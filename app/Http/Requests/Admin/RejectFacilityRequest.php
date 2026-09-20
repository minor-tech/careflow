<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RejectFacilityRequest extends FormRequest
{
    /**
     * Route middleware limits this to system admins; checked again here since
     * a rejection is emailed to the facility.
     */
    public function authorize(): bool
    {
        return $this->user()?->isSystemAdmin() ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Say why the registration is being rejected. The facility admin will receive this.',
            'reason.min' => 'Give the facility admin a little more detail (at least 10 characters).',
        ];
    }
}
