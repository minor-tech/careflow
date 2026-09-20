<?php

namespace Database\Factories;

use App\Enums\FeedbackIssue;
use App\Enums\VisitStatus;
use App\Models\Feedback;
use App\Models\Visit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Feedback>
 */
class FeedbackFactory extends Factory
{
    /**
     * Define the model's default state: a happy patient, about a completed
     * visit, with the patient and facility taken from that visit.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'visit_id' => Visit::factory()->state(['status' => VisitStatus::Completed]),
            'patient_id' => fn (array $attributes) => Visit::find($attributes['visit_id'])->patient_id,
            'facility_id' => fn (array $attributes) => Visit::find($attributes['visit_id'])->facility_id,
            'rating' => fake()->numberBetween(4, 5),
            'issues' => null,
            'comment' => null,
        ];
    }

    /**
     * An unhappy patient (3 stars or fewer), who names what went wrong.
     *
     * @param  list<FeedbackIssue>  $issues
     */
    public function unhappy(array $issues = [FeedbackIssue::LongWait], int $rating = 2): static
    {
        return $this->state(fn (array $attributes) => [
            'rating' => $rating,
            'issues' => array_map(fn (FeedbackIssue $issue) => $issue->value, $issues),
        ]);
    }
}
