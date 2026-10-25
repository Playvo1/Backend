<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class PlayerProfileController extends Controller
{
    /**
     * Display the player profile.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        $profileImage = $user->images()
            ->latest('id')
            ->first();

        return ApiResponse::send(
            true,
            200,
            'Player profile retrieved successfully.',
            [
                'player' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'profile_image' => $profileImage
                        ? $this->imageUrl($profileImage->image_url)
                        : null,
                ],
            ]
        );
    }

    /**
     * Update the player account details.
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
            'Player profile updated successfully.',
            [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
            ]
        );
    }

    /**
     * Update the player's personal profile photo.
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

        $path = $request->file('photo')->store(
            'profile-images',
            'public'
        );

        try {
            $oldPath = DB::transaction(function () use ($user, $path) {
                $image = $user->images()
                    ->lockForUpdate()
                    ->latest('id')
                    ->first();

                $oldPath = $image?->image_url;

                if ($image) {
                    $image->update([
                        'image_url' => $path,
                    ]);
                } else {
                    $user->images()->create([
                        'image_url' => $path,
                        'sort_order' => 0,
                        'is_cover' => true,
                    ]);
                }

                return $oldPath;
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
            'Player profile photo updated successfully.',
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
