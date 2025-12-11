<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    /**
     * Get the authenticated user's profile with balances.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user()->load(['assets', 'feeTier']);

        return response()->json([
            'user' => new UserResource($user),
        ]);
    }
}
