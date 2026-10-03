<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AdminVenueIndexRequest;
use App\Http\Requests\Api\CreateAdminVenueRequest;
use App\Http\Requests\Api\UpdateVenueStatusRequest;
use App\Http\Resources\VenueProfileResource;
use App\Models\AuditLog;
use App\Models\Venue;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Admin venue management (US-3.5): list, add a venue for an owner to complete, and
 * approve or remove it. Removing is a soft delete guarded against upcoming bookings,
 * so booking and rating history survives and the venue drops out of player search.
 * Every change is written to AUDIT_LOG.
 */
class AdminVenueController extends Controller
{
    public function index(AdminVenueIndexRequest $request): JsonResponse
    {
        $venues = Venue::withTrashed()
            ->with('sports')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->query('status')))
            ->orderBy('id')
            ->get()
            ->map(fn (Venue $venue) => $this->present($venue));

        return ApiResponse::send(true, 200, 'OK', $venues->all());
    }

    public function store(CreateAdminVenueRequest $request): JsonResponse
    {
        $venue = DB::transaction(function () use ($request) {
            $venue = Venue::create([...$request->safe()->except('sport_ids'), 'status' => 'inactive']);
            $venue->sports()->sync($request->validated('sport_ids') ?? []);

            AuditLog::record($request->user()->id, 'venue_created', 'VENUE', $venue->id);

            return $venue;
        });

        return ApiResponse::send(true, 201, 'Venue created, waiting for the owner to complete its profile', $this->present($venue->load('sports')));
    }

    public function updateStatus(UpdateVenueStatusRequest $request, Venue $venue): JsonResponse
    {
        return $request->validated('status') === 'active'
            ? $this->approve($request, $venue)
            : $this->remove($request, $venue);
    }

    private function approve(UpdateVenueStatusRequest $request, Venue $venue): JsonResponse
    {
        if (! $venue->isProfileComplete()) {
            return ApiResponse::send(false, 422, 'This venue cannot go live until its profile is complete.', null, [
                'status' => ['The owner must complete the address, area, map location and dimensions first.'],
            ]);
        }

        DB::transaction(function () use ($request, $venue) {
            $venue->restore();
            $venue->update(['status' => 'active']);

            AuditLog::record($request->user()->id, 'venue_status_change', 'VENUE', $venue->id);
        });

        return ApiResponse::send(true, 200, 'Venue approved', ['id' => $venue->id, 'status' => 'active']);
    }

    private function remove(UpdateVenueStatusRequest $request, Venue $venue): JsonResponse
    {
        if ($venue->hasUpcomingBookings()) {
            return ApiResponse::send(false, 409, "Resolve this venue's upcoming bookings before removing it.");
        }

        DB::transaction(function () use ($request, $venue) {
            $venue->update(['status' => 'inactive']);
            $venue->delete();

            AuditLog::record($request->user()->id, 'venue_status_change', 'VENUE', $venue->id);
        });

        return ApiResponse::send(true, 200, 'Venue removed', ['id' => $venue->id, 'status' => 'inactive']);
    }

    private function present(Venue $venue): array
    {
        return [
            ...(new VenueProfileResource($venue))->resolve(),
            'deleted_at' => $venue->deleted_at?->toIso8601ZuluString(),
        ];
    }
}
