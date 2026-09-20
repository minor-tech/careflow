<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\VisitEventType;
use App\Enums\VisitSource;
use App\Enums\VisitStatus;
use App\Models\Department;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpRemoteQueue;
use Tests\TestCase;

/**
 * What staff do with a patient accepted from home: check them in when they
 * arrive, and decide what to do when the queue reaches someone who hasn't.
 */
class QueueRemoteArrivalTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpRemoteQueue;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpRemoteQueue();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function visitFor(User $doctor, string $name, int $number, VisitStatus $status, array $attributes = []): Visit
    {
        return Visit::factory()->inLineOf($doctor, $number)->create([
            'patient_id' => Patient::factory()->for($this->facility)->create(['name' => $name])->id,
            'queue_number' => 60 + $number,
            'status' => $status,
            'source' => $status === VisitStatus::AwaitingArrival ? VisitSource::Remote : VisitSource::WalkIn,
            // Everyone joined a few minutes apart, in the order of their numbers.
            'department_entered_at' => now()->subMinutes(40 - 3 * $number),
            ...$attributes,
        ]);
    }

    private function awaiting(User $doctor, string $name, int $number, array $attributes = []): Visit
    {
        return $this->visitFor($doctor, $name, $number, VisitStatus::AwaitingArrival, $attributes);
    }

    private function waiting(User $doctor, string $name, int $number, array $attributes = []): Visit
    {
        return $this->visitFor($doctor, $name, $number, VisitStatus::Waiting, $attributes);
    }

    private function board(?User $as = null)
    {
        return $this->actingAs($as ?? $this->wanjiku)->get(route('queue.index'));
    }

    public function test_checking_in_makes_them_a_waiting_patient_who_keeps_the_place_they_were_given(): void
    {
        $visit = $this->awaiting($this->wanjiku, 'Kevin Otieno', 2);
        $placeHeld = $visit->department_entered_at->copy();

        $this->actingAs($this->receptionist)
            ->post(route('queue.check-in', $visit))
            ->assertRedirect(route('queue.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Kevin Otieno is checked in and waiting.');

        $visit->refresh();
        $this->assertSame(VisitStatus::Waiting, $visit->status);
        $this->assertTrue($visit->arrived_at->isSameMinute(now()));
        $this->assertNull($visit->arrival_grace_started_at);
        $this->assertTrue($visit->department_entered_at->equalTo($placeHeld), 'Their place in the line is the one they were given when accepted.');

        $event = $visit->events()->sole();
        $this->assertSame(VisitEventType::CheckedIn, $event->event);
        $this->assertSame('checked_in', $event->getRawOriginal('event'));
        $this->assertSame($this->receptionist->id, $event->user_id);
        $this->assertSame($this->consultation->id, $event->department_id);
    }

    public function test_from_then_on_it_behaves_exactly_like_a_walk_in_in_the_queue(): void
    {
        $visit = $this->awaiting($this->wanjiku, 'Kevin Otieno', 1);
        $this->actingAs($this->receptionist)->post(route('queue.check-in', $visit));

        $this->actingAs($this->wanjiku)->post(route('queue.call', $visit))->assertSessionHasNoErrors();
        $this->actingAs($this->wanjiku)->post(route('queue.start', $visit))->assertSessionHasNoErrors();
        $this->actingAs($this->wanjiku)->post(route('queue.complete', $visit))->assertSessionHasNoErrors();

        $this->assertSame(VisitStatus::Completed, $visit->fresh()->status);
        $this->assertSame(
            [VisitEventType::CheckedIn, VisitEventType::Called, VisitEventType::Started, VisitEventType::Completed],
            $visit->events->pluck('event')->all(),
        );
    }

    public function test_a_patient_who_is_not_here_yet_cannot_be_called_or_started(): void
    {
        $visit = $this->awaiting($this->wanjiku, 'Kevin Otieno', 1);

        foreach (['queue.call', 'queue.start', 'queue.complete'] as $route) {
            $this->actingAs($this->wanjiku)->post(route($route, $visit))->assertSessionHasErrors('queue');
        }

        $this->assertSame(VisitStatus::AwaitingArrival, $visit->fresh()->status);
        $this->assertCount(0, $visit->events);
    }

    public function test_checking_in_twice_or_checking_in_someone_who_is_not_awaiting_is_refused(): void
    {
        $visit = $this->awaiting($this->wanjiku, 'Kevin Otieno', 1);
        $walkIn = $this->waiting($this->wanjiku, 'Walk In', 2);

        $this->actingAs($this->receptionist)->post(route('queue.check-in', $visit));
        $this->actingAs($this->receptionist)->post(route('queue.check-in', $visit))
            ->assertSessionHasErrors(['queue' => 'That patient is not waiting to arrive: they may already be checked in, or the visit is over.']);
        $this->actingAs($this->receptionist)->post(route('queue.check-in', $walkIn))->assertSessionHasErrors('queue');

        $this->assertCount(1, $visit->events, 'Only the one check-in is logged.');
        $this->assertCount(0, $walkIn->events);
    }

    public function test_the_front_desk_can_check_in_anyone_expected_whichever_department_they_were_accepted_into(): void
    {
        $visit = $this->awaiting($this->kamau, 'Kevin Otieno', 1);

        // The receptionist works in Reception, the patient is in Dr. Kamau's consultation line.
        $this->assertNotSame($this->receptionist->department_id, $visit->department_id);
        $this->actingAs($this->receptionist)->post(route('queue.check-in', $visit))->assertSessionHasNoErrors();

        $this->assertSame(VisitStatus::Waiting, $visit->fresh()->status);
    }

    public function test_the_patients_own_doctor_and_admins_can_check_in_but_another_doctor_cannot(): void
    {
        $mine = $this->awaiting($this->wanjiku, 'Mine', 1);
        $theirs = $this->awaiting($this->wanjiku, 'Theirs', 2);

        $this->actingAs($this->kamau)->post(route('queue.check-in', $mine))->assertForbidden();
        $this->actingAs($this->wanjiku)->post(route('queue.check-in', $mine))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('queue.check-in', $theirs))->assertSessionHasNoErrors();

        $this->assertSame(VisitStatus::Waiting, $theirs->fresh()->status);
    }

    public function test_another_facilitys_staff_and_guests_cannot_check_anyone_in(): void
    {
        $visit = $this->awaiting($this->wanjiku, 'Kevin Otieno', 1);
        $outsider = User::factory()->for(Facility::factory()->create())->receptionist()->create();

        $this->actingAs($outsider)->post(route('queue.check-in', $visit))->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->post(route('queue.check-in', $visit))->assertRedirect(route('login'));

        $this->assertSame(VisitStatus::AwaitingArrival, $visit->fresh()->status);
    }

    public function test_a_no_show_can_be_cancelled_by_the_front_desk_the_doctor_or_an_admin(): void
    {
        foreach ([$this->receptionist, $this->wanjiku, $this->admin] as $index => $staff) {
            $visit = $this->awaiting($this->wanjiku, "No Show {$index}", 10 + $index);

            $this->actingAs($staff)->post(route('queue.cancel', $visit))->assertSessionHasNoErrors();

            $this->assertSame(VisitStatus::Cancelled, $visit->fresh()->status);
            $this->assertSame(VisitEventType::Cancelled, $visit->events()->sole()->event);
            $this->assertSame($staff->id, $visit->events()->sole()->user_id);
        }
    }

    public function test_the_board_shows_who_says_they_have_arrived_and_offers_the_check_in(): void
    {
        $this->waiting($this->wanjiku, 'Here Already', 1);
        $visit = $this->awaiting($this->wanjiku, 'Kevin Otieno', 2, ['arrival_signaled_at' => now()->subMinute()]);

        $this->board()
            ->assertOk()
            ->assertSeeInOrder(['Kevin Otieno', "Says they've arrived", 'Awaiting check-in'])
            ->assertSee(route('queue.check-in', $visit), false)
            // A claim is not a check-in: the grace-period prompt is for someone who has not said anything.
            ->assertDontSeeText('grace period');
    }

    public function test_the_front_desk_sees_everyone_on_their_way_with_those_who_say_they_are_here_first(): void
    {
        $this->awaiting($this->wanjiku, 'Not Yet Nadia', 1);
        $this->awaiting($this->kamau, 'Says Sam', 1, ['arrival_signaled_at' => now()->subMinutes(2)]);
        $this->awaiting($this->kamau, 'Says Sasha', 2, ['arrival_signaled_at' => now()->subMinute()]);

        $this->board($this->receptionist)
            ->assertOk()
            ->assertSeeInOrder(['On their way', 'Says Sam', "Says they've arrived", 'Says Sasha', 'Not Yet Nadia', 'Not here yet']);

        // Doctors and nurses see their own lines, not the front desk's panel of everyone on their way.
        $this->board($this->kamau)->assertDontSee('id="expected-heading"', false)->assertDontSeeText('Not Yet Nadia')->assertSeeText('Says Sam');
    }

    public function test_someone_already_in_the_list_on_screen_is_not_listed_a_second_time_in_the_panel(): void
    {
        $this->awaiting($this->wanjiku, 'Kevin Otieno', 1);

        // The doctor's own screen has no panel; the admin looking at that same line sees Kevin once, in the list, with his actions.
        $html = $this->actingAs($this->admin)->get(route('queue.index', ['department' => $this->consultation->id]))->getContent();

        $this->assertSame(1, substr_count($html, 'Kevin Otieno</p>'));
        $this->assertStringNotContainsString('id="expected-heading"', $html);

        // Looking at another department instead, the panel is how the front desk finds him.
        $this->actingAs($this->receptionist)->get(route('queue.index'))->assertSee('id="expected-heading"', false)->assertSeeText('Kevin Otieno');
    }

    public function test_a_patient_next_in_line_who_is_not_here_gets_the_no_show_prompt_with_the_grace_period_left(): void
    {
        $this->facility->update(['remote_queue_grace_minutes' => 10]);
        $visit = $this->awaiting($this->wanjiku, 'Kevin Otieno', 1);
        $this->waiting($this->wanjiku, 'Behind Bella', 2);

        $this->board()
            ->assertSeeTextInOrder(['Kevin Otieno', 'Not yet checked in', 'grace period: 10 min remaining'])
            ->assertSee(route('queue.wait', $visit), false)
            ->assertSee(route('queue.skip', $visit), false)
            ->assertSee(route('queue.cancel', $visit), false)
            ->assertSeeText('Cancel request');

        $this->travelTo(now()->addMinutes(4));
        $this->board()->assertSeeText('grace period: 6 min remaining');

        $this->travelTo(now()->addMinutes(7));
        $this->board()->assertSeeText('grace period is over');
    }

    public function test_the_grace_period_length_is_the_facilitys_setting_not_a_fixed_number(): void
    {
        $this->facility->update(['remote_queue_grace_minutes' => 3]);
        $this->awaiting($this->wanjiku, 'Kevin Otieno', 1);

        $this->board()->assertSeeText('grace period: 3 min remaining');
    }

    public function test_the_clock_starts_when_staff_are_first_shown_them_as_next_and_polling_never_moves_it(): void
    {
        $visit = $this->awaiting($this->wanjiku, 'Kevin Otieno', 1);
        $this->assertNull($visit->arrival_grace_started_at);

        $this->board();
        $started = $visit->fresh()->arrival_grace_started_at;
        $this->assertNotNull($started);

        $this->travelTo(now()->addMinutes(3));
        $this->actingAs($this->wanjiku)->get(route('queue.index'), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();

        $this->assertTrue($visit->fresh()->arrival_grace_started_at->equalTo($started));
    }

    public function test_someone_who_is_not_next_is_not_prompted_and_their_clock_does_not_start(): void
    {
        $this->waiting($this->wanjiku, 'Ahead Adam', 1);
        $behind = $this->awaiting($this->wanjiku, 'Kevin Otieno', 2);

        $this->board()->assertDontSeeText('grace period')->assertDontSeeText('Not yet checked in');

        $this->assertNull($behind->fresh()->arrival_grace_started_at);
    }

    public function test_every_doctors_line_has_its_own_next_patient(): void
    {
        $this->waiting($this->wanjiku, 'Wanjiku Front', 1);
        $this->awaiting($this->wanjiku, 'Wanjiku Second', 2);
        $kamauFront = $this->awaiting($this->kamau, 'Kamau Front', 1);

        $this->board($this->kamau)->assertSeeText('grace period: 10 min remaining');
        $this->assertNotNull($kamauFront->fresh()->arrival_grace_started_at);
    }

    public function test_waiting_gives_them_a_fresh_grace_period(): void
    {
        $visit = $this->awaiting($this->wanjiku, 'Kevin Otieno', 1);
        $this->board();
        $this->travelTo(now()->addMinutes(8));
        $this->board()->assertSeeText('grace period: 2 min remaining');

        $this->actingAs($this->wanjiku)->post(route('queue.wait', $visit))->assertRedirect(route('queue.index'))->assertSessionHasNoErrors();

        $this->board()->assertSeeText('grace period: 10 min remaining');
        $this->assertSame(VisitStatus::AwaitingArrival, $visit->fresh()->status);
    }

    public function test_skipping_moves_them_behind_the_next_person_who_is_here_without_destroying_the_visit(): void
    {
        $noShow = $this->awaiting($this->wanjiku, 'Kevin Otieno', 1);
        $next = $this->waiting($this->wanjiku, 'Next Nia', 2);
        $last = $this->waiting($this->wanjiku, 'Last Leo', 3);
        $this->board();

        $this->actingAs($this->wanjiku)
            ->post(route('queue.skip', $noShow))
            ->assertRedirect(route('queue.index'))
            ->assertSessionHas('success', 'Kevin Otieno was moved behind the next patient. If they arrive they can still be checked in.');

        $noShow->refresh();
        $this->assertSame(VisitStatus::AwaitingArrival, $noShow->status);
        $this->assertNull($noShow->arrival_grace_started_at, 'Their grace period starts afresh when they are next again.');
        $this->assertTrue($noShow->department_entered_at->greaterThan($next->department_entered_at));
        $this->assertTrue($noShow->department_entered_at->lessThan($last->department_entered_at));

        $skipped = $noShow->events()->sole();
        $this->assertSame(VisitEventType::Skipped, $skipped->event);
        $this->assertSame($this->wanjiku->id, $skipped->user_id);

        $this->board()->assertSeeInOrder(['Next Nia', 'Kevin Otieno', 'Last Leo']);
    }

    public function test_a_skipped_patient_who_then_arrives_can_still_be_checked_in_and_waits_where_they_now_are(): void
    {
        $noShow = $this->awaiting($this->wanjiku, 'Kevin Otieno', 1);
        $this->waiting($this->wanjiku, 'Next Nia', 2);
        $this->waiting($this->wanjiku, 'Last Leo', 3);
        $this->actingAs($this->wanjiku)->post(route('queue.skip', $noShow));

        $this->actingAs($this->receptionist)->post(route('queue.check-in', $noShow))->assertSessionHasNoErrors();

        $this->assertSame(VisitStatus::Waiting, $noShow->fresh()->status);
        $this->board()->assertSeeInOrder(['Next Nia', 'Kevin Otieno', 'Last Leo']);
    }

    public function test_the_person_who_went_first_is_now_next_and_the_skipped_one_is_not(): void
    {
        $noShow = $this->awaiting($this->wanjiku, 'Kevin Otieno', 1);
        $this->waiting($this->wanjiku, 'Next Nia', 2);
        $this->actingAs($this->wanjiku)->post(route('queue.skip', $noShow));

        // Nia is now first: nobody is prompted, because the front of the line is here.
        $this->board()->assertDontSeeText('grace period');
        $this->assertNull($noShow->fresh()->arrival_grace_started_at);
    }

    public function test_skipping_is_refused_when_nobody_who_is_here_is_behind_them(): void
    {
        $noShow = $this->awaiting($this->wanjiku, 'Kevin Otieno', 1);
        $this->awaiting($this->wanjiku, 'Also Away', 2);
        // Only ever counts the same doctor's line, and only people who are here.
        $this->waiting($this->kamau, 'Other Doctors Patient', 1);

        $this->actingAs($this->wanjiku)->post(route('queue.skip', $noShow))
            ->assertSessionHasErrors('queue');

        $this->assertCount(0, $noShow->events);
        $this->assertTrue($noShow->fresh()->department_entered_at->equalTo($noShow->department_entered_at));
    }

    public function test_wait_and_skip_only_apply_to_someone_who_is_awaiting_arrival(): void
    {
        $walkIn = $this->waiting($this->wanjiku, 'Walk In', 1);

        $this->actingAs($this->wanjiku)->post(route('queue.wait', $walkIn))->assertSessionHasErrors('queue');
        $this->actingAs($this->wanjiku)->post(route('queue.skip', $walkIn))->assertSessionHasErrors('queue');
    }

    public function test_only_staff_who_may_work_the_patient_can_wait_or_skip_them(): void
    {
        $noShow = $this->awaiting($this->wanjiku, 'Kevin Otieno', 1);
        $this->waiting($this->wanjiku, 'Next Nia', 2);

        $this->actingAs($this->kamau)->post(route('queue.wait', $noShow))->assertForbidden();
        $this->actingAs($this->kamau)->post(route('queue.skip', $noShow))->assertForbidden();
    }

    public function test_a_patient_who_has_been_at_the_facility_is_timed_from_when_they_arrived_not_from_being_accepted(): void
    {
        $visit = $this->awaiting($this->wanjiku, 'Kevin Otieno', 1, ['department_entered_at' => now()->subMinutes(90)]);
        $this->actingAs($this->receptionist)->post(route('queue.check-in', $visit));
        $this->travelTo(now()->addMinutes(5));

        $this->board()->assertSeeText('5 min')->assertDontSeeText('95 min');
    }

    public function test_an_ordinary_walk_in_row_is_unchanged(): void
    {
        $this->waiting($this->wanjiku, 'Walk In Wanda', 1);

        $this->board()
            ->assertSeeText('Walk In Wanda')
            ->assertDontSeeText('On their way')
            ->assertDontSeeText('Check In')
            ->assertDontSeeText('grace period');
    }

    public function test_the_stat_cards_do_not_count_someone_who_is_not_here_as_waiting(): void
    {
        $this->waiting($this->wanjiku, 'Here', 1);
        $this->awaiting($this->wanjiku, 'Not Here', 2);

        $this->board()->assertViewHas('counts', ['waiting' => 1, 'awaiting' => 1, 'called' => 0, 'in_service' => 0]);
    }

    public function test_a_department_without_doctor_lines_has_the_same_arrival_flow_in_its_shared_queue(): void
    {
        $this->consultation->update(['requires_doctor_assignment' => false]);
        $lab = Department::factory()->for($this->facility)->create(['name' => 'Laboratory']);
        $tech = User::factory()->for($this->facility)->nurse()->create(['department_id' => $lab->id]);
        $noShow = Visit::factory()->for($this->facility)->create([
            'patient_id' => Patient::factory()->for($this->facility)->create(['name' => 'Kevin Otieno'])->id,
            'department_id' => $lab->id,
            'status' => VisitStatus::AwaitingArrival,
            'source' => VisitSource::Remote,
            'queue_number' => 5,
            'department_entered_at' => now()->subMinutes(20),
        ]);
        Visit::factory()->for($this->facility)->create([
            'patient_id' => Patient::factory()->for($this->facility)->create(['name' => 'Shared Sam'])->id,
            'department_id' => $lab->id,
            'queue_number' => 6,
            'department_entered_at' => now()->subMinutes(10),
        ]);

        $this->actingAs($tech)->get(route('queue.index'))->assertSeeText('grace period: 10 min remaining');
        $this->actingAs($tech)->post(route('queue.skip', $noShow))->assertSessionHasNoErrors();
    }
}
