<?php

namespace App\Http\Controllers;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AuthCodeController extends Controller
{
    private function issue(string $email, string $purpose): string
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        DB::table('auth_codes')->where('email', $email)->where('purpose', $purpose)->delete();
        DB::table('auth_codes')->insert([
            'email' => $email,
            'purpose' => $purpose,
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $code;
    }

    /** True once, for a correct, unexpired code (5 wrong tries burns the code). */
    private function check(string $email, string $purpose, string $code): bool
    {
        $row = DB::table('auth_codes')->where('email', $email)->where('purpose', $purpose)->first();
        if (!$row || $row->attempts >= 5 || Carbon::parse($row->expires_at)->isPast()) return false;

        DB::table('auth_codes')->where('id', $row->id)->increment('attempts');
        if (!Hash::check(trim($code), $row->code_hash)) return false;

        DB::table('auth_codes')->where('id', $row->id)->delete();
        return true;
    }

    private function send(string $email, string $subject, string $line, string $code): void
    {
        try {
            Mail::raw("{$line}\n\nYour code: {$code}\n\nIt expires in 30 minutes. If you did not ask for this, ignore this email.", function ($m) use ($email, $subject) {
                $m->to($email)->subject($subject);
            });
        } catch (\Throwable $e) {
            Log::error('Could not send auth code email: ' . $e->getMessage());
        }
    }

    // POST /api/auth/forgot  { email }. Always answers ok, so nobody can probe which emails exist.
    public function forgot(Request $request): JsonResponse
    {
        $email = strtolower(trim((string) $request->input('email')));
        if (User::where('email', $email)->exists()) {
            $this->send($email, 'Your password reset code', 'Use this code to reset your UTME CBT password.', $this->issue($email, 'reset'));
        }

        return response()->json(['ok' => true]);
    }

    // POST /api/auth/reset  { email, code, password }
    public function reset(Request $request): JsonResponse
    {
        $email = strtolower(trim((string) $request->input('email')));
        $password = (string) $request->input('password');
        if (strlen($password) < 6) return response()->json(['error' => 'Password must be at least 6 characters'], 400);

        $user = User::where('email', $email)->first();
        if (!$user || !$this->check($email, 'reset', (string) $request->input('code'))) {
            return response()->json(['error' => 'That code is wrong or has expired'], 400);
        }

        $user->password = $password;     // hashed by the model cast
        $user->save();
        $user->tokens()->delete();       // log out everywhere

        return response()->json(['ok' => true]);
    }

    // POST /api/auth/verify/send  (logged in)
    public function sendVerify(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->email_verified_at) return response()->json(['ok' => true, 'already' => true]);

        $this->send($user->email, 'Verify your email', 'Use this code to verify your email for UTME CBT.', $this->issue($user->email, 'verify'));

        return response()->json(['ok' => true]);
    }

    // POST /api/auth/verify  { code }  (logged in)
    public function verify(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user->email_verified_at) {
            if (!$this->check($user->email, 'verify', (string) $request->input('code'))) {
                return response()->json(['error' => 'That code is wrong or has expired'], 400);
            }
            $user->email_verified_at = now();
            $user->save();
        }

        return response()->json($user->fresh()->toPublic());
    }
}
