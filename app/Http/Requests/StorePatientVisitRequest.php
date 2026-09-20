<?php

namespace App\Http\Requests;

use App\Enums\Gender;
use App\Support\PhoneNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePatientVisitRequest extends FormRequest
{
    /**
     * Route middleware has already limited this to admins and receptionists.
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
            'phone' => ['required', 'string', 'regex:'.PhoneNumber::CANONICAL_PATTERN],
            'name' => ['required', 'string', 'max:255'],
            'dob' => ['nullable', 'date', 'before_or_equal:today', 'after:1900-01-01'],
            'gender' => ['nullable', Rule::enum(Gender::class)],
            'department_id' => [
                'nullable',
                Rule::exists('departments', 'id')
                    ->where('facility_id', $this->user()->facility_id)
                    ->where('is_active', true),
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
            'dob.before_or_equal' => 'The date of birth cannot be in the future.',
            'department_id.exists' => 'Choose one of your facility\'s active departments.',
        ];
    }

    /**
     * Store and match phone numbers in one canonical form (+254712345678).
     */
    protected function prepareForValidation(): void
    {
        $phone = (string) $this->input('phone');

        $this->merge(['phone' => PhoneNumber::normalize($phone) ?? $phone]);
    }
}
