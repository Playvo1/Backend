<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a venue photo upload (US-3.2): MIME type and size are checked server-side,
 * and sort_order is optional (the photo goes to the end of the gallery when omitted).
 */
class UploadVenueImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file' => 'required|file|image|mimes:jpg,jpeg,png,webp|max:5120',
            'sort_order' => 'nullable|integer|min:0|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Please choose a photo to upload.',
            'file.image' => 'Unsupported file type. Upload a JPG, PNG or WEBP image.',
            'file.mimes' => 'Unsupported file type. Upload a JPG, PNG or WEBP image.',
            'file.max' => 'The photo must not be larger than 5 MB.',
        ];
    }
}
