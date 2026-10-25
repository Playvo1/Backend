<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;
use App\Http\Requests\Api\VenueFilterRequest;
use App\Models\Venue;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Http\Requests\Api\UpdateVenueRequest;
use App\Models\Image;
use Illuminate\Support\Facades\Storage;
use App\Models\User;
use App\Models\VenueRating;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VenueController extends Controller
{
    public function index(VenueFilterRequest $request): JsonResponse
    {
        $query = Venue::query()
            ->where('status', 'active')
            ->with([
                'city.country',
                'sports',
                'images',
                'timeSlots' => function ($q) use ($request) {

                    $q->where('status', 'available');

                    if ($request->filled('date')) {
                        $q->whereDate('slot_date', $request->date);
                    }

                    if ($request->filled('sport_id')) {
                        $q->where('sport_id', $request->sport_id);
                    }

                    if ($request->filled('hour')) {
                        $hour = Carbon::parse($request->hour)->format('H:i:s');

                        $q->where('start_time', '<=', $hour)
                            ->where('end_time', '>', $hour);
                    }
                },
            ]);

        if ($request->filled('country_id')) {
            $query->whereHas('city', function ($q) use ($request) {
                $q->where('country_id', $request->country_id);
            });
        }

        if ($request->filled('city_id')) {
            $query->where('city_id', $request->city_id);
        }

        if ($request->filled('sport_id')) {
            $query->whereHas('sports', function ($q) use ($request) {
                $q->where('sports.id', $request->sport_id);
            });
        }

        if ($request->filled('search')) {
            $search = $request->search;

            $query->where(function ($q) use ($search) {
                $q->where('name_ar', 'like', "%{$search}%")
                    ->orWhere('name_en', 'like', "%{$search}%");
            });
        }

        if ($request->filled('date')) {
            $query->whereHas('timeSlots', function ($q) use ($request) {
                $q->whereDate('slot_date', $request->date)
                    ->where('status', 'available');

                if ($request->filled('sport_id')) {
                    $q->where('sport_id', $request->sport_id);
                }

                if ($request->filled('hour')) {
                    $hour = Carbon::parse($request->hour)->format('H:i:s');

                    $q->where('start_time', '<=', $hour)
                        ->where('end_time', '>', $hour);
                }
            });
        }

        $venues = $query->paginate(10);
        return response()->json([
            'success' => true,
            'data' => $venues,
            'message' => 'Venues retrieved successfully',
            'errors' => null,
        ]);
    }

    public function show(int $id): JsonResponse
{
    $venue = Venue::query()
        ->where('status', 'active')
        ->with([
            'city.country',
            'sports',
            'images',
        ])
        ->find($id);

    if (!$venue) {
        return response()->json([
            'success' => false,
            'data' => null,
            'message' => 'Venue not found',
            'errors' => null,
        ], 404);
    }

    return response()->json([
        'success' => true,
        'data' => $venue,
        'message' => 'Venue retrieved successfully',
        'errors' => null,
    ]);
}
public function timeSlots(int $id, Request $request): JsonResponse
{
    $venue = Venue::query()
        ->where('status', 'active')
        ->find($id);

    if (!$venue) {
        return response()->json([
            'success' => false,
            'data' => null,
            'message' => 'Venue not found',
            'errors' => null,
        ], 404);
    }

    $request->validate([
        'date' => ['required', 'date'],
        'sport_id' => ['required', 'integer', 'exists:sports,id'],
    ]);

    $timeSlots = $venue->timeSlots()
        ->whereDate('slot_date', $request->date)
        ->where('sport_id', $request->sport_id)
        ->orderBy('start_time')
        ->get();

    return response()->json([
        'success' => true,
        'data' => $timeSlots,
        'message' => 'Time slots retrieved successfully',
        'errors' => null,
    ]);
}
public function update(UpdateVenueRequest $request,  $id): JsonResponse
{
    $venue = Venue::find($id);

    if (!$venue) {
        return response()->json([
            'success' => false,
            'data' => null,
            'message' => 'Venue not found.',
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
        'message' => 'You are not authorized to update this venue.',
        'errors' => null,
    ], 403);
}

        $validated = $request->validated();

        if (array_key_exists('sport_ids', $validated)) {
            $sportIds = $validated['sport_ids'];
            unset($validated['sport_ids']);

            $venue->update($validated);
            $venue->sports()->sync($sportIds);
        } else {
            $venue->update($validated);
        }

    return response()->json([
        'success' => true,
        'data' => $venue->fresh(),
        'message' => 'Venue updated successfully.',
    ]);
}
public function uploadImage(Request $request, int $id): JsonResponse
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

    // Only the venue owner can upload images.
    if ((int) $venue->owner_id !== (int) $user->id) {
        return response()->json([
            'success' => false,
            'data' => null,
            'message' => 'You are not authorized to manage this venue.',
            'errors' => null,
        ], 403);
    }

    $validated = $request->validate([
        'image' => [
            'required',
            'image',
            'mimes:jpg,jpeg,png,webp',
            'max:5120',
        ],
        'sort_order' => [
            'nullable',
            'integer',
            'min:0',
        ],
    ]);

    try {
        $file = $request->file('image');

        if (!$file || !$file->isValid()) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'The uploaded image is invalid.',
                'errors' => null,
            ], 422);
        }

        // Upload image to Cloudinary.
        $result = Cloudinary::uploadApi()->upload(
            $file->getRealPath(),
            [
                'folder' => 'playvo/venues',
                'resource_type' => 'image',
            ]
        );

        // Support array and SDK response objects.
        if (is_array($result)) {
            $uploadData = $result;
        } elseif (is_object($result) && method_exists($result, 'getArrayCopy')) {
            $uploadData = $result->getArrayCopy();
        } elseif (is_object($result) && method_exists($result, 'jsonSerialize')) {
            $uploadData = $result->jsonSerialize();
        } else {
            $uploadData = null;
        }

        if (!is_array($uploadData)) {
            throw new \RuntimeException(
                'Unexpected Cloudinary response type: ' . get_debug_type($result)
            );
        }

        $imageUrl = $uploadData['secure_url'] ?? null;

        if (!is_string($imageUrl) || $imageUrl === '') {
            \Illuminate\Support\Facades\Log::error(
                'Cloudinary response did not contain secure_url',
                [
                    'response_type' => get_debug_type($result),
                    'response_keys' => array_keys($uploadData),
                ]
            );

            throw new \RuntimeException(
                'Cloudinary did not return a secure image URL.'
            );
        }

        // Determine image order.
        $sortOrder = $validated['sort_order']
            ?? (($venue->images()->max('sort_order') ?? -1) + 1);

        // Save the Cloudinary URL in the database.
        $image = $venue->images()->create([
            'image_url' => $imageUrl,
            'sort_order' => $sortOrder,
        ]);

        return response()->json([
            'success' => true,
            'data' => $image,
            'message' => 'Venue image uploaded successfully.',
            'errors' => null,
        ], 201);

   } catch (\Throwable $e) {
    \Illuminate\Support\Facades\Log::error(
        'Venue image upload failed',
        [
            'venue_id' => $id,
            'exception' => get_class($e),
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]
    );

    return response()->json([
        'success' => false,
        'data' => null,
        'message' => 'Failed to upload venue image.',
        'errors' => null,
    ], 500);
}
}
public function ratings(Request $request, int $id): JsonResponse
{
    $user = $request->user();

    $venue = Venue::where('id', $id)
        ->where('owner_id', $user->id)
        ->first();

    if (!$venue) {
        return response()->json([
            'success' => false,
            'data' => null,
            'message' => 'Venue not found or access denied.',
            'errors' => null,
        ], 404);
    }

    $ratingsQuery = VenueRating::where('venue_id', $venue->id);

    $totalRatings = (clone $ratingsQuery)->count();

    $averageRating = $totalRatings > 0
        ? round((float) (clone $ratingsQuery)->avg('rating'), 2)
        : 0;

    $ratingCounts = (clone $ratingsQuery)
        ->selectRaw('rating, COUNT(*) as total')
        ->groupBy('rating')
        ->pluck('total', 'rating');

    $distribution = [];

    for ($star = 1; $star <= 5; $star++) {
        $count = (int) ($ratingCounts[$star] ?? 0);

        $distribution[] = [
            'stars' => $star,
            'count' => $count,
            'percentage' => $totalRatings > 0
                ? round(($count / $totalRatings) * 100, 2)
                : 0,
        ];
    }

    $ratings = (clone $ratingsQuery)
        ->with('booking.captain')
        ->latest()
        ->paginate(10);

    $ratings->getCollection()->transform(function ($rating) {
        $rating->player_name = $rating->booking?->captain?->name;
        $rating->unsetRelation('booking');

        return $rating;
    });

    return response()->json([
        'success' => true,
        'data' => [
            'venue' => [
                'id' => $venue->id,
                'name' => $venue->name_en,
                'avg_rating' => $averageRating,
                'ratings_count' => $totalRatings,
            ],
            'rating_distribution' => $distribution,
            'ratings' => $ratings,
        ],
        'message' => 'Venue ratings retrieved successfully.',
        'errors' => null,
    ]);
}
public function setCoverImage(Request $request, int $id, int $imageId): JsonResponse
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

    if ((int) $venue->owner_id !== (int) $request->user()->id) {
        return response()->json([
            'success' => false,
            'data' => null,
            'message' => 'You are not authorized to manage this venue.',
            'errors' => null,
        ], 403);
    }

    $image = $venue->images()->whereKey($imageId)->first();

    if (!$image) {
        return response()->json([
            'success' => false,
            'data' => null,
            'message' => 'Image not found for this venue.',
            'errors' => null,
        ], 404);
    }

    DB::transaction(function () use ($venue, $image) {
        $venue->images()->update(['is_cover' => false]);
        $image->update(['is_cover' => true]);
    });

    return response()->json([
        'success' => true,
        'data' => $venue->images()->orderBy('sort_order')->get(),
        'message' => 'Cover image updated successfully.',
        'errors' => null,
    ]);
}
public function deleteImage(Request $request, int $id, int $imageId): JsonResponse
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

    if ((int) $venue->owner_id !== (int) $request->user()->id) {
        return response()->json([
            'success' => false,
            'data' => null,
            'message' => 'You are not authorized to manage this venue.',
            'errors' => null,
        ], 403);
    }

    $image = $venue->images()->whereKey($imageId)->first();

    if (!$image) {
        return response()->json([
            'success' => false,
            'data' => null,
            'message' => 'Image not found for this venue.',
            'errors' => null,
        ], 404);
    }

    $path = parse_url($image->image_url, PHP_URL_PATH);

    if (is_string($path) && Str::startsWith($path, '/storage/')) {
        $filePath = Str::after($path, '/storage/');
        Storage::disk('public')->delete($filePath);
    }

    $image->delete();

    return response()->json([
        'success' => true,
        'data' => null,
        'message' => 'Venue image deleted successfully.',
        'errors' => null,
    ]);
}
public function reorderImages(Request $request, int $id): JsonResponse
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

    if ((int) $venue->owner_id !== (int) $request->user()->id) {
        return response()->json([
            'success' => false,
            'data' => null,
            'message' => 'You are not authorized to manage this venue.',
            'errors' => null,
        ], 403);
    }

    $validated = $request->validate([
        'image_ids' => ['required', 'array', 'min:1'],
        'image_ids.*' => ['required', 'integer', 'distinct'],
    ]);

    $imageIds = array_map('intval', $validated['image_ids']);

    $venueImageIds = $venue->images()
        ->pluck('id')
        ->map(fn ($imageId) => (int) $imageId)
        ->all();

    $sortedSubmittedIds = $imageIds;
    $sortedVenueIds = $venueImageIds;

    sort($sortedSubmittedIds);
    sort($sortedVenueIds);

    if ($sortedSubmittedIds !== $sortedVenueIds) {
        return response()->json([
            'success' => false,
            'data' => null,
            'message' => 'Send all image IDs belonging to this venue exactly once.',
            'errors' => null,
        ], 422);
    }

    DB::transaction(function () use ($venue, $imageIds) {
        foreach ($imageIds as $position => $imageId) {
            $venue->images()
                ->whereKey($imageId)
                ->update(['sort_order' => $position]);
        }
    });

    return response()->json([
        'success' => true,
        'data' => $venue->images()->orderBy('sort_order')->get(),
        'message' => 'Venue images reordered successfully.',
        'errors' => null,
    ]);
}
public function myVenues(Request $request): JsonResponse
{
    $venues = Venue::query()
        ->where('owner_id', $request->user()->id)
        ->with(['city.country', 'sports', 'images'])
        ->latest()
        ->get();

    return response()->json([
        'success' => true,
        'data' => $venues,
        'message' => 'Owner venues retrieved successfully',
        'errors' => null,
    ]);
}
}
