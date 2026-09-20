<?php

namespace Database\Factories;

use App\Enums\DataRole;
use App\Enums\FacilityStatus;
use App\Enums\FacilityType;
use App\Enums\NotificationChannel;
use App\Enums\OperatingDay;
use App\Models\Facility;
use App\Support\Counties;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Facility>
 */
class FacilityFactory extends Factory
{
    /**
     * Define the model's default state. Facilities are active by default so
     * that staff created by other factories can reach the dashboards; use
     * pendingReview() to mirror a fresh registration.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company().' Clinic',
            'facility_type' => FacilityType::Clinic,
            'license_number' => 'KMPDC-'.fake()->unique()->numerify('######'),
            'ownership_type' => null,
            'county' => fake()->randomElement(Counties::all()),
            'sub_county' => fake()->city(),
            'address' => fake()->streetAddress(),
            'phone' => '0712345678',
            'email' => fake()->unique()->companyEmail(),
            'operating_days' => [OperatingDay::Mon->value, OperatingDay::Tue->value, OperatingDay::Wed->value],
            'opens_at' => '08:00',
            'closes_at' => '17:00',
            'is_24hr' => false,
            'notification_channels' => [NotificationChannel::Email->value],
            'data_role' => DataRole::Controller,
            'dpa_accepted_at' => now(),
            'patient_consent_confirmed_at' => now(),
            'terms_accepted_at' => now(),
            'privacy_accepted_at' => now(),
            'signature_name' => fake()->name(),
            'status' => FacilityStatus::Active,
        ];
    }

    public function pendingReview(): static
    {
        return $this->state(fn (array $attributes) => ['status' => FacilityStatus::PendingReview]);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => ['status' => FacilityStatus::Suspended]);
    }

    public function rejected(string $reason = 'The license number could not be verified.'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => FacilityStatus::Rejected,
            'rejection_reason' => $reason,
            'reviewed_at' => now(),
        ]);
    }

    public function open24Hours(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_24hr' => true,
            'opens_at' => null,
            'closes_at' => null,
        ]);
    }
}
