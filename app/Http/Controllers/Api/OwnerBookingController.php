<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\OwnerBookingIndexRequest;
use App\Http\Resources\OwnerBookingResource;
use App\Models\Booking;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * The venue owner's bookings overview (US-3.4): bookings across the owner's venues,
 * filterable by venue and status, plus this week's booking count and income.
 * Everything is computed per request, so new bookings and receipt verifications
 * show up immediately.
 */
class OwnerBookingController extends Controller
{
    public function index(OwnerBookingIndexRequest $request): JsonResponse
    {
        $bookings = $this->ownerBookings($request)
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', $request->query('status')))
            ->with('timeSlot.venue')
            ->latest()
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 20));

        $data = ApiResponse::paginated($bookings, OwnerBookingResource::collection($bookings->items())->resolve());
        $data['weekly_stats'] = $this->weeklyStats($request);

        return ApiResponse::send(true, 200, 'OK', $data);
    }

    /**
     * Bookings submitted this week (Monday to Sunday): every non-cancelled booking counts
     * towards demand, while income only counts confirmed (payment-verified) bookings.
     */
    private function weeklyStats(OwnerBookingIndexRequest $request): array
    {
        $weekStart = now()->startOfWeek();
        $weekEnd = now()->endOfWeek();

        $thisWeek = fn () => $this->ownerBookings($request)->whereBetween('created_at', [$weekStart, $weekEnd]);

        return [
            'week_start' => $weekStart->toDateString(),
            'week_end' => $weekEnd->toDateString(),
            'total_bookings' => $thisWeek()->whereIn('status', ['pending_payment', 'confirmed'])->count(),
            'confirmed_bookings' => $thisWeek()->where('status', 'confirmed')->count(),
            'income' => (float) $thisWeek()->where('status', 'confirmed')->sum('total_price'),
        ];
    }

    private function ownerBookings(OwnerBookingIndexRequest $request): Builder
    {
        return Booking::query()
            ->whereHas('timeSlot.venue', fn (Builder $venue) => $venue->where('owner_id', $request->user()->id))
            ->when($request->filled('venue_id'), fn (Builder $query) => $query->whereHas(
                'timeSlot',
                fn (Builder $slot) => $slot->where('venue_id', $request->integer('venue_id')),
            ));
    }
}
