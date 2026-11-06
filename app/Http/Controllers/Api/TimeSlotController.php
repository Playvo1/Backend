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
            'end_time' => ['required', 'date_format:H:i'],
            'hourly_price' => ['required', 'numeric', 'min:0', 'max:10000', 'decimal:0,2'],
        ]);

        if ($error = $this->invalidRangeResponse($validated['start_time'], $validated['end_time'])) {
            return $error;
        }

        $sportBelongsToVenue = $venue->sports()
            ->where('sports.id', $validated['sport_id'])
            ->exists();

        if (!$sportBelongsToVenue) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'This sport is not associated with this venue.',
                'errors' => [
                    'sport_id' => [
                        'The selected sport is not associated with this venue.',
                    ],
                ],
            ], 422);
        }

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

        if (
            !$timeSlot->venue ||
            (int) $timeSlot->venue->owner_id !== (int) $user->id
        ) {
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
            'hourly_price' => ['sometimes', 'numeric', 'min:0', 'max:10000', 'decimal:0,2'],
        ]);

        // Only when a time is sent, checked against the resulting times so changing one side
        // is covered too; existing slots of another length can still get price/sport updates.
        if (array_key_exists('start_time', $validated) || array_key_exists('end_time', $validated)) {
            $error = $this->invalidRangeResponse(
                $validated['start_time'] ?? (string) $timeSlot->start_time,
                $validated['end_time'] ?? (string) $timeSlot->end_time
            );

            if ($error) {
                return $error;
            }
        }

        if (isset($validated['sport_id'])) {
            $sportBelongsToVenue = $timeSlot->venue->sports()
                ->where('sports.id', $validated['sport_id'])
                ->exists();

            if (!$sportBelongsToVenue) {
                return response()->json([
                    'success' => false,
                    'data' => null,
                    'message' => 'This sport is not associated with this venue.',
                    'errors' => [
                        'sport_id' => [
                            'The selected sport is not associated with this venue.',
                        ],
                    ],
                ], 422);
            }
        }

        $timeSlot->update($validated);

        return response()->json([
            'success' => true,
            'data' => $timeSlot->fresh(),
            'message' => 'Time slot updated successfully.',
            'errors' => null,
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
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

        if (
            !$timeSlot->venue ||
            (int) $timeSlot->venue->owner_id !== (int) $user->id
        ) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'You are not authorized to delete this time slot.',
                'errors' => null,
            ], 403);
        }

        if ($timeSlot->booking()->exists()) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Cannot delete a time slot that has booking history.',
                'errors' => null,
            ], 409);
        }

        $timeSlot->delete();

        return response()->json([
            'success' => true,
            'data' => null,
            'message' => 'Time slot deleted successfully.',
            'errors' => null,
        ]);
    }

    public function ownerTimeSlots(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Unauthenticated.',
                'errors' => null,
            ], 401);
        }

        $venue = Venue::where('owner_id', $user->id)->first();

        if (!$venue) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'You do not have a venue.',
                'errors' => null,
            ], 404);
        }

        $query = TimeSlot::where('venue_id', $venue->id);

        if ($request->filled('date')) {
            $validated = $request->validate([
                'date' => ['date_format:Y-m-d'],
            ]);

            $query->whereDate('slot_date', $validated['date']);
        }

        if ($request->filled('status')) {
            $validated = $request->validate([
                'status' => ['in:available,booked,blocked'],
            ]);

            $query->where('status', $validated['status']);
        }

        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $timeSlots = $query
            ->orderBy('slot_date')
            ->orderBy('start_time')
            ->paginate($validated['per_page'] ?? 15);

        return response()->json([
            'success' => true,
            'data' => $timeSlots,
            'message' => 'Owner time slots retrieved successfully.',
            'errors' => null,
        ]);
    }

    /**
     * A 422 response unless the slot lasts exactly TimeSlot::DURATION_MINUTES
     * (00:00 as an end is midnight, so 23:00–00:00 is valid); null when the range is valid.
     */
    private function invalidRangeResponse(string $startTime, string $endTime): ?JsonResponse
    {
        $slot = new TimeSlot(['start_time' => $startTime, 'end_time' => $endTime]);

        if ($slot->durationInMinutes() === TimeSlot::DURATION_MINUTES) {
            return null;
        }

        return response()->json([
            'success' => false,
            'data' => null,
            'message' => 'A time slot must last exactly one hour.',
            'errors' => [
                'end_time' => [
                    'A time slot must last exactly one hour.',
                ],
            ],
        ], 422);
    }
}
