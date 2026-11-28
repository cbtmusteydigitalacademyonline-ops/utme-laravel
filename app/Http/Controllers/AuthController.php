<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\SignupGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        if ($msg = SignupGuard::throttle('signup:' . $request->ip(), 15, 3600)) {   // 15 sign-ups an hour per address
            return response()->json(['error' => $msg], 429);
        }
        if ($msg = SignupGuard::check($request)) {
            return response()->json(['error' => $msg], 422);
        }

        $name = trim((string) $request->input('name'));
        $email = strtolower(trim((string) $request->input('email')));
        $password = (string) $request->input('password');

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 6) {
            return response()->json(['error' => 'Name, a valid email and a 6+ character password are required'], 400);
        }
        if (User::where('email', $email)->exists()) {
            return response()->json(['error' => 'Email already registered'], 409);
        }

        $user = User::create(['name' => $name, 'email' => $email, 'password' => $password]);

        return response()->json([
            'token' => $user->createToken('app')->plainTextToken,
            'user' => $user->toPublic(),
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $key = 'login:' . $request->ip() . '|' . strtolower((string) $request->input('email'));
        if ($msg = SignupGuard::lockedOut($key, 8)) {                                   // 8 wrong passwords, then a 5 minute pause
            return response()->json(['error' => $msg], 429);
        }

        $user = User::where('email', strtolower((string) $request->input('email')))->first();

        if (!$user || !Hash::check((string) $request->input('password'), $user->password)) {
            SignupGuard::failed($key, 300);
            return response()->json(['error' => 'Wrong email or password'], 401);
        }

        SignupGuard::cleared($key);

        return response()->json([
            'token' => $user->createToken('app')->plainTextToken,
            'user' => $user->toPublic(),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($request->user()->toPublic());
    }
}
