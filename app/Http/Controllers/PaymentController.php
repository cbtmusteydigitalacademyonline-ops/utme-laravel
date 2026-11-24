<?php

namespace App\Http\Controllers;

use App\Services\BillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    // GET /api/billing/status
    public function status(Request $request): JsonResponse
    {
        return response()->json(BillingService::status($request->user()));
    }

    // POST /api/payments/initialize  { plan }  ->  { authorizationUrl, reference }
    public function initialize(Request $request): JsonResponse
    {
        $planId = (string) $request->input('plan');
        $plan = config("billing.plans.{$planId}");
        if (!$plan) return response()->json(['error' => 'Choose a valid plan'], 400);

        $secret = config('billing.paystack_secret');
        if (!$secret) return response()->json(['error' => 'Payments are not set up yet. Please try again later.'], 503);

        $user = $request->user();
        $reference = 'utme_' . Str::lower(Str::random(24));
        $kobo = $plan['amount'] * 100;

        DB::table('payments')->insert([
            'user_id' => $user->id, 'reference' => $reference, 'plan' => $planId, 'amount_kobo' => $kobo,
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $res = Http::withToken($secret)->acceptJson()->timeout(20)->post('https://api.paystack.co/transaction/initialize', [
            'email' => $user->email,
            'amount' => $kobo,
            'currency' => 'NGN',
            'reference' => $reference,
            'callback_url' => rtrim(config('billing.frontend_url'), '/') . '/?payref=' . $reference,
            'metadata' => ['user_id' => $user->id, 'plan' => $planId],
        ]);

        $url = $res->json('data.authorization_url');
        if (!$res->successful() || !$url) {
            DB::table('payments')->where('reference', $reference)->update(['status' => 'failed', 'updated_at' => now()]);
            return response()->json(['error' => 'Could not start the payment. Please try again.'], 502);
        }

        return response()->json(['authorizationUrl' => $url, 'reference' => $reference]);
    }

    // POST /api/payments/verify  { reference }   (called when Paystack sends the student back)
    public function verify(Request $request): JsonResponse
    {
        $reference = (string) $request->input('reference');
        $mine = DB::table('payments')->where('reference', $reference)->where('user_id', $request->user()->id)->exists();
        if (!$mine) return response()->json(['error' => 'Payment not found'], 404);

        return response()->json(BillingService::fulfil($reference));
    }

    // POST /api/paystack/webhook : Paystack tells us about payments even if the student closed the tab
    public function webhook(Request $request): JsonResponse
    {
        $secret = (string) config('billing.paystack_secret');
        $signature = (string) $request->header('x-paystack-signature');
        $expected = hash_hmac('sha512', $request->getContent(), $secret);

        if ($secret === '' || !hash_equals($expected, $signature)) {
            return response()->json(['error' => 'Bad signature'], 401);
        }

        if ($request->input('event') === 'charge.success') {
            $reference = (string) $request->input('data.reference');
            if ($reference !== '') BillingService::fulfil($reference);
        }

        return response()->json(['ok' => true]);
    }

    // GET /api/admin/payments
    public function adminIndex(Request $request): JsonResponse
    {
        if ($request->user()->role !== 'admin') return response()->json(['error' => 'Admins only'], 403);

        $ok = DB::table('payments')->where('status', 'success');
        $rows = DB::table('payments as p')->join('users as u', 'u.id', '=', 'p.user_id')
            ->orderByDesc('p.id')->limit(50)
            ->get(['p.id', 'u.name', 'u.email', 'p.plan', 'p.amount_kobo', 'p.status', 'p.paid_at', 'p.created_at'])
            ->map(fn ($r) => [
                'id' => $r->id, 'name' => $r->name, 'email' => $r->email, 'plan' => $r->plan,
                'amount' => $r->amount_kobo / 100, 'status' => $r->status, 'date' => $r->paid_at ?? $r->created_at,
            ]);

        return response()->json([
            'revenue' => (float) ($ok->sum('amount_kobo') / 100),
            'paid' => (clone $ok)->count(),
            'premiumNow' => DB::table('users')->where('premium_until', '>', now())->count(),
            'recent' => $rows->values(),
        ]);
    }
}
