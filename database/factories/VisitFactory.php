<?php

namespace Database\Factories;

use App\Enums\VisitStatus;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\Visit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Visit>
 */
class VisitFactory extends Factory
{
    /**
     * Define the model's default state. The patient belongs to the same
     * facility as the visit.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'facility_id' => Facility::factory(),
            'patient_id' => fn (array $attributes) => Patient::factory()->create(['facility_id' => $attributes['facility_id']])->id,
            'department_id' => null,
            'queue_number' => fake()->numberBetween(1, 200),
            'status' => VisitStatus::Waiting,
            'created_by' => null,
            'completed_at' => null,
        ];
    }

    public function called(): static
    {
        return $this->state(fn (array $attributes) => ['status' => VisitStatus::Called]);
    }

    public function inService(): static
    {
        return $this->state(fn (array $attributes) => ['status' => VisitStatus::InService]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => ['status' => VisitStatus::Cancelled]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => VisitStatus::Completed,
            'completed_at' => now(),
        ]);
    }
}
