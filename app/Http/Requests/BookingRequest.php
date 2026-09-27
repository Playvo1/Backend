<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class BookingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
  public function rules(): array
{
    return [
        'time_slot_id' => ['required', 'integer', 'exists:time_slots,id'],
        'captain_name' => ['required', 'string', 'max:255'],
        'captain_role' => ['required', 'string', 'max:50'],
        'captain_phone' => ['required', 'string', 'max:30'],
    ];
}
}
