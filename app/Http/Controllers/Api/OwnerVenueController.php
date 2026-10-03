<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdateOwnerVenueRequest;
use App\Http\Requests\Api\UploadVenueImageRequest;
use App\Http\Resources\VenueProfileResource;
use App\Models\Venue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * Venue-owner dashboard endpoints for the owner's own venues: profile (US-3.1) and photos (US-3.2).
 * Routes are restricted to venue owners; VenuePolicy limits each call to the caller's venues.
 */
class OwnerVenueController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $venues = $request->user()->ownedVenues()
            ->with('sports')
            ->orderBy('id')
            ->get();

        return ApiResponse::send(true, 200, 'OK', VenueProfileResource::collection($venues)->resolve());
    }

    public function update(UpdateOwnerVenueRequest $request, Venue $venue): JsonResponse
    {
        Gate::authorize('manage', $venue);

        $attributes = $request->safe()->except('sport_ids');

        DB::transaction(function () use ($venue, $attributes, $request) {
            $venue->update($attributes);

            if ($request->has('sport_ids')) {
                $venue->sports()->sync($request->validated('sport_ids'));
            }
        });

        $venue->load('sports');

        return ApiResponse::send(true, 200, 'Venue updated', (new VenueProfileResource($venue))->resolve());
    }

    public function uploadImage(UploadVenueImageRequest $request, Venue $venue): JsonResponse
    {
        Gate::authorize('manage', $venue);

        $path = $request->file('file')->store("venues/{$venue->id}", 'public');

        $image = $venue->images()->create([
            'image_url' => Storage::disk('public')->url($path),
            'sort_order' => $request->validated('sort_order') ?? $this->nextSortOrder($venue),
        ]);

        return ApiResponse::send(true, 201, 'Image uploaded', [
            'id' => $image->id,
            'image_url' => $image->image_url,
            'sort_order' => $image->sort_order,
        ]);
    }

    private function nextSortOrder(Venue $venue): int
    {
        return ((int) $venue->images()->reorder()->max('sort_order')) + 1;
    }
}
