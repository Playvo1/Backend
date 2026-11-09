<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ImageUrl;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AdminVenueRequest;
use App\Mail\TemporaryPasswordMail;
use App\Models\Booking;
use App\Models\Image;
use App\Models\Sport;
use App\Models\User;
use App\Models\Venue;
use App\Services\AccountProvisioner;
use App\Services\CloudinaryImageService;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class AdminVenueController extends Controller
{
    private const IMAGE_FOLDER = 'playvo/venues';

    private const VENUE_FIELDS = [
        'city_id',
        'name_ar', 'name_en',
        'address_ar', 'address_en',
        'area_ar', 'area_en',
        'description_ar', 'description_en',
        'surface_ar', 'surface_en',
        'team_format_ar', 'team_format_en',
        'capacity_ar', 'capacity_en',
        'latitude', 'longitude',
        'length_m', 'width_m',
        'min_hourly_price', 'morning_hourly_price', 'evening_hourly_price',
    ];

    /**
     * Venues management list. Soft-deleted venues are not listed; active and inactive
     * (removed by an admin) are, and can be filtered by status.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city_id' => ['sometimes', 'integer'],
            'status' => ['sometimes', 'in:active,inactive'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Venue::query()
            ->with([
                'city:id,name_ar,name_en',
                'owner:id,name,email',
                'images' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
            ])
            ->latest('id');

        if (filled($validated['search'] ?? null)) {
            // "!" escapes LIKE wildcards the same way on MySQL and SQLite.
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $validated['search']).'%';

            $query->where(function ($q) use ($term) {
                foreach (['name_ar', 'name_en', 'address_ar', 'address_en', 'area_ar', 'area_en'] as $column) {
                    $q->orWhereRaw("{$column} LIKE ? ESCAPE '!'", [$term]);
                }
            });
        }

        if (isset($validated['city_id'])) {
            $query->where('city_id', $validated['city_id']);
        }

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $venues = $query
            ->paginate($validated['per_page'] ?? 10)
            ->withQueryString()
            ->through(fn (Venue $venue) => [
                'id' => $venue->id,
                'name_ar' => $venue->name_ar,
                'name_en' => $venue->name_en,
                'status' => $venue->status,
                'city' => $venue->city ? [
                    'id' => $venue->city->id,
                    'name_ar' => $venue->city->name_ar,
                    'name_en' => $venue->city->name_en,
                ] : null,
                'address_ar' => $venue->address_ar,
                'address_en' => $venue->address_en,
                'area_ar' => $venue->area_ar,
                'area_en' => $venue->area_en,
                'owner' => $venue->owner ? [
                    'id' => $venue->owner->id,
                    'name' => $venue->owner->name,
                    'email' => $venue->owner->email,
                ] : null,
                'cover_image_url' => $this->coverUrl($venue),
                'created_at' => $venue->created_at,
            ]);

        return response()->json([
            'success' => true,
            'data' => $venues,
            'message' => 'Venues retrieved successfully.',
            'errors' => null,
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $venue = Venue::find($id);

        if (!$venue) {
            return $this->notFound();
        }

        return response()->json([
            'success' => true,
            'data' => $this->details($venue),
            'message' => 'Venue retrieved successfully.',
            'errors' => null,
        ]);
    }

    /**
     * Creates the venue, its owner account (or links an existing venue owner), its sports
     * and photos. Photos are uploaded first; the database work runs in one transaction and
     * any photo already uploaded is deleted again if anything fails.
     */
    public function store(AdminVenueRequest $request): JsonResponse
    {
        $data = $request->validated();
        $files = $request->file('images', []);

        $uploadedUrls = $this->uploadAll($files);

        if ($uploadedUrls === null) {
            return $this->uploadFailed();
        }

        $newOwner = empty($data['owner_id']);
        $generatedPassword = $newOwner && empty($data['owner_password'])
            ? Str::password(12)
            : null;

        try {
            $result = DB::transaction(function () use ($data, $uploadedUrls, $newOwner, $generatedPassword, $request) {
                $owner = $newOwner
                    ? app(AccountProvisioner::class)->create(
                        $data['owner_name'],
                        $data['owner_email'],
                        $data['owner_phone'] ?? null,
                        $generatedPassword ?? $data['owner_password'],
                        'venue_owner',
                        'venue_owner_created',
                        $request->user()->id,
                    )
                    : User::findOrFail($data['owner_id']);

                $venueData = Arr::only($data, self::VENUE_FIELDS);
                $venueData['name_en'] = filled($data['name_en'] ?? null) ? $data['name_en'] : $data['name_ar'];

                try {
                    $venue = Venue::create($venueData + [
                        'owner_id' => $owner->id,
                        'status' => 'active',
                    ]);
                } catch (UniqueConstraintViolationException $e) {
                    // A new owner was created above, so roll it back with the rest.
                    if ($newOwner) {
                        throw $e;
                    }

                    // Existing owner: nothing has been written by this request yet, and the
                    // failed statement alone is undone (MySQL and SQLite), so end the
                    // transaction without touching the other request's venue.
                    return $e;
                }

                $venue->sports()->sync($data['sport_ids'] ?? $this->defaultSportIds());

                foreach ($uploadedUrls as $index => $url) {
                    $venue->images()->create([
                        'image_url' => $url,
                        'sort_order' => $index,
                        'is_cover' => $index === 0,
                    ]);
                }

                return [$venue, $owner];
            });

            if ($result instanceof UniqueConstraintViolationException) {
                throw $result;
            }

            [$venue, $owner] = $result;
        } catch (UniqueConstraintViolationException $e) {
            // Another request created the owner's venue or the same owner email after validation.
            $this->deleteUploaded($uploadedUrls);

            return $this->uniqueConflict($e);
        } catch (\Throwable $e) {
            $this->deleteUploaded($uploadedUrls);

            Log::error('Admin venue creation failed', [
                'exception' => get_class($e),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Failed to create the venue.',
                'errors' => null,
            ], 500);
        }

        // A password the admin typed is handed over by the admin; only a generated one is emailed.
        // Everything is committed by now, so a mail failure is logged, not reported as a failed create.
        $emailed = false;

        if ($generatedPassword !== null) {
            try {
                Mail::to($owner->email)->send(new TemporaryPasswordMail($owner->name, $generatedPassword));
                $emailed = true;
            } catch (\Throwable $e) {
                Log::error('Venue owner credentials email failed', [
                    'user_id' => $owner->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'data' => $this->details($venue) + [
                'owner_account' => [
                    'id' => $owner->id,
                    'email' => $owner->email,
                    'created' => $newOwner,
                    'credentials_emailed' => $emailed,
                ],
            ],
            'message' => 'Venue created successfully.',
            'errors' => null,
        ], 201);
    }

    /**
     * Adds photos to an existing venue, up to AdminVenueRequest::MAX_IMAGES in total.
     * The first photo becomes the cover when the venue has none.
     */
    public function addImages(Request $request, int $id): JsonResponse
    {
        $venue = Venue::find($id);

        if (!$venue) {
            return $this->notFound();
        }

        $request->validate([
            'images' => ['required', 'array', 'min:1', 'max:'.AdminVenueRequest::MAX_IMAGES],
            'images.*' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $files = $request->file('images', []);

        // Early check so nothing is uploaded when the limit is already reached.
        if ($venue->images()->count() + count($files) > AdminVenueRequest::MAX_IMAGES) {
            return $this->tooManyImages();
        }

        $uploadedUrls = $this->uploadAll($files);

        if ($uploadedUrls === null) {
            return $this->uploadFailed();
        }

        try {
            $saved = DB::transaction(function () use ($venue, $uploadedUrls) {
                // Lock the venue row and recount, so concurrent uploads cannot pass the limit.
                Venue::whereKey($venue->id)->lockForUpdate()->first();

                if ($venue->images()->count() + count($uploadedUrls) > AdminVenueRequest::MAX_IMAGES) {
                    return false;
                }

                $hasCover = $venue->images()->where('is_cover', true)->exists();
                $nextOrder = ($venue->images()->max('sort_order') ?? -1) + 1;

                foreach ($uploadedUrls as $index => $url) {
                    $venue->images()->create([
                        'image_url' => $url,
                        'sort_order' => $nextOrder + $index,
                        'is_cover' => !$hasCover && $index === 0,
                    ]);
                }

                return true;
            });
        } catch (\Throwable $e) {
            $this->deleteUploaded($uploadedUrls);

            throw $e;
        }

        if (!$saved) {
            $this->deleteUploaded($uploadedUrls);

            return $this->tooManyImages();
        }

        return response()->json([
            'success' => true,
            'data' => $this->details($venue->fresh())['images'],
            'message' => 'Venue images uploaded successfully.',
            'errors' => null,
        ], 201);
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'status' => ['required', 'in:active,inactive'],
        ]);

        $venue = Venue::withTrashed()->findOrFail($id);

        if ($request->status === 'inactive') {
            $hasUpcomingBookings = Booking::where('status', 'confirmed')
                ->whereHas('timeSlot', function ($query) use ($venue) {
                    $query->where('venue_id', $venue->id)
                        ->where('slot_date', '>', Carbon::today());
                })
                ->exists();

            if ($hasUpcomingBookings) {
                return response()->json([
                    'success' => false,
                    'data' => null,
                    'message' => 'Resolve this venue\'s upcoming bookings before removing it.',
                    'errors' => null,
                ], 422);
            }

            $venue->status = 'inactive';
            $venue->save();

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $venue->id,
                    'status' => $venue->status,
                ],
                'message' => 'Venue deactivated successfully.',
                'errors' => null,
            ]);
        }

        $venue->restore();
        $venue->status = 'active';
        $venue->save();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $venue->id,
                'status' => $venue->status,
            ],
            'message' => 'Venue activated successfully.',
            'errors' => null,
        ]);
    }

    /**
     * Everything the admin venue details screen shows.
     */
    private function details(Venue $venue): array
    {
        $venue->load([
            'city.country',
            'sports',
            'owner',
            'images' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
        ]);

        $city = $venue->city;
        $country = $city?->country;

        return [
            'id' => $venue->id,
            'name_ar' => $venue->name_ar,
            'name_en' => $venue->name_en,
            'status' => $venue->status,
            'address_ar' => $venue->address_ar,
            'address_en' => $venue->address_en,
            'area_ar' => $venue->area_ar,
            'area_en' => $venue->area_en,
            'city' => $city ? [
                'id' => $city->id,
                'name_ar' => $city->name_ar,
                'name_en' => $city->name_en,
            ] : null,
            'country' => $country ? [
                'id' => $country->id,
                'name_ar' => $country->name_ar,
                'name_en' => $country->name_en,
            ] : null,
            'sports' => $venue->sports
                ->map(fn (Sport $sport) => [
                    'id' => $sport->id,
                    'name_ar' => $sport->name_ar,
                    'name_en' => $sport->name_en,
                ])
                ->values(),
            'latitude' => $venue->latitude,
            'longitude' => $venue->longitude,
            'length_m' => $venue->length_m,
            'width_m' => $venue->width_m,
            'description_ar' => $venue->description_ar,
            'description_en' => $venue->description_en,
            'surface_ar' => $venue->surface_ar,
            'surface_en' => $venue->surface_en,
            'team_format_ar' => $venue->team_format_ar,
            'team_format_en' => $venue->team_format_en,
            'capacity_ar' => $venue->capacity_ar,
            'capacity_en' => $venue->capacity_en,
            'min_hourly_price' => $venue->min_hourly_price,
            'morning_hourly_price' => $venue->morning_hourly_price,
            'evening_hourly_price' => $venue->evening_hourly_price,
            'avg_rating' => (float) $venue->avg_rating,
            'created_at' => $venue->created_at,
            'owner' => $venue->owner ? [
                'id' => $venue->owner->id,
                'name' => $venue->owner->name,
                'email' => $venue->owner->email,
                'phone' => $venue->owner->phone,
                'status' => $venue->owner->status,
            ] : null,
            'images' => $venue->images
                ->map(fn (Image $image) => [
                    'id' => $image->id,
                    'image_url' => ImageUrl::resolve($image->image_url),
                    'is_cover' => (bool) $image->is_cover,
                    'sort_order' => (int) $image->sort_order,
                ])
                ->values(),
            'images_count' => $venue->images->count(),
            'cover_image_url' => $this->coverUrl($venue),
        ];
    }

    /**
     * The cover image (is_cover, else the first by sort order) as an absolute URL.
     */
    private function coverUrl(Venue $venue): ?string
    {
        $cover = $venue->images->firstWhere('is_cover', true) ?? $venue->images->first();

        return ImageUrl::resolve($cover?->image_url);
    }

    /**
     * Uploads every file; on any failure deletes those already uploaded and returns null.
     *
     * @param  array<int, UploadedFile>  $files
     * @return list<string>|null
     */
    private function uploadAll(array $files): ?array
    {
        $urls = [];

        try {
            foreach ($files as $file) {
                $urls[] = CloudinaryImageService::upload($file, self::IMAGE_FOLDER)['url'];
            }
        } catch (\Throwable $e) {
            Log::error('Admin venue image upload failed', [
                'exception' => get_class($e),
                'error' => $e->getMessage(),
            ]);

            $this->deleteUploaded($urls);

            return null;
        }

        return $urls;
    }

    /**
     * @param  list<string>  $urls
     */
    private function deleteUploaded(array $urls): void
    {
        foreach ($urls as $url) {
            try {
                CloudinaryImageService::delete($url);
            } catch (\Throwable $e) {
                Log::warning('Could not delete an uploaded venue image', ['url' => $url]);
            }
        }
    }

    /**
     * The platform is for football venues for now, so that is the default sport.
     *
     * @return list<int>
     */
    private function defaultSportIds(): array
    {
        $football = Sport::where('name_en', 'Football')
            ->orWhere('name_ar', 'كرة القدم')
            ->first();

        return $football ? [$football->id] : [];
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'data' => null,
            'message' => 'Venue not found.',
            'errors' => null,
        ], 404);
    }

    private function tooManyImages(): JsonResponse
    {
        $message = 'A venue can have at most '.AdminVenueRequest::MAX_IMAGES.' photos.';

        return response()->json([
            'success' => false,
            'data' => null,
            'message' => $message,
            'errors' => ['images' => [$message]],
        ], 422);
    }

    /**
     * A 422 for a unique index hit after validation passed (a concurrent request won).
     * The failing column is read from the driver message, which names it on MySQL and SQLite.
     */
    private function uniqueConflict(UniqueConstraintViolationException $e): JsonResponse
    {
        $message = $e->getMessage();

        [$field, $text] = match (true) {
            // SQLite names "venues.owner_id"; MySQL names the index "venues_owner_id_unique".
            (bool) preg_match('/venues[._]owner_id/', $message) => ['owner_id', 'This owner already has a venue.'],
            (bool) preg_match('/users[._]email/', $message) => ['owner_email', 'The email has already been taken.'],
            default => [null, 'This record already exists.'],
        };

        return response()->json([
            'success' => false,
            'data' => null,
            'message' => $text,
            'errors' => $field ? [$field => [$text]] : null,
        ], 422);
    }

    private function uploadFailed(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'data' => null,
            'message' => 'Failed to upload venue images.',
            'errors' => null,
        ], 500);
    }
}
