<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class ConfigController extends Controller
{
    // GET /api/config : public settings the app needs before login
    public function show(): JsonResponse
    {
        return response()->json([
            // only offered when the server can also check the answer
            'turnstileSiteKey' => config('security.turnstile_secret') ? config('security.turnstile_site_key') : null,
        ]);
    }
}
