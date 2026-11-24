<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class BillingService
{
    private const TZ = 'Africa/Lagos';

    public static function isPremium($user): bool
    {
        if ($user->role === 'admin') return true;
        return $user->premium_until && Carbon::parse($user->premium_until)->isFuture();
    }

    /** Exams the student started since midnight (Lagos time). */
    public static function usedToday($user): int
    {
        $startMs = (new \DateTimeImmutable('today', new \DateTimeZone(self::TZ)))->getTimestamp() * 1000;
        return DB::table('exams')->where('user_id', $user->id)->where('started_at', '>=', $startMs)->count();
    }

    public static function status($user): array
    {
        $limit = (int) config('billing.free_daily_limit');
        $used = self::usedToday($user);

        return [
            'premium' => self::isPremium($user),
            'isAdmin' => $user->role === 'admin',
            'premiumUntil' => $user->premium_until ? Carbon::parse($user->premium_until)->toIso8601String() : null,
            'freeLimit' => $limit,
            'usedToday' => $used,
            'remainingToday' => max(0, $limit - $used),
            'plans' => collect(config('billing.plans'))->map(fn ($p, $id) => [
                'id' => $id,
                'name' => $p['name'],
                'days' => $p['days'],
                'amount' => $p['amount'],
                'perMonth' => (int) round($p['amount'] / ($p['days'] / 30)),
            ])->values(),
        ];
    }

    /**
     * Asks Paystack whether the payment really succeeded, checks the amount, then grants access.
     * Safe to call many times (return page and webhook can both call it): access is granted once.
     */
    public static function fulfil(string $reference): array
    {
        $payment = DB::table('payments')->where('reference', $reference)->first();
        if (!$payment) return ['status' => 'unknown', 'message' => 'Payment not found.'];

        $owner = DB::table('users')->where('id', $payment->user_id)->first();
        if ($payment->status === 'success') {
            return ['status' => 'success', 'message' => 'Payment already confirmed.', 'premiumUntil' => $owner->premium_until];
        }

        $secret = config('billing.paystack_secret');
        if (!$secret) return ['status' => 'pending', 'message' => 'Payments are not configured on the server.'];

        $res = Http::withToken($secret)->acceptJson()->timeout(20)
            ->get('https://api.paystack.co/transaction/verify/' . rawurlencode($reference));
        if (!$res->successful()) {
            return ['status' => 'pending', 'message' => 'Could not reach Paystack. Please try again in a moment.'];
        }

        $d = $res->json('data') ?? [];
        if (($d['status'] ?? null) !== 'success') {
            return ['status' => $d['status'] ?? 'pending', 'message' => 'The payment was not completed.'];
        }
        if ((int) ($d['amount'] ?? 0) !== (int) $payment->amount_kobo || ($d['currency'] ?? '') !== 'NGN' || ($d['reference'] ?? '') !== $reference) {
            DB::table('payments')->where('id', $payment->id)->update(['status' => 'mismatch', 'updated_at' => now()]);
            return ['status' => 'failed', 'message' => 'The payment details did not match, so access was not granted.'];
        }

        DB::transaction(function () use ($payment) {
            $fresh = DB::table('payments')->where('id', $payment->id)->lockForUpdate()->first();
            if ($fresh->status === 'success') return;

            $days = (int) config("billing.plans.{$payment->plan}.days", 0);
            $user = DB::table('users')->where('id', $payment->user_id)->lockForUpdate()->first();
            $from = ($user->premium_until && Carbon::parse($user->premium_until)->isFuture()) ? Carbon::parse($user->premium_until) : now();

            DB::table('users')->where('id', $user->id)->update(['premium_until' => $from->copy()->addDays($days)]);
            DB::table('payments')->where('id', $payment->id)->update(['status' => 'success', 'paid_at' => now(), 'updated_at' => now()]);
        });

        $user = DB::table('users')->where('id', $payment->user_id)->first();
        return ['status' => 'success', 'message' => 'Payment confirmed. Premium is now active.', 'premiumUntil' => $user->premium_until];
    }
}
