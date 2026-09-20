<?php

namespace Tests\Feature\Http\Controllers;

use App\Actions\RegisterPatientVisit;
use App\Enums\DepartmentType;
use App\Enums\VisitStatus;
use App\Models\Department;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TrackingControllerTest extends TestCase
{
    use RefreshDatabase;

    private const STEP_AWAY = "You don't need to wait here — we'll text you when you're about to be called.";

    private const ALMOST_UP = "You're almost up — please head back to the waiting area.";

    private Facility $facility;

    private Department $consultation;

    private Department $laboratory;

    protected function setUp(): void
    {
        parent::setUp();

        // Midday, so "minutes ago" never crosses the clinic's midnight.
        $this->travelTo(now(config('careflow.timezone'))->setTime(12, 0));

        $this->facility = Facility::factory()->create(['name' => 'Upendo Clinic', 'notification_channels' => ['sms']]);
        $this->consultation = Department::factory()->for($this->facility)->create(['name' => 'Consultation', 'type' => DepartmentType::Consultation]);
        $this->laboratory = Department::factory()->for($this->facility)->create(['name' => 'Laboratory', 'type' => DepartmentType::Laboratory]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function visit(string $name, int $number, VisitStatus $status = VisitStatus::Waiting, ?Department $department = null, array $attributes = []): Visit
    {
        return Visit::factory()->for($this->facility)->create([
            'department_id' => ($department ?? $this->consultation)->id,
            'patient_id' => Patient::factory()->for($this->facility)->create(['name' => $name, 'phone' => sprintf('+254711000%03d', $number)])->id,
            'queue_number' => $number,
            'status' => $status,
            'department_entered_at' => now()->subMinutes(100 - $number),
            ...$attributes,
        ]);
    }

    /**
     * Brian Kamau, with the given number of people waiting ahead of him.
     */
    private function brianWithAhead(int $ahead): Visit
    {
        for ($n = 1; $n <= $ahead; $n++) {
            $this->visit("Ahead Person{$n}", $n);
        }

        return $this->visit('Brian Kamau', $ahead + 1);
    }

    public function test_the_page_leads_with_patients_ahead_then_stage_then_the_estimate_then_the_journey(): void
    {
        config(['careflow.tracking.default_minutes_per_patient' => 5]);
        $this->visit('Someone Serving', 21, VisitStatus::InService);
        $brian = $this->brianWithAhead(6);

        $this->get(route('tracking.show', $brian->tracking_token))
            ->assertOk()
            ->assertSeeTextInOrder([
                'Upendo Clinic',
                'Brian Kamau',
                'Queue',
                '#7',
                'Currently serving:',
                '#21',
                '6',
                'patients ahead of you',
                'Waiting for Consultation',
                'Estimated wait: 25–35 minutes',
                'Further steps appear once your doctor decides them.',
            ]);
    }

    public function test_a_single_person_ahead_reads_in_the_singular(): void
    {
        $this->get(route('tracking.show', $this->brianWithAhead(1)->tracking_token))
            ->assertSeeText('patient ahead of you')
            ->assertDontSeeText('patients ahead of you');
    }

    public function test_the_first_in_line_is_told_they_are_next(): void
    {
        $this->get(route('tracking.show', $this->brianWithAhead(0)->tracking_token))
            ->assertSeeText("You're next")
            ->assertSeeText('No one is ahead of you')
            ->assertSeeText('Estimated wait: 5 minutes or less');
    }

    public function test_after_a_transfer_the_page_shows_the_number_in_the_new_department(): void
    {
        $visit = $this->visit('Brian Kamau', 27, department: $this->laboratory, attributes: ['department_queue_number' => 8]);

        $this->get(route('tracking.show', $visit->tracking_token))
            ->assertSeeInOrder(['Queue', 'L-8'])
            ->assertSeeText('Waiting for Laboratory');
    }

    public function test_the_page_needs_no_login(): void
    {
        $this->assertGuest();

        $this->get(route('tracking.show', $this->brianWithAhead(0)->tracking_token))->assertOk();
    }

    public function test_currently_serving_is_only_ever_a_bare_number(): void
    {
        $this->visit('Secret Servedperson', 21, VisitStatus::InService);
        $this->visit('Secret Waitingperson', 22);
        $this->visit('Secret Calledperson', 23, VisitStatus::Called);
        $brian = $this->visit('Brian Kamau', 24);

        $page = $this->get(route('tracking.show', $brian->tracking_token))->assertOk();
        $status = $this->getJson(route('tracking.status', $brian->tracking_token))->assertOk();

        $page->assertSeeText('Currently serving: #21');
        foreach ([$page->getContent(), $status->getContent()] as $everythingSent) {
            $this->assertStringNotContainsString('Secret', $everythingSent);
            $this->assertStringNotContainsString('Servedperson', $everythingSent);
            $this->assertStringNotContainsString('+254711000', $everythingSent);
        }
    }

    public function test_currently_serving_uses_the_number_style_of_the_patients_own_department(): void
    {
        $this->visit('Someone Serving', 5, VisitStatus::InService, $this->laboratory, ['department_queue_number' => 3]);
        $visit = $this->visit('Brian Kamau', 27, department: $this->laboratory, attributes: ['department_queue_number' => 4]);

        $this->get(route('tracking.show', $visit->tracking_token))->assertSeeText('Currently serving: L-3');
    }

    public function test_nobody_else_is_shown_when_no_one_is_being_served(): void
    {
        $this->get(route('tracking.show', $this->brianWithAhead(2)->tracking_token))
            ->assertDontSeeText('Currently serving');
    }

    public function test_only_people_in_the_same_department_and_facility_are_reported_as_being_served(): void
    {
        $this->visit('Elsewhere Person', 8, VisitStatus::InService, $this->laboratory);
        $otherFacility = Facility::factory()->create();
        Visit::factory()->for($otherFacility)->create(['department_id' => Department::factory()->for($otherFacility)->create()->id, 'status' => VisitStatus::InService, 'queue_number' => 9]);

        $this->get(route('tracking.show', $this->brianWithAhead(1)->tracking_token))
            ->assertDontSeeText('Currently serving');
    }

    /**
     * @return array<string, array{int}>
     */
    public static function farFromTheFront(): array
    {
        return ['three ahead' => [3], 'five ahead' => [5], 'twelve ahead' => [12]];
    }

    #[DataProvider('farFromTheFront')]
    public function test_while_more_than_two_are_ahead_they_are_told_they_can_step_away(int $ahead): void
    {
        $this->get(route('tracking.show', $this->brianWithAhead($ahead)->tracking_token))
            ->assertSeeText(self::STEP_AWAY)
            ->assertDontSeeText(self::ALMOST_UP);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function nearTheFront(): array
    {
        return ['two ahead' => [2], 'one ahead' => [1], 'next' => [0]];
    }

    #[DataProvider('nearTheFront')]
    public function test_from_two_ahead_down_they_are_told_to_head_back(int $ahead): void
    {
        $this->get(route('tracking.show', $this->brianWithAhead($ahead)->tracking_token))
            ->assertSeeText(self::ALMOST_UP)
            ->assertDontSeeText(self::STEP_AWAY);
    }

    public function test_the_line_changes_as_the_patients_ahead_drop_to_two(): void
    {
        $brian = $this->brianWithAhead(3);
        $this->assertStringContainsString(e("don't need to wait here"), $this->getJson(route('tracking.status', $brian->tracking_token))->json('html'));

        Visit::where('queue_number', 1)->update(['status' => VisitStatus::Called]);

        $html = $this->getJson(route('tracking.status', $brian->tracking_token))->json('html');
        $this->assertStringContainsString('head back to the waiting area', $html);
        $this->assertStringNotContainsString(e("don't need to wait here"), $html);
    }

    public function test_a_facility_that_sends_no_texts_does_not_promise_one(): void
    {
        $this->facility->update(['notification_channels' => ['email']]);

        $this->get(route('tracking.show', $this->brianWithAhead(5)->tracking_token))
            ->assertOk()
            ->assertSeeText('5')
            ->assertDontSeeText("we'll text you");
    }

    public function test_a_facility_that_sends_no_texts_still_says_when_they_are_almost_up(): void
    {
        $this->facility->update(['notification_channels' => []]);

        $this->get(route('tracking.show', $this->brianWithAhead(1)->tracking_token))
            ->assertSeeText(self::ALMOST_UP);
    }

    public function test_when_it_is_their_turn_the_page_says_so_without_a_queue_count(): void
    {
        $visit = $this->visit('Brian Kamau', 5, VisitStatus::Called);

        $this->get(route('tracking.show', $visit->tracking_token))
            ->assertSeeText("It's your turn — please go to Consultation")
            ->assertDontSeeText('ahead of you')
            ->assertDontSeeText('Estimated wait')
            ->assertDontSeeText('Currently serving')
            ->assertDontSeeText(self::STEP_AWAY);
    }

    public function test_while_being_seen_the_page_says_where(): void
    {
        $visit = $this->visit('Brian Kamau', 5, VisitStatus::InService);

        $this->get(route('tracking.show', $visit->tracking_token))
            ->assertSeeText('Being seen at Consultation')
            ->assertDontSeeText('ahead of you')
            ->assertDontSeeText('Estimated wait');
    }

    public function test_a_finished_visit_says_it_is_complete(): void
    {
        $visit = $this->visit('Brian Kamau', 5, VisitStatus::Completed);

        $this->get(route('tracking.show', $visit->tracking_token))
            ->assertSeeText('Your visit is complete')
            ->assertDontSeeText('ahead of you')
            ->assertDontSeeText('Further steps appear');
    }

    public function test_a_cancelled_visit_says_so(): void
    {
        $visit = $this->visit('Brian Kamau', 5, VisitStatus::Cancelled);

        $this->get(route('tracking.show', $visit->tracking_token))
            ->assertSeeText('Your visit was cancelled')
            ->assertDontSeeText('ahead of you');
    }

    public function test_an_unknown_link_shows_the_inactive_page_and_reveals_nothing(): void
    {
        $this->brianWithAhead(1);

        $this->get(route('tracking.show', 'abcdefghjkmnpq'))
            ->assertNotFound()
            ->assertSeeText("This link isn't active")
            ->assertDontSeeText('Brian Kamau')
            ->assertDontSeeText('Upendo Clinic');
    }

    public function test_a_link_that_is_not_shaped_like_a_token_is_not_found(): void
    {
        $this->get('/t/1')->assertNotFound();
        $this->get('/t/ABCDEFGHJKMNPQ')->assertNotFound();
    }

    public function test_the_link_stops_working_the_next_clinic_day(): void
    {
        $visit = $this->brianWithAhead(0);

        $this->travelTo(now(config('careflow.timezone'))->addDay()->setTime(9, 0));

        $this->get(route('tracking.show', $visit->tracking_token))->assertNotFound()->assertDontSeeText('Brian Kamau');
        $this->getJson(route('tracking.status', $visit->tracking_token))->assertNotFound();
    }

    public function test_a_visit_is_still_followable_late_in_the_evening_of_its_own_day(): void
    {
        $visit = $this->brianWithAhead(0);

        $this->travelTo(now(config('careflow.timezone'))->setTime(23, 30));

        $this->get(route('tracking.show', $visit->tracking_token))->assertOk();
    }

    public function test_the_page_is_kept_out_of_caches_search_engines_and_referrers(): void
    {
        $visit = $this->brianWithAhead(0);

        foreach ([route('tracking.show', $visit->tracking_token), route('tracking.status', $visit->tracking_token), route('tracking.show', 'abcdefghjkmnpq')] as $url) {
            $this->get($url)
                ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
                ->assertHeader('Referrer-Policy', 'no-referrer')
                ->assertHeader('Cache-Control', 'no-store, private');
        }

        $this->get(route('tracking.show', $visit->tracking_token))->assertSee('<meta name="robots" content="noindex, nofollow">', false);
    }

    public function test_the_status_answer_carries_the_live_part_of_the_page_and_whether_to_keep_asking(): void
    {
        $brian = $this->brianWithAhead(4);

        $this->getJson(route('tracking.status', $brian->tracking_token))
            ->assertOk()
            ->assertJsonPath('finished', false)
            ->assertJsonPath('html', fn (string $html) => str_contains($html, 'patients ahead of you') && str_contains($html, 'Brian Kamau'));
    }

    public function test_the_status_answer_moves_the_moment_staff_act(): void
    {
        $brian = $this->brianWithAhead(3);
        $link = route('tracking.status', $brian->tracking_token);

        $this->assertStringContainsString('>3<', $this->getJson($link)->json('html'));

        Visit::where('queue_number', 1)->update(['status' => VisitStatus::Called]);
        $this->assertStringContainsString('>2<', $this->getJson($link)->json('html'));

        Visit::where('queue_number', 2)->update(['status' => VisitStatus::Called]);
        $this->assertStringContainsString('>1<', $this->getJson($link)->json('html'));
    }

    #[DataProvider('endedStatuses')]
    public function test_the_status_answer_tells_the_page_to_stop_asking_once_the_visit_is_over(VisitStatus $status): void
    {
        $visit = $this->visit('Brian Kamau', 5, $status);

        $this->getJson(route('tracking.status', $visit->tracking_token))->assertJsonPath('finished', true);
    }

    /**
     * @return array<string, array{VisitStatus}>
     */
    public static function endedStatuses(): array
    {
        return ['completed' => [VisitStatus::Completed], 'cancelled' => [VisitStatus::Cancelled]];
    }

    public function test_an_unknown_link_gets_a_not_found_status_answer(): void
    {
        $this->getJson(route('tracking.status', 'abcdefghjkmnpq'))->assertNotFound()->assertJsonPath('finished', true);
    }

    public function test_the_page_asks_for_news_at_the_configured_interval(): void
    {
        config(['careflow.tracking.poll_seconds' => 9]);
        $brian = $this->brianWithAhead(1);

        $this->get(route('tracking.show', $brian->tracking_token))
            ->assertSee('seconds: 9', false)
            // The address is embedded as a JavaScript string, so its slashes are escaped.
            ->assertSee($brian->tracking_token.'\/status', false);
    }

    public function test_by_default_the_page_asks_every_5_to_10_seconds(): void
    {
        $this->assertGreaterThanOrEqual(5, config('careflow.tracking.poll_seconds'));
        $this->assertLessThanOrEqual(10, config('careflow.tracking.poll_seconds'));
    }

    public function test_a_page_is_limited_to_a_sensible_number_of_requests_a_minute(): void
    {
        $brian = $this->brianWithAhead(0);
        $link = route('tracking.status', $brian->tracking_token);

        foreach (range(1, 30) as $ignored) {
            $this->getJson($link)->assertOk();
        }

        $this->getJson($link)->assertTooManyRequests();
    }

    public function test_one_patients_limit_does_not_use_up_anothers(): void
    {
        $brian = $this->brianWithAhead(0);
        $wanjiru = $this->visit('Wanjiru Njeri', 50);

        foreach (range(1, 31) as $ignored) {
            $this->getJson(route('tracking.status', $brian->tracking_token));
        }

        $this->getJson(route('tracking.status', $wanjiru->tracking_token))->assertOk();
    }

    public function test_the_link_in_a_registration_leads_to_that_patients_page(): void
    {
        $receptionist = User::factory()->for($this->facility)->receptionist()->create();

        $visit = app(RegisterPatientVisit::class)->handle($receptionist, [
            'phone' => '+254712345678',
            'name' => 'Wanjiru Kamau',
            'department_id' => $this->consultation->id,
        ]);

        $this->get($visit->trackingUrl())->assertOk()->assertSeeText('Wanjiru Kamau');
    }
}
