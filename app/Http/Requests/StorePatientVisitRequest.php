<?php

namespace App\Http\Requests;

use App\Enums\DepartmentType;
use App\Enums\Gender;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Department;
use App\Support\PhoneNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class StorePatientVisitRequest extends FormRequest
{
    /** The department the visit starts in, once looked up (false: not looked up yet). */
    private Department|false|null $startingDepartment = false;

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
            // What the patient is here for; narrows which doctors fit.
            'service_id' => [
                'nullable',
                Rule::exists('services', 'id')->where('department_id', $this->startingDepartment()?->id),
            ],
            // Chosen by the receptionist from the ranked list, never picked for them.
            'doctor_id' => [
                Rule::requiredIf($this->requiresDoctor()),
                'nullable',
                $this->eligibleDoctor(),
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
            'service_id.exists' => 'Choose one of this department\'s services.',
            'doctor_id.required' => 'Choose a doctor for this patient. If none are listed, no doctor is on duty in this department right now.',
            'doctor_id.exists' => 'That doctor isn\'t on duty for this department and service. Choose one from the list.',
        ];
    }

    /**
     * Whether the department the visit starts in gives each patient their own
     * doctor. Where it doesn't, registration is exactly as it always was.
     */
    public function requiresDoctor(): bool
    {
        return $this->startingDepartment()?->requires_doctor_assignment === true;
    }

    /**
     * The department chosen, or the facility's Reception when none was, the
     * same default the registration itself uses. Null if it isn't one of this
     * facility's active departments (the department rule reports that).
     */
    private function startingDepartment(): ?Department
    {
        if ($this->startingDepartment !== false) {
            return $this->startingDepartment;
        }

        $chosen = $this->input('department_id');

        return $this->startingDepartment = Department::query()
            ->where('facility_id', $this->user()->facility_id)
            ->where('is_active', true)
            ->when(
                filled($chosen),
                fn ($query) => $query->whereKey($chosen),
                fn ($query) => $query->where('type', DepartmentType::Reception)->orderBy('id'),
            )
            ->first();
    }

    /**
     * An active doctor of the department, on duty now, who fits the service if one was chosen.
     */
    private function eligibleDoctor(): Exists
    {
        $rule = Rule::exists('users', 'id')
            ->where('facility_id', $this->user()->facility_id)
            ->where('department_id', $this->startingDepartment()?->id)
            ->where('role', UserRole::Doctor->value)
            ->where('status', UserStatus::Active->value)
            ->where('is_on_duty', true);

        return filled($this->input('service_id')) ? $rule->where('service_id', $this->input('service_id')) : $rule;
    }

    /**
     * Store and match phone numbers in one canonical form (+254712345678).
     */
    protected function prepareForValidation(): void
    {
        $phone = (string) $this->input('phone');

        $this->merge(['phone' => PhoneNumber::normalize($phone) ?? $phone]);

        // A doctor only means something where patients are assigned to doctors.
        if (! $this->requiresDoctor()) {
            $this->merge(['doctor_id' => null]);
        }
    }
}
