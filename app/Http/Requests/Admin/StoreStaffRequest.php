<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStaffRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['required', 'string', 'regex:/^\+?[0-9][0-9\s\-()]{7,18}$/'],
            'role' => ['required', Rule::in(array_keys(UserRole::staffOptions()))],
            'department_id' => [
                'required',
                Rule::exists('departments', 'id')->where('facility_id', $this->user()->facility_id),
            ],
            // A doctor's specialty: one of the services of the department they work in.
            'service_id' => [
                'nullable',
                Rule::exists('services', 'id')->where('department_id', $this->input('department_id')),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => 'Enter a valid phone number, for example 0712 345 678.',
            'email.unique' => 'An account with this email already exists.',
            'department_id.required' => 'Choose the department this person works in.',
            'department_id.exists' => 'Choose one of your facility\'s departments.',
            'service_id.exists' => 'Choose one of the services of the department this person works in.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => strtolower(trim((string) $this->input('email'))),
            // Only a doctor has a specialty.
            'service_id' => $this->input('role') === UserRole::Doctor->value ? $this->input('service_id') : null,
        ]);
    }
}
