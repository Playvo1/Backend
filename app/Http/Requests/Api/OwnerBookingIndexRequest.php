<?php

namespace App\Http\Requests\Api;

use App\Models\Venue;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Filters for the venue owner's bookings list (US-3.4). Filtering by a venue the
 * caller doesn't own is refused rather than silently returning an empty list.
 */
class OwnerBookingIndexRequest extends FormRequest
{
    public function authorize(): Response
    {
        $venue = $this->filled('venue_id') ? Venue::find($this->query('venue_id')) : null;

        return $venue ? Gate::inspect('manage', $venue) : Response::allow();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'venue_id' => 'nullable|integer|exists:venues,id',
            'status' => 'nullable|in:pending_payment,confirmed,cancelled',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ];
    }
}
