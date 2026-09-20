<?php

namespace Tests\Feature\Models;

use App\Enums\FeedbackIssue;
use App\Models\Feedback;
use App\Models\Visit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeedbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_belongs_to_a_visit_its_patient_and_its_facility(): void
    {
        $feedback = Feedback::factory()->create();

        $this->assertTrue($feedback->visit->is(Visit::sole()));
        $this->assertTrue($feedback->patient->is($feedback->visit->patient));
        $this->assertTrue($feedback->facility->is($feedback->visit->facility));
        $this->assertTrue($feedback->visit->feedback->is($feedback));
    }

    public function test_the_issues_are_kept_as_a_list_and_can_be_absent(): void
    {
        $unhappy = Feedback::factory()->unhappy([FeedbackIssue::LongWait, FeedbackIssue::Billing])->create()->fresh();
        $happy = Feedback::factory()->create()->fresh();

        $this->assertSame(['long_wait', 'billing'], $unhappy->issues);
        $this->assertNull($happy->issues);
    }

    public function test_the_database_allows_only_one_feedback_per_visit(): void
    {
        $first = Feedback::factory()->create();

        $this->expectException(UniqueConstraintViolationException::class);
        Feedback::factory()->create(['visit_id' => $first->visit_id]);
    }

    public function test_it_records_when_it_was_given_and_is_never_updated(): void
    {
        $feedback = Feedback::factory()->create();

        $this->assertNotNull($feedback->created_at);
        $this->assertNull($feedback->updated_at);
    }
}
