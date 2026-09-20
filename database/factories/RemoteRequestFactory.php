<?php

namespace Database\Factories;

use App\Enums\RemoteRequestSource;
use App\Enums\RemoteRequestStatus;
use App\Models\Facility;
use App\Models\RemoteRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RemoteRequest>
 */
class RemoteRequestFactory extends Factory
{
    /**
     * Define the model's default state: a request from home, still waiting for review.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'facility_id' => Facility::factory(),
            'name' => fake()->name(),
            'phone' => '+2547'.fake()->unique()->numerify('########'),
            'source' => RemoteRequestSource::Remote,
            'requested_arrival' => '10:30:00',
            'status' => RemoteRequestStatus::Pending,
        ];
    }

    /**
     * Someone already in the building, registering on their own phone.
     */
    public function selfCheckin(): static
    {
        return $this->state(fn (array $attributes) => ['source' => RemoteRequestSource::SelfCheckin, 'requested_arrival' => now(config('careflow.timezone'))->format('H:i:s')]);
    }

    public function declined(?string $reason = null): static
    {
        return $this->state(fn (array $attributes) => ['status' => RemoteRequestStatus::Declined, 'declined_reason' => $reason, 'reviewed_at' => now()]);
    }

    public function accepted(): static
    {
        return $this->state(fn (array $attributes) => ['status' => RemoteRequestStatus::Accepted, 'reviewed_at' => now()]);
    }
}
