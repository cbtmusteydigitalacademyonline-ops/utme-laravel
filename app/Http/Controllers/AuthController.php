<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
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
        $user = User::where('email', strtolower((string) $request->input('email')))->first();

        if (!$user || !Hash::check((string) $request->input('password'), $user->password)) {
            return response()->json(['error' => 'Wrong email or password'], 401);
        }

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
