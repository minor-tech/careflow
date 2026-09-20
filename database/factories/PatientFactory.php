<?php

namespace Database\Factories;

use App\Enums\Gender;
use App\Models\Facility;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Patient>
 */
class PatientFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'facility_id' => Facility::factory(),
            'name' => fake()->name(),
            'phone' => '+2547'.fake()->unique()->numerify('########'),
            'dob' => null,
            'gender' => null,
        ];
    }

    public function withDetails(): static
    {
        return $this->state(fn (array $attributes) => [
            'dob' => fake()->date('Y-m-d', '-10 years'),
            'gender' => fake()->randomElement(Gender::cases()),
        ]);
    }
}
