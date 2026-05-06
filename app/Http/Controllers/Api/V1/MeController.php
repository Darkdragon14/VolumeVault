<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        return response()->json([
            'data' => [
                'user' => $request->user()->only(['id', 'name', 'email', 'role', 'locale', 'theme']),
                'token' => $token ? [
                    'id' => $token->id,
                    'name' => $token->name,
                    'abilities' => $token->abilities,
                    'last_used_at' => $token->last_used_at,
                    'expires_at' => $token->expires_at,
                ] : null,
            ],
        ]);
    }
}
