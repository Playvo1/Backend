<?php

namespace App\Http\Resources;

use App\Models\Booking;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A booking's confirmation details (booking ID, venue, date, time slot), shown in the
 * admin receipt queue and returned once a receipt is verified (US-3.6).
 *
 * @mixin Booking
 */
class BookingConfirmationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $slot = $this->timeSlot;

        return [
            'id' => $this->id,
            'status' => $this->status,
            'captain_name' => $this->captain_name,
            'captain_phone' => $this->captain_phone,
            'total_price' => (float) $this->total_price,
            'venue_id' => $slot->venue_id,
            'venue_name_ar' => $slot->venue?->name_ar,
            'venue_name_en' => $slot->venue?->name_en,
            'slot_date' => Carbon::parse($slot->slot_date)->toDateString(),
            'start_time' => Carbon::parse($slot->start_time)->format('H:i'),
            'end_time' => Carbon::parse($slot->end_time)->format('H:i'),
        ];
    }
}
