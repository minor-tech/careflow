<?php

namespace App\Http\Requests;

use App\Support\AccessPin;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AttemptTrackingEntryRequest extends FormRequest
{
    /**
     * Public: anyone may try. The rate limits in the controller are the guard.
     */
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
            'queue_code' => ['required', 'string', 'max:12'],
            'pin' => ['required', 'string', 'digits:'.AccessPin::LENGTH],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'queue_code.required' => 'Enter the queue code from your ticket, for example V027.',
            'pin.required' => 'Enter the '.AccessPin::LENGTH.'-digit PIN from your ticket.',
            'pin.digits' => 'The PIN is '.AccessPin::LENGTH.' digits.',
        ];
    }
}
