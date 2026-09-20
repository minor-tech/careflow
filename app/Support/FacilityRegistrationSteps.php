<?php

namespace App\Support;

use App\Enums\DataRole;
use App\Enums\DepartmentType;
use App\Enums\FacilityType;
use App\Enums\NotificationChannel;
use App\Enums\OperatingDay;
use App\Enums\OwnershipType;
use App\Enums\UserRole;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * The nine screens of the facility registration wizard, and the validation
 * rules each one owns.
 */
class FacilityRegistrationSteps
{
    public const LAST = 9;

    public const STAFF_STEP = 6;

    private const PHONE_PATTERN = '/^\+?[0-9][0-9\s\-()]{7,18}$/';

    /**
     * @return array<int, array{slug: string, title: string, optional: bool}>
     */
    public static function all(): array
    {
        return [
            1 => ['slug' => 'identity', 'title' => 'Facility identity', 'optional' => false],
            2 => ['slug' => 'location', 'title' => 'Location', 'optional' => false],
            3 => ['slug' => 'contact', 'title' => 'Contact', 'optional' => false],
            4 => ['slug' => 'operations', 'title' => 'Operating details', 'optional' => false],
            5 => ['slug' => 'admin', 'title' => 'Admin account', 'optional' => false],
            6 => ['slug' => 'staff', 'title' => 'Staff setup', 'optional' => true],
            7 => ['slug' => 'notifications', 'title' => 'Notifications', 'optional' => false],
            8 => ['slug' => 'compliance', 'title' => 'Data protection', 'optional' => false],
            9 => ['slug' => 'terms', 'title' => 'Terms and submission', 'optional' => false],
        ];
    }

