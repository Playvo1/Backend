<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\VenueFilterRequest;
use App\Models\Venue;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;
use Illuminate\Http\Request;
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
}
