<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\CloudinaryImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AdminProfileController extends Controller
{
    /**
     * Display the authenticated admin's profile.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        $user->load('images');

        $image = $user->images
            ->sortByDesc('id')
            ->first();

        return ApiResponse::send(
            true,
            200,
            'Admin profile retrieved successfully.',
            [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'profile_image' => $image
                    ? $this->imageUrl($image->image_url)
                    : null,
            ]
        );
    }

    /**
     * Upload or update the authenticated admin's profile photo.
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
    if (!$imagePath) {
        return null;
    }

    if (filter_var($imagePath, FILTER_VALIDATE_URL)) {
        return $imagePath;
    }

    if (str_starts_with($imagePath, '/storage/')) {
        return url($imagePath);
    }

    return asset('storage/' . ltrim($imagePath, '/'));
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
