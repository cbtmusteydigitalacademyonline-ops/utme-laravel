<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\SignupGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GoogleAuthController extends Controller
{
    // POST /api/auth/google  { credential: "<Google ID token>" }
    public function login(Request $request): JsonResponse
    {
        if ($msg = SignupGuard::throttle('google:' . $request->ip(), 30, 3600)) {   // 30 Google sign-ins an hour per address
            return response()->json(['error' => $msg], 429);
        }

        $clientId = (string) config('google.client_id');
        $credential = (string) $request->input('credential');
        if ($clientId === '' || $credential === '' || strlen($credential) > 4096) {
            return response()->json(['error' => 'Google sign-in failed. Please try again.'], 400);
        }

        // Ask Google to check the token's signature and expiry
        try {
            $res = Http::timeout(10)->get('https://oauth2.googleapis.com/tokeninfo', ['id_token' => $credential]);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Could not reach Google. Please try again.'], 503);
        }
        if (!$res->ok()) {
            return response()->json(['error' => 'Google sign-in failed. Please try again.'], 401);
        }

        $g = (array) $res->json();
        $email = strtolower(trim((string) ($g['email'] ?? '')));
        $valid =
            in_array($g['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true)   // issued by Google
            && hash_equals($clientId, (string) ($g['aud'] ?? ''))                                       // issued for this app
            && (int) ($g['exp'] ?? 0) > time()                                                          // not expired
            && filter_var($g['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN)                       // Google confirmed the email
            && filter_var($email, FILTER_VALIDATE_EMAIL);

        if (!$valid) {
            return response()->json(['error' => 'Google sign-in failed. Please try again.'], 401);
        }

        $user = User::where('email', $email)->first();

        if (!$user) {
            $name = trim((string) ($g['name'] ?? '')) ?: Str::before($email, '@');
            // random password nobody knows: Google accounts log in with Google, or use "Forgot password" to set one
            $user = User::create(['name' => Str::limit($name, 100, ''), 'email' => $email, 'password' => Str::random(40)]);
        }

        // Google has proved they own this address
        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return response()->json([
            'token' => $user->createToken('app')->plainTextToken,
            'user' => $user->toPublic(),
        ]);
    }
}
