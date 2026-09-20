<?php

namespace Database\Factories;

use App\Enums\VisitEventType;
use App\Models\Visit;
use App\Models\VisitEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VisitEvent>
 */
class VisitEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'visit_id' => Visit::factory(),
            'department_id' => null,
            'event' => VisitEventType::Registered,
            'user_id' => null,
        ];
    }
}
