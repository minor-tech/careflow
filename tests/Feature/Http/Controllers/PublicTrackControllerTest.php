<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\FacilityStatus;
use App\Enums\VisitStatus;
use App\Models\Facility;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PublicTrackControllerTest extends TestCase
{
    use RefreshDatabase;

    private const NOT_RECOGNIZED = 'Queue code or PIN not recognized.';

    private Facility $facility;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facility = Facility::factory()->create(['name' => 'Upendo Health Centre', 'slug' => 'upendo']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function visit(int $queueNumber = 27, string $pin = '7394', array $attributes = [], ?Facility $facility = null): Visit
    {
        return Visit::factory()->for($facility ?? $this->facility)->withAccessPin($pin)->create([
            'queue_number' => $queueNumber,
            ...$attributes,
        ]);
    }

    private function enter(string $code, string $pin, ?Facility $facility = null)
    {
        return $this->post(route('tracking.entry.attempt', ($facility ?? $this->facility)->slug), ['queue_code' => $code, 'pin' => $pin]);
    }

    public function test_the_form_is_the_facilitys_own_page_with_a_queue_code_and_a_pin_field(): void
    {
        $this->get('/upendo/track')
            ->assertOk()
            ->assertSeeText('Upendo Health Centre')
            ->assertSee('name="queue_code"', false)
            ->assertSee('name="pin"', false)
            ->assertSee('inputmode="numeric"', false)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_a_facility_that_does_not_exist_or_is_not_live_has_no_page(): void
    {
        $this->get('/nowhere/track')->assertNotFound();

        foreach ([FacilityStatus::PendingReview, FacilityStatus::Suspended, FacilityStatus::Rejected] as $status) {
            $facility = Facility::factory()->create(['status' => $status, 'slug' => 'closed-'.$status->value]);

            $this->get("/{$facility->slug}/track")->assertNotFound();
            $this->enter('V001', '1234', $facility)->assertNotFound();
        }
    }

    public function test_the_right_queue_code_and_pin_open_the_same_tracking_page_as_the_link(): void
    {
        $visit = $this->visit();

        $this->enter('V027', '7394')->assertRedirect(route('tracking.show', $visit->tracking_token));

        $this->get(route('tracking.show', $visit->tracking_token))->assertOk();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function waysToTypeTheQueueCode(): array
    {
        return [
            'as printed' => ['V027'],
            'lower case' => ['v027'],
            'without the padding' => ['V27'],
            'just the number' => ['27'],
            'with a hash' => ['#27'],
            'with stray spaces' => ['  v 027 '],
        ];
    }

    #[DataProvider('waysToTypeTheQueueCode')]
    public function test_the_queue_code_may_be_typed_loosely(string $typed): void
    {
        $visit = $this->visit();

        $this->enter($typed, '7394')->assertRedirect(route('tracking.show', $visit->tracking_token));
    }

    public function test_a_wrong_pin_is_turned_away_with_a_plain_message_and_the_pin_is_not_kept(): void
    {
        $this->visit();

        $this->enter('V027', '1111')
            ->assertRedirect(route('tracking.entry', 'upendo'))
            ->assertSessionHasErrors(['pin' => self::NOT_RECOGNIZED])
            ->assertSessionHasInput('queue_code', 'V027')
            ->assertSessionMissing('_old_input.pin');
    }

    public function test_a_queue_code_that_does_not_exist_gets_exactly_the_same_answer_as_a_wrong_pin(): void
    {
        $this->visit();

        $this->enter('V099', '7394')->assertSessionHasErrors(['pin' => self::NOT_RECOGNIZED]);
    }

    public function test_something_that_is_not_a_queue_code_is_told_so_and_does_not_count_as_a_guess(): void
    {
        $this->visit();

        $this->enter('hello', '7394')->assertSessionHasErrors('queue_code');

        foreach (range(1, 5) as $ignored) {
            $this->enter('hello', '7394');
        }

        $this->enter('V027', '7394')->assertRedirect(route('tracking.show', Visit::sole()->tracking_token));
    }

    public function test_the_pin_must_be_four_digits(): void
    {
        $this->visit();

        foreach (['', '123', '12345', 'abcd', '12 4'] as $pin) {
            $this->enter('V027', $pin)->assertSessionHasErrors('pin');
        }
    }

    public function test_another_facilitys_visit_with_the_same_queue_number_and_pin_does_not_open(): void
    {
        $other = Facility::factory()->create(['slug' => 'other-clinic']);
        $this->visit(facility: $other);

        $this->enter('V027', '7394')->assertSessionHasErrors(['pin' => self::NOT_RECOGNIZED]);
    }

    public function test_yesterdays_visit_does_not_open_even_with_the_right_pin(): void
    {
        $this->visit(attributes: ['created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);

        $this->enter('V027', '7394')->assertSessionHasErrors(['pin' => self::NOT_RECOGNIZED]);
    }

    public function test_a_completed_or_cancelled_visits_pin_no_longer_opens_it_even_if_guessed_right(): void
    {
        $this->visit(27, attributes: ['status' => VisitStatus::Completed]);
        $this->visit(28, attributes: ['status' => VisitStatus::Cancelled]);

        $this->enter('V027', '7394')->assertSessionHasErrors(['pin' => self::NOT_RECOGNIZED]);
        $this->enter('V028', '7394')->assertSessionHasErrors(['pin' => self::NOT_RECOGNIZED]);
    }

    public function test_the_original_link_still_works_for_a_completed_visit_so_feedback_is_unaffected(): void
    {
        $visit = $this->visit(attributes: ['status' => VisitStatus::Completed, 'completed_at' => now()]);

        $this->get(route('tracking.show', $visit->tracking_token))->assertOk();
    }

    public function test_a_visit_from_before_pins_existed_cannot_be_opened_by_typing(): void
    {
        Visit::factory()->for($this->facility)->create(['queue_number' => 27, 'access_pin_hash' => null]);

        $this->enter('V027', '0000')->assertSessionHasErrors(['pin' => self::NOT_RECOGNIZED]);
    }

    public function test_six_wrong_guesses_in_a_row_shut_the_door_and_then_even_the_right_pin_is_refused(): void
    {
        $this->visit();

        foreach (range(1, 5) as $guess) {
            $this->enter('V027', '000'.$guess)->assertSessionHasErrors(['pin' => self::NOT_RECOGNIZED]);
        }

        $this->enter('V027', '0006')->assertSessionHasErrors(['pin' => 'Too many attempts. Try again in a few minutes.']);
        $this->enter('V027', '7394')->assertSessionHasErrors(['pin' => 'Too many attempts. Try again in a few minutes.']);
    }

    public function test_the_lockout_ends_after_ten_minutes(): void
    {
        $this->visit();

        foreach (range(1, 5) as $guess) {
            $this->enter('V027', '000'.$guess);
        }

        $this->travel(11)->minutes();

        $this->enter('V027', '7394')->assertRedirect(route('tracking.show', Visit::sole()->tracking_token));
    }

    public function test_one_facilitys_lockout_does_not_lock_the_same_person_out_of_another_facility(): void
    {
        $this->visit();
        $other = Facility::factory()->create(['slug' => 'other-clinic']);
        $theirs = $this->visit(facility: $other);

        foreach (range(1, 5) as $guess) {
            $this->enter('V027', '000'.$guess);
        }

        $this->enter('V027', '7394', $other)->assertRedirect(route('tracking.show', $theirs->tracking_token));
    }

    public function test_a_success_clears_the_count_of_misses(): void
    {
        $this->visit();

        foreach (range(1, 4) as $guess) {
            $this->enter('V027', '000'.$guess);
        }
        $this->enter('V027', '7394')->assertRedirect();

        foreach (range(1, 4) as $guess) {
            $this->enter('V027', '000'.$guess)->assertSessionHasErrors(['pin' => self::NOT_RECOGNIZED]);
        }
    }

    public function test_a_crowd_of_different_addresses_cannot_grind_through_one_visits_pins(): void
    {
        $this->visit();

        // Every address stays within its own five guesses, so only the ceiling on one visit can stop this.
        foreach (range(1, 20) as $address) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$address}"])
                ->enter('V027', '0000')
                ->assertSessionHasErrors(['pin' => self::NOT_RECOGNIZED]);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.9.9'])
            ->enter('V027', '7394')
            ->assertSessionHasErrors(['pin' => self::NOT_RECOGNIZED]);

        RateLimiter::clear('track-visit:'.Visit::sole()->id);

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.9.9'])
            ->enter('V027', '7394')
            ->assertRedirect(route('tracking.show', Visit::sole()->tracking_token));
    }

    public function test_misses_against_one_visit_do_not_count_against_a_different_visit(): void
    {
        $this->visit(27);
        $other = $this->visit(28, '5555');

        foreach (range(1, 20) as $address) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$address}"])->enter('V027', '0000');
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.9.9'])
            ->enter('V028', '5555')
            ->assertRedirect(route('tracking.show', $other->tracking_token));
    }
}
