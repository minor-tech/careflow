<?php

namespace Database\Factories;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\PatientNotification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PatientNotification>
 */
class PatientNotificationFactory extends Factory
{
    /**
     * Define the model's default state. The patient belongs to the same
     * facility as the notification.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'facility_id' => Facility::factory(),
            'patient_id' => fn (array $attributes) => Patient::factory()->create(['facility_id' => $attributes['facility_id']])->id,
            'visit_id' => null,
            'channel' => NotificationChannel::Sms,
            'message' => fake()->sentence(),
            'status' => NotificationStatus::Queued,
            'provider_response' => null,
            'sent_at' => null,
        ];
    }

    public function sent(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => NotificationStatus::Sent,
            'provider_response' => '{"SMSMessageData":{"Message":"Sent to 1/1"}}',
            'sent_at' => now(),
        ]);
    }

    public function failed(string $reason = 'InvalidSenderId'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => NotificationStatus::Failed,
            'provider_response' => $reason,
        ]);
    }
}
