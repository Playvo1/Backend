<?php

namespace App\Http\Requests\Api;

 use Illuminate\Foundation\Http\FormRequest;

class googleAuthRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_token' => ['required', 'string'],
        ];
    }
}
