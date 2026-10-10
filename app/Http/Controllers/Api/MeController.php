<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    /**
     * Who the token belongs to, and the timezone their dates and financial years are in.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'name' => $user->name,
                'email' => $user->email,
                'timezone' => $user->timezone,
                'token' => $user->currentAccessToken()->name,
            ],
        ]);
    }
}
