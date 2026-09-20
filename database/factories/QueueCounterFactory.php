<?php

namespace Database\Factories;

use App\Models\Facility;
use App\Models\QueueCounter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QueueCounter>
 */
class QueueCounterFactory extends Factory
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
            'date' => now()->toDateString(),
            'last_number' => 0,
        ];
    }
}
