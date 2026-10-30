<?php

namespace App\Services;

use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class CloudinaryImageService
{
    /**
     * Upload an image to Cloudinary and return its URL and public ID.
     */
    public static function upload(
        UploadedFile $file,
        string $folder
    ): array {
        $result = Cloudinary::uploadApi()->upload(
            $file->getRealPath(),
            [
                'folder' => $folder,
                'resource_type' => 'image',
            ]
        );

        if (empty($result['secure_url'])) {
            throw new RuntimeException(
                'Cloudinary did not return a secure image URL.'
            );
        }

        return [
            'url' => $result['secure_url'],
            'public_id' => $result['public_id'] ?? null,
        ];
    }

    /**
     * Delete a Cloudinary image or a legacy local-storage image.
     */
    public static function delete(?string $imageUrl): void
    {
        if (!$imageUrl) {
            return;
        }

        // Delete images hosted on Cloudinary.
        if (str_contains(
            strtolower(parse_url($imageUrl, PHP_URL_HOST) ?? ''),
            'res.cloudinary.com'
        )) {
            $path = parse_url($imageUrl, PHP_URL_PATH) ?? '';
            $uploadPosition = strpos($path, '/upload/');

            if ($uploadPosition === false) {
                return;
            }

            $afterUpload = substr(
                $path,
                $uploadPosition + strlen('/upload/')
            );

            $segments = explode('/', trim($afterUpload, '/'));
            $versionIndex = null;

            foreach ($segments as $index => $segment) {
                if (preg_match('/^v\d+$/', $segment)) {
                    $versionIndex = $index;
                    break;
                }
            }

            // A version segment is expected in normal Cloudinary URLs.
            if ($versionIndex !== null) {
                $publicId = implode(
                    '/',
                    array_slice($segments, $versionIndex + 1)
                );
            } else {
                // Avoid deleting an asset if its public ID is ambiguous.
                return;
            }

            $publicId = preg_replace('/\.[^.\/]+$/', '', $publicId);

            if ($publicId !== '') {
                Cloudinary::uploadApi()->destroy(
                    $publicId,
                    ['resource_type' => 'image']
                );
            }

            return;
        }

        // Keep support for older images saved on Laravel's public disk.
        $path = $imageUrl;

        if (filter_var($imageUrl, FILTER_VALIDATE_URL)) {
            $urlPath = parse_url($imageUrl, PHP_URL_PATH);

            if (!is_string($urlPath)
                || !str_starts_with($urlPath, '/storage/')) {
                return;
            }

            $path = substr($urlPath, strlen('/storage/'));
        } elseif (str_starts_with($path, '/storage/')) {
            $path = substr($path, strlen('/storage/'));
        }

        $path = ltrim($path, '/');

        if ($path !== '') {
            Storage::disk('public')->delete($path);
        }
    }
}
