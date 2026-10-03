<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreTimeSlotRequest;
use App\Http\Requests\Api\UpdateTimeSlotRequest;
use App\Http\Resources\TimeSlotResource;
use App\Models\TimeSlot;
use App\Models\Venue;
use App\Services\Owner\TimeSlotScheduler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Lets venue owners create, edit and remove the bookable time slots of their own
 * venues (US-3.3). A slot that is reserved or booked can't be changed, so a player's
 * booking never silently moves or changes price.
 */
class OwnerTimeSlotController extends Controller
{
    public function __construct(private readonly TimeSlotScheduler $scheduler) {}

    public function store(StoreTimeSlotRequest $request, Venue $venue): JsonResponse
    {
        $slot = $this->scheduler->create($venue, [
            'sport_id' => $request->integer('sport_id'),
            'slot_date' => $request->validated('slot_date'),
            'start_time' => $request->validated('start_time').':00',
            'end_time' => $request->validated('end_time').':00',
            'hourly_price' => $request->validated('hourly_price'),
        ]);

        return ApiResponse::send(true, 201, 'Time slot created', (new TimeSlotResource($slot))->resolve());
    }

    /**
     * Held or booked slots are refused with 409 by UpdateTimeSlotRequest, before validation.
     */
    public function update(UpdateTimeSlotRequest $request, TimeSlot $timeSlot): JsonResponse
    {
        [$date, $start, $end] = $request->resultingSchedule();

        $this->scheduler->update($timeSlot, [
            'slot_date' => $date,
            'start_time' => $start,
            'end_time' => $end,
            'hourly_price' => $request->validated('hourly_price', $timeSlot->hourly_price),
        ]);

        return ApiResponse::send(true, 200, 'Time slot updated', (new TimeSlotResource($timeSlot))->resolve());
    }

    /**
     * 204 with no body on success (Guidelines 2.2).
     */
    public function destroy(TimeSlot $timeSlot): JsonResponse|Response
    {
        Gate::authorize('manage', $timeSlot->venue ?? throw new NotFoundHttpException);

        if ($timeSlot->hasActiveBooking()) {
            return ApiResponse::send(false, 409, UpdateTimeSlotRequest::BOOKED_MESSAGE);
        }

        // A cancelled booking still references the slot, and booking history must be kept.
        if ($timeSlot->bookings()->exists()) {
            return ApiResponse::send(false, 409, 'This slot has booking history and cannot be removed.');
        }

        $timeSlot->delete();

        return response()->noContent();
    }
}
