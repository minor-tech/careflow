<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'department_id' => Department::factory(),
            'name' => fake()->unique()->randomElement(['General Medicine', 'Pediatrics', 'Dermatology', 'Gynecology', 'Orthopedics', 'Cardiology']),
        ];
    }
}
