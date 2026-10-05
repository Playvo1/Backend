<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\VenueFilterRequest;
use App\Models\Venue;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Http\Requests\Api\UpdateVenueRequest;
use App\Models\Image;
use Illuminate\Support\Facades\Storage;
use App\Models\User;
class VenueController extends Controller
{
    public function index(VenueFilterRequest $request): JsonResponse
    {
        $query = Venue::query()
            ->where('status', 'active')
            ->with([
                'city.country',
                'sports',
                'images',
                'timeSlots' => function ($q) use ($request) {

                    $q->where('status', 'available');

                    if ($request->filled('date')) {
                        $q->whereDate('slot_date', $request->date);
                    }

                    if ($request->filled('sport_id')) {
                        $q->where('sport_id', $request->sport_id);
                    }

                    if ($request->filled('hour')) {
                        $hour = Carbon::parse($request->hour)->format('H:i:s');

                        $q->where('start_time', '<=', $hour)
                            ->where('end_time', '>', $hour);
                    }
                },
            ]);

        if ($request->filled('country_id')) {
            $query->whereHas('city', function ($q) use ($request) {
                $q->where('country_id', $request->country_id);
            });
        }

        if ($request->filled('city_id')) {
            $query->where('city_id', $request->city_id);
        }

        if ($request->filled('sport_id')) {
            $query->whereHas('sports', function ($q) use ($request) {
                $q->where('sports.id', $request->sport_id);
            });
        }

        if ($request->filled('search')) {
            $search = $request->search;

            $query->where(function ($q) use ($search) {
                $q->where('name_ar', 'like', "%{$search}%")
                    ->orWhere('name_en', 'like', "%{$search}%");
            });
        }

        if ($request->filled('date')) {
            $query->whereHas('timeSlots', function ($q) use ($request) {
                $q->whereDate('slot_date', $request->date)
                    ->where('status', 'available');

                if ($request->filled('sport_id')) {
                    $q->where('sport_id', $request->sport_id);
                }

                if ($request->filled('hour')) {
                    $hour = Carbon::parse($request->hour)->format('H:i:s');

                    $q->where('start_time', '<=', $hour)
                        ->where('end_time', '>', $hour);
                }
            });
        }

        $venues = $query->paginate(10);
        return response()->json([
            'success' => true,
            'data' => $venues,
            'message' => 'Venues retrieved successfully',
            'errors' => null,
        ]);
    }

    public function show(int $id): JsonResponse
{
    $venue = Venue::query()
        ->where('status', 'active')
        ->with([
            'city.country',
            'sports',
            'images',
        ])
        ->find($id);

    if (!$venue) {
        return response()->json([
            'success' => false,
            'data' => null,
            'message' => 'Venue not found',
            'errors' => null,
        ], 404);
    }

    return response()->json([
        'success' => true,
        'data' => $venue,
        'message' => 'Venue retrieved successfully',
        'errors' => null,
    ]);
}
public function timeSlots(int $id, Request $request): JsonResponse
{
    $venue = Venue::query()
        ->where('status', 'active')
        ->find($id);

    if (!$venue) {
        return response()->json([
            'success' => false,
            'data' => null,
            'message' => 'Venue not found',
            'errors' => null,
        ], 404);
    }

    $request->validate([
        'date' => ['required', 'date'],
        'sport_id' => ['required', 'integer', 'exists:sports,id'],
    ]);

    $timeSlots = $venue->timeSlots()
        ->whereDate('slot_date', $request->date)
        ->where('sport_id', $request->sport_id)
        ->orderBy('start_time')
        ->get();

    return response()->json([
        'success' => true,
        'data' => $timeSlots,
        'message' => 'Time slots retrieved successfully',
        'errors' => null,
    ]);
}
public function update(UpdateVenueRequest $request,  $id): JsonResponse
{
    $venue = Venue::find($id);

    if (!$venue) {
        return response()->json([
            'success' => false,
            'data' => null,
            'message' => 'Venue not found.',
        ], 404);
    }

   $user = $request->user();

if (!$user) {
    return response()->json([
        'success' => false,
        'data' => null,
        'message' => 'Unauthenticated.',
        'errors' => null,
    ], 401);
}

if ((int) $venue->owner_id !== (int) $user->id) {
    return response()->json([
        'success' => false,
        'data' => null,
        'message' => 'You are not authorized to update this venue.',
        'errors' => null,
    ], 403);
}

    $venue->update($request->validated());

    return response()->json([
        'success' => true,
        'data' => $venue->fresh(),
        'message' => 'Venue updated successfully.',
    ]);
}
public function uploadImage(Request $request, int $id): JsonResponse
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

    $user = $request->user();

    if (!$user) {
        return response()->json([
            'success' => false,
            'data' => null,
            'message' => 'Unauthenticated.',
            'errors' => null,
        ], 401);
    }

    if ((int) $venue->owner_id !== (int) $user->id) {
        return response()->json([
            'success' => false,
            'data' => null,
            'message' => 'You are not authorized to manage this venue.',
            'errors' => null,
        ], 403);
    }

    $request->validate([
        'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        'sort_order' => ['nullable', 'integer', 'min:0'],
    ]);

    $path = $request->file('image')->store('venues', 'public');

    $image = $venue->images()->create([
        'image_url' => Storage::url($path),
        'sort_order' => $request->input(
            'sort_order',
            $venue->images()->max('sort_order') + 1
        ),
    ]);

    return response()->json([
        'success' => true,
        'data' => $image,
        'message' => 'Venue image uploaded successfully.',
        'errors' => null,
    ], 201);
}
}
