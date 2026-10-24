<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
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
        $file = $request->file('photo');

        $path = $file->store('profile-images', 'public');

        try {
            DB::transaction(function () use ($user, $path) {
                $image = $user->images()
                    ->lockForUpdate()
                    ->latest('id')
                    ->first();

                if ($image) {
                    $oldPath = $image->image_url;

                    $image->update([
                        'image_url' => $path,
                    ]);
                } else {
                    $image = $user->images()->create([
                        'image_url' => $path,
                        'sort_order' => 0,
                        'is_cover' => true,
                    ]);

                    $oldPath = null;
                }
            });
        } catch (\Throwable $e) {
            Storage::disk('public')->delete($path);

            throw $e;
        }

        // Delete the old file after the database transaction succeeds.
        if (!empty($oldPath)) {
            $oldPath = $this->storagePath($oldPath);

            if ($oldPath && $oldPath !== $path) {
                Storage::disk('public')->delete($oldPath);
            }
        }

        $user->load('images');

        $image = $user->images
            ->sortByDesc('id')
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
