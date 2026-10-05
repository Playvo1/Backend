<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AdminVenueRequest;
use App\Models\Venue;
use Illuminate\Http\JsonResponse;
use App\Models\User;
use App\Models\Booking;
use Carbon\Carbon;
use Illuminate\Http\Request;
class AdminVenueController extends Controller
{
    public function store(AdminVenueRequest $request): JsonResponse
    {
        $venue = Venue::create([
              'owner_id' => $request->owner_id,
            'city_id' => $request->city_id,

            'name_ar' => $request->name_ar,
            'name_en' => $request->name_en,

            'address_ar' => $request->address_ar,
            'address_en' => $request->address_en,

            'area_ar' => $request->area_ar,
            'area_en' => $request->area_en,

            'latitude' => $request->latitude,
            'longitude' => $request->longitude,

            'length_m' => $request->length_m,
            'width_m' => $request->width_m,

            'min_hourly_price' => $request->min_hourly_price,

            'status' => 'active',
        ]);
        $owner = User::findOrFail($request->owner_id);

        if (! $owner->hasRole('venue_owner')) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'The selected user is not a venue owner.',
                'errors' => null,
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $venue,
            'message' => 'Venue created successfully.',
            'errors' => null,
        ], 201);
    }
  public function updateStatus(Request $request, int $id): JsonResponse
{
    $request->validate([
        'status' => ['required', 'in:active,inactive'],
    ]);

    $venue = Venue::findOrFail($id);

    $venue->status = $request->status;
    $venue->save();

    return response()->json([
        'success' => true,
        'data' => [
            'id' => $venue->id,
            'status' => $venue->status,
        ],
        'message' => 'Venue status updated successfully.',
        'errors' => null,
    ]);
}
}
