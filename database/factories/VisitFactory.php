<?php

namespace Database\Factories;

use App\Enums\VisitStatus;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

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

    /**
     * A visit that can be followed by typing its queue code and this PIN.
     */
    public function withAccessPin(string $pin = '1234'): static
    {
        return $this->state(fn (array $attributes) => ['access_pin_hash' => Hash::make($pin)]);
    }

    /**
     * A patient in this doctor's own line, at the given place in it.
     */
    public function inLineOf(User $doctor, int $number = 1): static
    {
        return $this->state(fn (array $attributes) => [
            'facility_id' => $doctor->facility_id,
            'department_id' => $doctor->department_id,
            'assigned_doctor_id' => $doctor->id,
            'doctor_queue_number' => $number,
        ]);
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
