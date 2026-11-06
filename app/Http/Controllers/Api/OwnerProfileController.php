<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Helpers\ImageUrl;
use App\Http\Controllers\Controller;
use App\Services\CloudinaryImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class OwnerProfileController extends Controller
{
    /**
     * Display the owner profile and venue information.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        $venue = $user->ownedVenues()
            ->with(['city', 'sports', 'images'])
            ->first();

        $profileImage = $user->images()
            ->latest('id')
            ->first();

        return ApiResponse::send(
            true,
            200,
            'Owner profile retrieved successfully.',
            [
                'owner' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'profile_image' => $profileImage
                        ? $this->imageUrl($profileImage->image_url)
                        : null,
                ],

                'venue' => $venue ? [
                    'id' => $venue->id,
                    'name_ar' => $venue->name_ar,
                    'name_en' => $venue->name_en,
                    'address' => $venue->address_ar ?: $venue->address_en,
                    'area' => $venue->area_ar ?: $venue->area_en,
                    'address_ar' => $venue->address_ar,
                    'address_en' => $venue->address_en,
                    'area_ar' => $venue->area_ar,
                    'area_en' => $venue->area_en,
                    'description_ar' => $venue->description_ar,
                    'description_en' => $venue->description_en,
                    'surface_ar' => $venue->surface_ar,
                    'surface_en' => $venue->surface_en,
                    'team_format_ar' => $venue->team_format_ar,
                    'team_format_en' => $venue->team_format_en,
                    'capacity_ar' => $venue->capacity_ar,
                    'capacity_en' => $venue->capacity_en,
                    'length_m' => $venue->length_m,
                    'width_m' => $venue->width_m,
                    'morning_hourly_price' => $venue->morning_hourly_price,
                    'evening_hourly_price' => $venue->evening_hourly_price,

                    'city' => $venue->city ? [
                        'id' => $venue->city->id,
                        'name_ar' => $venue->city->name_ar,
                        'name_en' => $venue->city->name_en,
                    ] : null,

                    'latitude' => $venue->latitude,
                    'longitude' => $venue->longitude,

                    // Venue status is displayed only.
                    // The owner cannot change it through this controller.
                    'status' => $venue->status,

                    'sports' => $venue->sports
                        ->map(function ($sport) {
                            return [
                                'id' => $sport->id,
                                'name_ar' => $sport->name_ar,
                                'name_en' => $sport->name_en,
                            ];
                        })
                        ->values(),

                    'images' => $venue->images
                        ->map(function ($image) {
                            return [
                                'id' => $image->id,
                                'image_url' => $this->imageUrl(
                                    $image->image_url
                                ),
                                'is_cover' => $image->is_cover,
                            ];
                        })
                        ->values(),
                ] : null,
            ]
        );
    }

    /**
     * Update the owner account details.
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
            ],

            'email' => [
                'sometimes',
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],

            'phone' => [
                'sometimes',
                'nullable',
                'string',
                'max:30',
            ],
        ]);

        $user->fill($validated);
        $user->save();

        return ApiResponse::send(
            true,
            200,
            'Owner profile updated successfully.',
            [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
            ]
        );
    }

    /**
     * Update the owner's personal profile photo.
     */

public function updatePhoto(Request $request): JsonResponse
{
    $request->validate([
        'photo' => [
            'required',
            'image',
            'mimes:jpg,jpeg,png,webp',
            'max:2048',
        ],
    ]);

    $user = $request->user();

    $uploaded = CloudinaryImageService::upload(
        $request->file('photo'),
        'playvo/profile-images'
    );

    try {
        $oldImageUrl = DB::transaction(function () use ($user, $uploaded) {
            $image = $user->images()
                ->lockForUpdate()
                ->latest('id')
                ->first();

            $oldUrl = $image?->image_url;

            if ($image) {
                $image->update([
                    'image_url' => $uploaded['url'],
                ]);
            } else {
                $user->images()->create([
                    'image_url' => $uploaded['url'],
                    'sort_order' => 0,
                    'is_cover' => true,
                ]);
            }

            return $oldUrl;
        });
    } catch (\Throwable $e) {
        CloudinaryImageService::delete($uploaded['url']);
        throw $e;
    }

    if ($oldImageUrl && $oldImageUrl !== $uploaded['url']) {
        CloudinaryImageService::delete($oldImageUrl);
    }

    $image = $user->images()
        ->latest('id')
        ->first();

    return ApiResponse::send(
        true,
        200,
        'Profile photo updated successfully.',
        [
            'profile_image' => $image
                ? $this->imageUrl($image->image_url)
                : null,
        ]
    );
}


    /**
     * Return a public URL for an image path.
     */
    private function imageUrl(?string $imagePath): ?string
    {
        return ImageUrl::resolve($imagePath);
    }

    /**
     * Convert a stored image URL or path to a disk-relative path.
     */
    private function storagePath(?string $imagePath): ?string
    {
        if (!$imagePath) {
            return null;
        }

        if (str_starts_with($imagePath, '/storage/')) {
            return substr($imagePath, strlen('/storage/'));
        }

        if (filter_var($imagePath, FILTER_VALIDATE_URL)) {
            $path = parse_url($imagePath, PHP_URL_PATH);

            if (!$path || !str_starts_with($path, '/storage/')) {
                return null;
            }

            return substr($path, strlen('/storage/'));
        }

        return ltrim($imagePath, '/');
    }
}
