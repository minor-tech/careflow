<?php

namespace Tests\Concerns;

trait RegistersFacilities
{
    public const ADMIN_PASSWORD = 'Sup3rSecret99';

    /**
     * A valid submission for one wizard screen, with any overrides applied.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function stepPayload(int $step, array $overrides = []): array
    {
        $payload = match ($step) {
            1 => [
                'name' => 'Upendo Health Centre',
                'facility_type' => 'clinic',
                'license_number' => 'KMPDC-123456',
                'ownership_type' => 'private',
            ],
            2 => [
                'county' => 'Nairobi',
                'sub_county' => 'Westlands',
                'address' => 'Opposite Sarit Centre',
                'latitude' => '-1.2612345',
                'longitude' => '36.8023456',
            ],
            3 => [
                'phone' => '0712 345 678',
                'alt_phone' => '',
                'email' => 'info@upendo.test',
                'website' => 'https://upendo.test',
            ],
            4 => [
                'operating_days' => ['mon', 'tue', 'wed'],
                'is_24hr' => '0',
                'opens_at' => '08:00',
                'closes_at' => '17:00',
                'departments' => ['reception', 'consultation', 'laboratory'],
                'doctors_count' => '4',
                'consultation_rooms' => '3',
            ],
            5 => [
                'admin_name' => 'Wanjiru Kamau',
                'admin_title' => 'Facility Manager',
                'admin_phone' => '0722 000 111',
                'admin_email' => 'wanjiru@upendo.test',
                'admin_password' => self::ADMIN_PASSWORD,
                'admin_password_confirmation' => self::ADMIN_PASSWORD,
                'two_factor_enabled' => '1',
            ],
            6 => [
                'staff' => [
                    [
                        'name' => 'Otieno Odhiambo',
                        'email' => 'otieno@upendo.test',
                        'phone' => '0733 222 333',
                        'role' => 'doctor',
                        'department' => 'consultation',
                    ],
                ],
            ],
            7 => [
                'notification_channels' => ['sms', 'email'],
                'sms_sender_id' => 'UPENDO',
            ],
            8 => [
                'data_role' => 'controller',
                'odpc_registration_no' => '',
                'agreed_dpa' => '1',
                'confirms_patient_consent' => '1',
            ],
            9 => [
                'accepted_terms' => '1',
                'accepted_privacy' => '1',
                'signature' => 'Wanjiru Kamau',
            ],
        };

        return [...$payload, ...$overrides];
    }

    /**
     * Complete screens 1 to $through through the real endpoints, so the session
     * draft is built exactly as a browser would build it.
     *
     * @param  array<int, array<string, mixed>>  $overrides  Per-screen overrides, keyed by screen number.
     */
    protected function completeSteps(int $through, array $overrides = []): void
    {
        for ($step = 1; $step <= $through; $step++) {
            $this->post(route('facility.register.save', $step), $this->stepPayload($step, $overrides[$step] ?? []))
                ->assertSessionHasNoErrors();
        }
    }
}
