<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\VisitEventType;
use App\Enums\VisitSource;
use App\Enums\VisitStatus;
use App\Models\Patient;
use App\Models\RemoteRequest;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpRemoteQueue;
use Tests\TestCase;

/**
 * The patient's own live page while they are still at home: the same page as
 * anyone's, with a recommended arrival time and an "I've arrived" that is only a
 * claim until staff check them in.
 */
class TrackingAwaitingArrivalTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpRemoteQueue;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpRemoteQueue();
    }

    /**
     * A patient accepted from home, with the arrival window they were given (10:20 to 10:30).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function atHome(int $number = 3, array $attributes = []): Visit
    {
        $visit = Visit::factory()->inLineOf($this->wanjiku, $number)->create([
            'patient_id' => Patient::factory()->for($this->facility)->create(['name' => 'Kevin Otieno'])->id,
            'queue_number' => 60 + $number,
            'status' => VisitStatus::AwaitingArrival,
            'source' => VisitSource::Remote,
            'department_entered_at' => now()->subMinutes(30 - $number),
            ...$attributes,
        ]);

        RemoteRequest::factory()->for($this->facility)->accepted()->create([
            'visit_id' => $visit->id,
            'recommended_arrival_from' => now(config('careflow.timezone'))->setTime(10, 20)->utc(),
            'recommended_arrival_until' => now(config('careflow.timezone'))->setTime(10, 30)->utc(),
        ]);

        return $visit;
    }

    private function inLine(int $number, VisitStatus $status, string $name = 'Someone Else', ?User $doctor = null): Visit
    {
        return Visit::factory()->inLineOf($doctor ?? $this->wanjiku, $number)->create([
            'patient_id' => Patient::factory()->for($this->facility)->create(['name' => $name])->id,
            'queue_number' => 60 + $number,
            'status' => $status,
            'department_entered_at' => now()->subMinutes(30 - $number),
        ]);
    }

    public function test_the_page_shows_the_recommended_arrival_and_how_many_are_ahead_in_their_doctors_line(): void
    {
        $this->inLine(1, VisitStatus::Waiting);
        $this->inLine(2, VisitStatus::AwaitingArrival, 'On Their Way');
        $visit = $this->atHome(3);
        $this->inLine(4, VisitStatus::Waiting, 'Behind Me');
        $this->inLine(1, VisitStatus::Waiting, 'Other Doctors Patient', $this->kamau);

        $this->get(route('tracking.show', $visit->tracking_token))
            ->assertOk()
            ->assertSeeTextInOrder(['Recommended arrival', '10:20–10:30 AM'])
            ->assertSeeText('Your doctor: Dr. Wanjiku Mwangi')
            ->assertSeeText('2 patients ahead of you')
            ->assertSeeText('Waiting at home — come when it\'s your turn')
            ->assertSeeText('Estimated wait')
            ->assertSeeText('I\'ve arrived');
    }

    public function test_someone_who_holds_a_place_but_is_not_here_counts_as_ahead_of_those_behind_them(): void
    {
        $this->atHome(1);
        $behind = $this->inLine(2, VisitStatus::Waiting, 'Waiting Wanda');

        $this->get(route('tracking.show', $behind->tracking_token))->assertSeeText('1 patient ahead of you');
    }

    public function test_the_arrived_button_is_only_ever_offered_to_someone_awaiting_arrival(): void
    {
        foreach ([VisitStatus::Waiting, VisitStatus::Called, VisitStatus::InService, VisitStatus::Completed, VisitStatus::Cancelled] as $status) {
            $visit = $this->inLine(5, $status);

            $this->get(route('tracking.show', $visit->tracking_token))->assertDontSeeText('I\'ve arrived')->assertDontSeeText('Recommended arrival');
        }

        $this->get(route('tracking.show', $this->atHome()->tracking_token))->assertSeeText('I\'ve arrived');
    }

    public function test_tapping_arrived_only_tells_staff_and_moves_nobody_into_the_active_queue(): void
    {
        $visit = $this->atHome(2);
        $behind = $this->inLine(3, VisitStatus::Waiting, 'Waiting Wanda');
        $aheadBefore = $this->get(route('tracking.show', $behind->tracking_token))->assertSeeText('1 patient ahead of you');

        $this->post(route('tracking.arrived', $visit->tracking_token))->assertRedirect(route('tracking.show', $visit->tracking_token));

        $visit->refresh();
        $this->assertSame(VisitStatus::AwaitingArrival, $visit->status, 'Still not in the active queue.');
        $this->assertNull($visit->arrived_at);
        $this->assertNotNull($visit->arrival_signaled_at);

        $event = $visit->events()->sole();
        $this->assertSame(VisitEventType::ArrivalSignaled, $event->event);
        $this->assertNull($event->user_id, 'It was the patient, not a staff member.');

        // Nothing has moved for anyone else, and staff now see the claim.
        $this->get(route('tracking.show', $behind->tracking_token))->assertSeeText('1 patient ahead of you');
        $this->actingAs($this->receptionist)->get(route('queue.index'))->assertSeeText("Says they've arrived");
    }

    public function test_after_tapping_the_patient_is_told_to_see_the_front_desk_and_the_button_goes(): void
    {
        $visit = $this->atHome();

        $this->post(route('tracking.arrived', $visit->tracking_token));

        $this->get(route('tracking.show', $visit->tracking_token))
            ->assertDontSeeText('I\'ve arrived')
            ->assertSeeText('Please tell the front desk you are here')
            ->assertSeeText('Waiting for the front desk to check you in');
    }

    public function test_tapping_twice_or_when_it_means_nothing_does_nothing_more(): void
    {
        $visit = $this->atHome();
        $walkIn = $this->inLine(5, VisitStatus::Waiting);

        $this->post(route('tracking.arrived', $visit->tracking_token));
        $this->post(route('tracking.arrived', $visit->tracking_token));
        $this->post(route('tracking.arrived', $walkIn->tracking_token))->assertRedirect();

        $this->assertSame(1, VisitEvent::where('visit_id', $visit->id)->count());
        $this->assertNull($walkIn->fresh()->arrival_signaled_at);
        $this->assertCount(0, $walkIn->events);
    }

    public function test_it_works_from_the_live_page_without_a_session_because_the_link_is_the_credential(): void
    {
        $visit = $this->atHome();
        $this->app['env'] = 'production'; // Laravel skips the forgery check when running tests: this puts it back.

        // A page fetched without cookies can't carry a token, so this route is exempt, like the rating…
        $this->post(route('tracking.arrived', $visit->tracking_token))->assertRedirect(route('tracking.show', $visit->tracking_token));

        // …while another public form that isn't exempt still needs one.
        $this->post(route('remote.cancel', RemoteRequest::factory()->for($this->facility)->create()->public_code))->assertStatus(419);
    }

    public function test_an_unknown_or_wrong_days_link_cannot_be_tapped(): void
    {
        $this->post(route('tracking.arrived', 'abcdefghjkmnpq'))->assertNotFound();

        $old = $this->atHome();
        $old->forceFill(['created_at' => now()->subDays(2)])->save();

        $this->post(route('tracking.arrived', $old->tracking_token))->assertNotFound();
    }

    public function test_once_staff_check_them_in_the_banner_and_button_go_and_the_place_is_the_same(): void
    {
        $this->inLine(1, VisitStatus::Waiting);
        $visit = $this->atHome(2);
        $this->post(route('tracking.arrived', $visit->tracking_token));

        $this->actingAs($this->receptionist)->post(route('queue.check-in', $visit));

        $this->get(route('tracking.show', $visit->tracking_token))
            ->assertDontSeeText('Recommended arrival')
            ->assertDontSeeText('I\'ve arrived')
            ->assertDontSeeText('Please tell the front desk')
            ->assertSeeText('Waiting for Dr. Wanjiku Mwangi')
            ->assertSeeText('1 patient ahead of you');
    }

    public function test_the_live_fragment_the_page_polls_carries_the_banner_and_the_button(): void
    {
        $visit = $this->atHome();

        $html = $this->getJson(route('tracking.status', $visit->tracking_token))->assertOk()->json('html');

        $this->assertStringContainsString('Recommended arrival', $html);
        $this->assertStringContainsString('10:20–10:30 AM', $html);
        $this->assertStringContainsString(route('tracking.arrived', $visit->tracking_token), $html);
    }

    public function test_a_patient_almost_up_is_told_to_make_their_way_to_the_facility(): void
    {
        $visit = $this->atHome(1);

        $this->get(route('tracking.show', $visit->tracking_token))->assertSeeText("You're almost up — please make your way to the facility now.");
    }

    public function test_a_cancelled_no_show_is_told_so_in_the_ordinary_way(): void
    {
        $visit = $this->atHome();
        $this->actingAs($this->wanjiku)->post(route('queue.cancel', $visit));

        $this->get(route('tracking.show', $visit->tracking_token))->assertSeeText('Your visit was cancelled')->assertDontSeeText('I\'ve arrived');
    }

    public function test_it_never_shows_another_patient_or_a_doctors_total_workload(): void
    {
        foreach (range(1, 7) as $number) {
            $this->inLine($number, VisitStatus::Waiting, "Private Person {$number}");
        }
        $visit = $this->atHome(8);

        $this->get(route('tracking.show', $visit->tracking_token))
            ->assertDontSeeText('Private Person')
            ->assertDontSeeText('8 patients')
            ->assertDontSeeText('7 patients waiting')
            ->assertDontSeeText('patients waiting')
            ->assertDontSeeText('Kamau');
    }

    public function test_a_department_with_one_shared_queue_is_told_the_same_without_a_doctor(): void
    {
        $this->consultation->update(['requires_doctor_assignment' => false]);
        $visit = Visit::factory()->for($this->facility)->create([
            'patient_id' => Patient::factory()->for($this->facility)->create(['name' => 'Kevin Otieno'])->id,
            'department_id' => $this->consultation->id,
            'status' => VisitStatus::AwaitingArrival,
            'source' => VisitSource::Remote,
        ]);

        $this->get(route('tracking.show', $visit->tracking_token))
            ->assertSeeText('Waiting at home — come when it\'s your turn')
            ->assertDontSeeText('Your doctor')
            ->assertSeeText('I\'ve arrived');
    }
}
