<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Favorite;
use App\Models\Venue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FavoriteController extends Controller
{
    public function toggle(Request $request, int $id): JsonResponse
    {
        $venue = Venue::find($id);

        if (!$venue) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Venue not found.',
                'errors' => null,
            ], 404);
        }

        $favorite = Favorite::where('user_id', $request->user()->id)
            ->where('venue_id', $venue->id)
            ->first();

        if ($favorite) {
            $favorite->delete();

            return response()->json([
                'success' => true,
                'data' => [
                    'venue_id' => $venue->id,
                    'is_favorite' => false,
                ],
                'message' => 'Venue removed from favorites.',
                'errors' => null,
            ]);
        }

        $favorite = Favorite::create([
            'user_id' => $request->user()->id,
            'venue_id' => $venue->id,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'favorite_id' => $favorite->id,
                'venue_id' => $venue->id,
                'is_favorite' => true,
            ],
            'message' => 'Venue added to favorites.',
            'errors' => null,
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $favorites = Favorite::with('venue')
            ->where('user_id', $request->user()->id)
            ->latest()
            ->paginate(10);

        return response()->json([
            'success' => true,
            'data' => $favorites,
            'message' => 'Favorites retrieved successfully.',
            'errors' => null,
        ]);
    }
}
