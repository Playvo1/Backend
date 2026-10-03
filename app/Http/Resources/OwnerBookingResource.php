<?php

namespace App\Http\Resources;

use App\Models\Booking;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A booking row on the venue owner's dashboard: who booked, which slot, price and status.
 *
 * @mixin Booking
 */
class OwnerBookingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $slot = $this->timeSlot;

        return [
            'id' => $this->id,
            'venue_id' => $slot->venue_id,
            'venue_name_ar' => $slot->venue?->name_ar,
            'venue_name_en' => $slot->venue?->name_en,
            'sport_id' => $slot->sport_id,
            'slot_date' => Carbon::parse($slot->slot_date)->toDateString(),
            'start_time' => Carbon::parse($slot->start_time)->format('H:i'),
            'end_time' => Carbon::parse($slot->end_time)->format('H:i'),
            'captain_name' => $this->captain_name,
            'captain_role' => $this->captain_role,
            'captain_phone' => $this->captain_phone,
            'total_price' => (float) $this->total_price,
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
