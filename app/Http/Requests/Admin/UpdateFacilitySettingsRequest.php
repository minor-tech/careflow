<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateFacilitySettingsRequest extends FormRequest
{
    /**
     * Route middleware has already limited this to admins.
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
            'remote_queue_enabled' => ['boolean'],
            'remote_queue_max_pending' => ['required', 'integer', 'min:1', 'max:500'],
            'remote_queue_accept_until' => ['nullable', 'date_format:H:i'],
            'remote_queue_grace_minutes' => ['required', 'integer', 'min:1', 'max:60'],
            'remote_queue_allow_doctor_choice' => ['boolean'],
            'remote_queue_allow_service_choice' => ['boolean'],
            'self_checkin_enabled' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'remote_queue_max_pending.min' => 'Allow at least one waiting request.',
            'remote_queue_max_pending.max' => 'Allow at most 500 waiting requests.',
            'remote_queue_accept_until.date_format' => 'Enter the time as hours and minutes, for example 16:00.',
            'remote_queue_grace_minutes.min' => 'Give patients at least one minute.',
            'remote_queue_grace_minutes.max' => 'A grace period of more than an hour would hold up the queue.',
        ];
    }

    /**
     * Ticked boxes arrive as "1" and unticked ones not at all; an empty cut-off means no cut-off.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'remote_queue_enabled' => $this->boolean('remote_queue_enabled'),
            'remote_queue_allow_doctor_choice' => $this->boolean('remote_queue_allow_doctor_choice'),
            'remote_queue_allow_service_choice' => $this->boolean('remote_queue_allow_service_choice'),
            'self_checkin_enabled' => $this->boolean('self_checkin_enabled'),
            'remote_queue_accept_until' => filled($this->input('remote_queue_accept_until')) ? $this->input('remote_queue_accept_until') : null,
        ]);
    }
}
