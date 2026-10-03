<?php

namespace App\Http\Resources;

use App\Models\Venue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A venue's full profile as seen by its owner and by admins, using the ERD field names.
 *
 * @mixin Venue
 */
class VenueProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'owner_id' => $this->owner_id,
            'city_id' => $this->city_id,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'address_ar' => $this->address_ar,
            'address_en' => $this->address_en,
            'area_ar' => $this->area_ar,
            'area_en' => $this->area_en,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'length_m' => $this->length_m,
            'width_m' => $this->width_m,
            'avg_rating' => $this->avg_rating,
            'min_hourly_price' => $this->min_hourly_price,
            'status' => $this->status,
            'profile_complete' => $this->isProfileComplete(),
            'sport_ids' => $this->sports->pluck('id')->values()->all(),
        ];
    }
}
