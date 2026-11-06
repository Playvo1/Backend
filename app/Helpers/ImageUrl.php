<?php

namespace App\Helpers;

class ImageUrl
{
    /**
     * Return a public URL for a stored image value (absolute URL or local storage path).
     */
    public static function resolve(?string $imagePath): ?string
    {
        if (!$imagePath) {
            return null;
        }

        if (preg_match('#^https?://#i', $imagePath)) {
            return $imagePath;
        }

        if (str_starts_with($imagePath, '/storage/')) {
            return url($imagePath);
        }

        return asset('storage/' . ltrim($imagePath, '/'));
    }
}
