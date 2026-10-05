<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceTokenController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'fcm_token' => ['required', 'string', 'max:2048'],
            'platform' => ['required', 'string', 'in:android,ios'],
        ]);

        $deviceToken = DeviceToken::updateOrCreate(
            ['fcm_token' => $validated['fcm_token']],
            [
                'user_id' => $request->user()->id,
                'platform' => $validated['platform'],
            ]
        );

        return response()->json([
            'success' => true,
            'data' => $deviceToken,
            'message' => 'Device token registered successfully.',
            'errors' => null,
        ]);
    }
}