    /**
     * Rules for one screen. $input is the draft merged with the request, since
     * some rules depend on other screens (staff departments, admin email).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function rules(int $step, array $input = []): array
    {
        return match ($step) {
            1 => [
                'name' => ['required', 'string', 'max:255'],
                'facility_type' => ['required', Rule::enum(FacilityType::class)],
                'license_number' => ['required', 'string', 'max:100'],
                'ownership_type' => ['nullable', Rule::enum(OwnershipType::class)],
            ],
            2 => [
                'county' => ['required', Rule::in(Counties::all())],
                'sub_county' => ['required', 'string', 'max:255'],
                'address' => ['required', 'string', 'max:255'],
                'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
                'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
            ],
            3 => [
                'phone' => ['required', 'string', 'regex:'.self::PHONE_PATTERN],
                'alt_phone' => ['nullable', 'string', 'regex:'.self::PHONE_PATTERN],
                'email' => ['required', 'email', 'max:255'],
                'website' => ['nullable', 'url', 'max:255'],
            ],
            4 => self::operationsRules($input),
            5 => self::adminRules($input),
            6 => self::staffRules($input),
            7 => [
                'notification_channels' => ['required', 'array', 'min:1'],
                'notification_channels.*' => [Rule::enum(NotificationChannel::class)],
                'sms_sender_id' => ['nullable', 'alpha_num', 'max:11'],
            ],
            8 => [
                'data_role' => ['required', Rule::enum(DataRole::class)],
                'odpc_registration_no' => ['nullable', 'string', 'max:100'],
                'agreed_dpa' => ['accepted'],
                'confirms_patient_consent' => ['accepted'],
            ],
            9 => [
                'accepted_terms' => ['accepted'],
                'accepted_privacy' => ['accepted'],
                'signature' => ['required', 'string', 'min:3', 'max:255'],
            ],
        };
    }

    /**
     * Rules for the final commit, run over the whole draft. The password is
     * only ever held as a hash by then, so it is checked for presence alone.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function commitRules(array $input): array
    {
        $rules = [];

        foreach (array_keys(self::all()) as $step) {
            $rules += self::rules($step, $input);
        }

        unset($rules['admin_password']);
        $rules['admin_password_hash'] = ['required', 'string'];

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'phone.regex' => 'Enter a valid phone number, for example 0712 345 678.',
            'alt_phone.regex' => 'Enter a valid phone number, for example 0712 345 678.',
            'admin_phone.regex' => 'Enter a valid phone number, for example 0712 345 678.',
            'staff.*.phone.regex' => 'Enter a valid phone number, for example 0712 345 678.',
            'admin_email.unique' => 'An account with this email already exists.',
            'staff.*.email.unique' => 'An account with this email already exists.',
            'staff.*.email.not_in' => 'This is the admin email. Each person needs their own.',
            'staff.*.email.distinct' => 'This email appears more than once.',
            'agreed_dpa.accepted' => 'You need to agree to the Data Processing Agreement to continue.',
            'confirms_patient_consent.accepted' => 'Please confirm the facility will obtain patient consent.',
            'accepted_terms.accepted' => 'You need to accept the Terms of Service.',
            'accepted_privacy.accepted' => 'You need to accept the Privacy Policy.',
            'admin_password_hash.required' => 'Set a password for the admin account.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'staff.*.name' => 'name',
            'staff.*.email' => 'email',
            'staff.*.phone' => 'phone',
            'staff.*.role' => 'role',
            'staff.*.department' => 'department',
            'notification_channels' => 'notification channel',
            'operating_days' => 'operating days',
            'opens_at' => 'opening time',
            'closes_at' => 'closing time',
            'sms_sender_id' => 'SMS sender ID',
        ];
    }

    /**
     * The screen that owns a validation error key such as "staff.0.email".
     */
    public static function stepForField(string $field): int
    {
        $root = strtok($field, '.');

        if ($root === 'admin_password_hash') {
            return 5;
        }

        foreach (array_keys(self::all()) as $step) {
            foreach (array_keys(self::rules($step)) as $key) {
                if (strtok($key, '.') === $root) {
                    return $step;
                }
            }
        }

        return 1;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private static function operationsRules(array $input): array
    {
        $timeRule = self::isTwentyFourHour($input) ? ['nullable'] : ['required'];

        return [
            'operating_days' => ['required', 'array', 'min:1'],
            'operating_days.*' => [Rule::enum(OperatingDay::class)],
            'is_24hr' => ['boolean'],
            'opens_at' => [...$timeRule, 'date_format:H:i'],
            'closes_at' => [...$timeRule, 'date_format:H:i'],
            'departments' => ['required', 'array', 'min:1'],
            'departments.*' => [Rule::enum(DepartmentType::class)],
            'doctors_count' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'consultation_rooms' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private static function adminRules(array $input): array
    {
        $hasStoredPassword = filled($input['admin_password_hash'] ?? null);

        return [
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_title' => ['required', 'string', 'max:100'],
            'admin_phone' => ['required', 'string', 'regex:'.self::PHONE_PATTERN],
            'admin_email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'admin_password' => [
                $hasStoredPassword ? 'nullable' : 'required',
                'confirmed',
                Password::min(8)->letters()->numbers(),
            ],
            'two_factor_enabled' => ['boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private static function staffRules(array $input): array
    {
        return [
            'staff' => ['nullable', 'array', 'max:20'],
            'staff.*.name' => ['required', 'string', 'max:255'],
            'staff.*.email' => [
                'required', 'email', 'max:255', 'distinct:ignore_case', 'unique:users,email',
                Rule::notIn([strtolower((string) ($input['admin_email'] ?? ''))]),
            ],
            'staff.*.phone' => ['required', 'string', 'regex:'.self::PHONE_PATTERN],
            'staff.*.role' => ['required', Rule::in(array_keys(UserRole::staffOptions()))],
            'staff.*.department' => ['nullable', Rule::in((array) ($input['departments'] ?? []))],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private static function isTwentyFourHour(array $input): bool
    {
        return filter_var($input['is_24hr'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }
}
