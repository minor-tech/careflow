<?php

namespace Database\Factories;

use App\Enums\DepartmentType;
use App\Models\Department;
use App\Models\Facility;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = fake()->randomElement(DepartmentType::cases());

        return [
            'facility_id' => Facility::factory(),
            'name' => $type->label(),
            'type' => $type,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
