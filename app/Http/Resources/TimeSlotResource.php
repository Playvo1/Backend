<?php

namespace App\Http\Resources;

use App\Models\TimeSlot;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A time slot in the API contract format: ISO date and 24h HH:mm times.
 *
 * @mixin TimeSlot
 */
class TimeSlotResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'venue_id' => $this->venue_id,
            'sport_id' => $this->sport_id,
            'slot_date' => Carbon::parse($this->slot_date)->toDateString(),
            'start_time' => Carbon::parse($this->start_time)->format('H:i'),
            'end_time' => Carbon::parse($this->end_time)->format('H:i'),
            'hourly_price' => $this->hourly_price,
            'status' => $this->status,
        ];
    }
}
