<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TimeSlot;
use App\Models\Venue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TimeSlotController extends Controller
{
    public function store(Request $request, int $id): JsonResponse
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

        $validated = $request->validate([
            'sport_id' => ['required', 'integer', 'exists:sports,id'],
            'slot_date' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'hourly_price' => ['required', 'numeric', 'min:0'],
        ]);
        $exists = $venue->timeSlots()
            ->where('sport_id', $validated['sport_id'])
            ->whereDate('slot_date', $validated['slot_date'])
            ->where('start_time', $validated['start_time'])
            ->where('end_time', $validated['end_time'])
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'This time slot already exists.',
                'errors' => null,
            ], 409);
        }

        $timeSlot = $venue->timeSlots()->create([
            'sport_id' => $validated['sport_id'],
            'slot_date' => $validated['slot_date'],
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'],
            'hourly_price' => $validated['hourly_price'],
            'status' => 'available',
        ]);

        return response()->json([
            'success' => true,
            'data' => $timeSlot,
            'message' => 'Time slot created successfully.',
            'errors' => null,
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $timeSlot = TimeSlot::with('venue')->find($id);

        if (!$timeSlot) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Time slot not found.',
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

        if ((int) $timeSlot->venue->owner_id !== (int) $user->id) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'You are not authorized to manage this time slot.',
                'errors' => null,
            ], 403);
        }

        if ($timeSlot->booking()->exists()) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'This slot already has a booking and cannot be changed.',
                'errors' => null,
            ], 409);
        }

        $validated = $request->validate([
            'sport_id' => ['sometimes', 'integer', 'exists:sports,id'],
            'slot_date' => ['sometimes', 'date'],
            'start_time' => ['sometimes', 'date_format:H:i'],
            'end_time' => ['sometimes', 'date_format:H:i'],
            'hourly_price' => ['sometimes', 'numeric', 'min:0'],
        ]);

        $timeSlot->update($validated);

        return response()->json([
            'success' => true,
            'data' => $timeSlot->fresh(),
            'message' => 'Time slot updated successfully.',
            'errors' => null,
        ]);
    }
}
