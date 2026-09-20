<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\DepartmentWaitEstimate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DepartmentWaitEstimate>
 */
class DepartmentWaitEstimateFactory extends Factory
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
            'avg_minutes' => fake()->randomFloat(1, 3, 30),
            'sample_size' => fake()->numberBetween(5, 200),
            'calculated_at' => now(),
        ];
    }
}
