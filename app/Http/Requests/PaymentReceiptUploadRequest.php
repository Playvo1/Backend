<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PaymentReceiptUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'receipt' => [
                'required',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'receipt.required' => 'Receipt image is required.',
            'receipt.image' => 'The receipt must be an image.',
            'receipt.mimes' => 'The receipt must be a JPG, JPEG, PNG, or WEBP image.',
            'receipt.max' => 'The receipt image must not exceed 5 MB.',
        ];
    }
}
