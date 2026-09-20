<?php

namespace App\Http\Requests;

use App\Models\Department;
use App\Models\Facility;
use App\Models\User;
use App\Support\ClinicDay;
use App\Support\PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A request from a public page to join today's queue from home. Anyone may
 * make one: the limits and the review by staff are the guard.
 */
class StoreRemoteRequestRequest extends FormRequest
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
        $facility = $this->facility();

        return [
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'regex:'.PhoneNumber::CANONICAL_PATTERN],
            'service_id' => [
                'nullable',
                Rule::exists('services', 'id')->whereIn('department_id', $this->activeDepartmentIds($facility)),
            ],
            'preferred_doctor_id' => [
                'nullable',
                Rule::exists('users', 'id')->whereIn('id', $this->doctorIdsOnDuty($facility)),
            ],
            ...$this->arrivalRules(),
        ];
    }

    /**
     * When they can get there: from home, a time today that hasn't already passed.
     *
     * @return array<string, mixed>
     */
    protected function arrivalRules(): array
    {
        return [
            'requested_arrival' => [
                'required', 'date_format:H:i',
                fn (string $attribute, mixed $value, Closure $fail) => $value < ClinicDay::now()->format('H:i')
                    ? $fail('Choose a time from now on: that time has already passed today.')
                    : null,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Enter your name.',
            'phone.required' => 'Enter your phone number.',
            'phone.regex' => 'Enter a valid phone number, for example 0712 345 678.',
            'service_id.exists' => 'Choose one of the services listed.',
            'preferred_doctor_id.exists' => 'That doctor is not on duty right now. Choose another, or leave it to the facility.',
            'requested_arrival.required' => 'Tell us when you can arrive.',
            'requested_arrival.date_format' => 'Enter a time such as 10:30.',
        ];
    }

    /**
     * The facility whose page this was sent from (the route names it).
     */
    public function facility(): Facility
    {
        return $this->route('facility');
    }

    protected function prepareForValidation(): void
    {
        $facility = $this->facility();
        $phone = (string) $this->input('phone');

        $this->merge([
            'name' => trim((string) $this->input('name')),
            'phone' => PhoneNumber::normalize($phone) ?? $phone,
            // What the facility didn't offer is ignored, not an error to puzzle over.
            'service_id' => $facility->remote_queue_allow_service_choice ? $this->input('service_id') : null,
            'preferred_doctor_id' => $facility->remote_queue_allow_doctor_choice ? $this->input('preferred_doctor_id') : null,
        ]);
    }

    /**
     * @return list<int>
     */
    private function activeDepartmentIds(Facility $facility): array
    {
        return Department::where('facility_id', $facility->id)->where('is_active', true)->pluck('id')->all();
    }

    /**
     * @return list<int>
     */
    private function doctorIdsOnDuty(Facility $facility): array
    {
        return User::query()
            ->where('facility_id', $facility->id)
            ->where('role', 'doctor')
            ->where('status', 'active')
            ->where('is_on_duty', true)
            ->pluck('id')
            ->all();
    }
}
