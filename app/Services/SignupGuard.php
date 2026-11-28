<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class SignupGuard
{
    public static function isDisposable(string $email, array $domains): bool
    {
        $at = strrpos($email, '@');
        if ($at === false) return false;

        $domain = strtolower(trim(substr($email, $at + 1)));
        foreach ($domains as $d) {
            if ($domain === $d || str_ends_with($domain, '.' . $d)) return true;
        }
        return false;
    }

    /** Honeypot, throwaway email and CAPTCHA checks. Returns an error message, or null when all is fine. */
    public static function check(Request $request, bool $isSignup = true): ?string
    {
        // a hidden field that real people never see or fill in
        if (trim((string) $request->input('website')) !== '') {
            return 'Could not complete this request.';
        }

        if ($isSignup && self::isDisposable(strtolower((string) $request->input('email')), config('security.disposable_domains', []))) {
            return 'Please use a real email address that you can open.';
        }

        return self::captcha($request);
    }

    private static function captcha(Request $request): ?string
    {
        $secret = config('security.turnstile_secret');
        if (!$secret) return null;                       // CAPTCHA not configured

        $token = (string) $request->input('captcha');
        if ($token === '') return 'Please complete the security check.';

        try {
            $res = Http::asForm()->timeout(10)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'secret' => $secret, 'response' => $token, 'remoteip' => $request->ip(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Turnstile unreachable: ' . $e->getMessage());
            return null;                                 // Cloudflare is down: do not lock real students out
        }
        if (!$res->successful()) {
            Log::warning('Turnstile answered ' . $res->status());
            return null;
        }

        return $res->json('success') === true ? null : 'The security check failed. Please try again.';
    }

    /** Counts this attempt. Returns a message once the limit is passed. */
    public static function throttle(string $key, int $max, int $decaySeconds): ?string
    {
        if (RateLimiter::tooManyAttempts($key, $max)) return self::wait($key);
        RateLimiter::hit($key, $decaySeconds);
        return null;
    }

    // for logins: only wrong passwords count against the limit
    public static function lockedOut(string $key, int $max): ?string
    {
        return RateLimiter::tooManyAttempts($key, $max) ? self::wait($key) : null;
    }

    public static function failed(string $key, int $decaySeconds): void
    {
        RateLimiter::hit($key, $decaySeconds);
    }

    public static function cleared(string $key): void
    {
        RateLimiter::clear($key);
    }

    private static function wait(string $key): string
    {
        $mins = max(1, (int) ceil(RateLimiter::availableIn($key) / 60));
        return "Too many attempts. Please try again in {$mins} minute" . ($mins === 1 ? '' : 's') . '.';
    }
}
