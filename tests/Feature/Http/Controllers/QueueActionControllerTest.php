<?php

namespace Tests\Feature\Http\Controllers;

use App\Actions\RegisterPatientVisit;
use App\Enums\DepartmentType;
use App\Enums\UserRole;
use App\Enums\VisitEventType;
use App\Enums\VisitStatus;
use App\Models\Department;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QueueActionControllerTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    private Department $consultation;

    private Department $laboratory;

    private User $doctor;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facility = Facility::factory()->create();
        $this->consultation = Department::factory()->for($this->facility)->create(['name' => 'Consultation', 'type' => DepartmentType::Consultation]);
        $this->laboratory = Department::factory()->for($this->facility)->create(['name' => 'Laboratory', 'type' => DepartmentType::Laboratory]);
        $this->doctor = User::factory()->for($this->facility)->doctor()->create(['department_id' => $this->consultation->id]);
        $this->admin = User::factory()->for($this->facility)->create();
    }

    private function visit(?Department $department, VisitStatus $status = VisitStatus::Waiting, ?Facility $facility = null): Visit
    {
        $facility ??= $this->facility;

        return Visit::factory()->for($facility)->create([
            'department_id' => $department?->id,
            'patient_id' => Patient::factory()->for($facility)->create()->id,
            'queue_number' => 19,
            'status' => $status,
        ]);
    }

    /**
     * Every action that works, from the status it works on.
     *
     * @return array<string, array{string, VisitStatus, VisitStatus, VisitEventType}>
     */
    public static function workingActions(): array
    {
        return [
            'call a waiting patient' => ['call', VisitStatus::Waiting, VisitStatus::Called, VisitEventType::Called],
            'start a called patient' => ['start', VisitStatus::Called, VisitStatus::InService, VisitEventType::Started],
            'complete a patient being served' => ['complete', VisitStatus::InService, VisitStatus::Completed, VisitEventType::Completed],
            'cancel a waiting patient' => ['cancel', VisitStatus::Waiting, VisitStatus::Cancelled, VisitEventType::Cancelled],
            'cancel a called patient (the no-show)' => ['cancel', VisitStatus::Called, VisitStatus::Cancelled, VisitEventType::Cancelled],
        ];
    }

    /**
     * Every action attempted from a status it does not work on.
     *
     * @return array<string, array{string, VisitStatus}>
     */
    public static function actionsOutOfOrder(): array
    {
        $working = [];

        foreach (self::workingActions() as [$action, $from]) {
            $working[] = "{$action}:{$from->value}";
        }

        $cases = [];

        foreach (['call', 'start', 'complete', 'cancel'] as $action) {
            foreach (VisitStatus::cases() as $status) {
                if (! in_array("{$action}:{$status->value}", $working, true)) {
                    $cases["{$action} a {$status->value} visit"] = [$action, $status];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('workingActions')]
    public function test_each_action_moves_the_visit_on_and_logs_who_did_it(string $action, VisitStatus $from, VisitStatus $to, VisitEventType $event): void
    {
        $visit = $this->visit($this->consultation, $from);

        $this->actingAs($this->doctor)
            ->post(route("queue.{$action}", $visit))
            ->assertRedirect(route('queue.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame($to, $visit->fresh()->status);

        $log = $visit->events;
        $this->assertCount(1, $log);
        $this->assertSame($event, $log->first()->event);
        $this->assertSame($this->doctor->id, $log->first()->user_id);
        $this->assertSame($this->consultation->id, $log->first()->department_id);
    }

    #[DataProvider('actionsOutOfOrder')]
    public function test_an_action_out_of_order_is_refused_with_a_message_and_changes_nothing(string $action, VisitStatus $status): void
    {
        $visit = $this->visit($this->consultation, $status);

        $this->actingAs($this->doctor)
            ->post(route("queue.{$action}", $visit))
            ->assertRedirect(route('queue.index'))
            ->assertSessionHasErrors('queue');

        $this->assertSame($status, $visit->fresh()->status);
        $this->assertDatabaseCount('visit_events', 0);
    }

    public function test_call_start_complete_in_sequence_leaves_a_full_audit_trail_with_each_persons_name(): void
    {
        $nurse = User::factory()->for($this->facility)->nurse()->create(['department_id' => $this->consultation->id]);
        $visit = $this->visit($this->consultation);

        $this->actingAs($nurse)->post(route('queue.call', $visit));
        $this->actingAs($this->doctor)->post(route('queue.start', $visit));
        $this->actingAs($this->doctor)->post(route('queue.complete', $visit));

        $visit->refresh();
        $this->assertSame(VisitStatus::Completed, $visit->status);
        $this->assertNotNull($visit->completed_at);
        $this->assertSame(
            [[VisitEventType::Called, $nurse->id], [VisitEventType::Started, $this->doctor->id], [VisitEventType::Completed, $this->doctor->id]],
            $visit->events->map(fn ($event) => [$event->event, $event->user_id])->all(),
        );
    }

    public function test_the_whole_journey_from_registration_is_one_continuous_log(): void
    {
        $receptionist = User::factory()->for($this->facility)->receptionist()->create(['department_id' => $this->consultation->id]);
        $visit = app(RegisterPatientVisit::class)->handle($receptionist, [
            'phone' => '+254712345678',
            'name' => 'Wanjiru Kamau',
            'department_id' => $this->consultation->id,
        ]);

        $this->actingAs($this->doctor)->post(route('queue.call', $visit));
        $this->actingAs($this->doctor)->post(route('queue.start', $visit));
        $this->actingAs($this->doctor)->post(route('queue.complete', $visit));

        $this->assertSame(
            [VisitEventType::Registered, VisitEventType::Called, VisitEventType::Started, VisitEventType::Completed],
            $visit->events->pluck('event')->all(),
        );
        $this->assertSame($receptionist->id, $visit->events->first()->user_id);
    }

    public function test_two_doctors_in_the_same_department_share_one_queue_and_either_can_act_on_any_patient(): void
    {
        $secondDoctor = User::factory()->for($this->facility)->doctor()->create(['department_id' => $this->consultation->id]);
        $visit = $this->visit($this->consultation);

        // Both see the same patient waiting.
        foreach ([$this->doctor, $secondDoctor] as $doctor) {
            $this->actingAs($doctor)->get(route('queue.index'))->assertSee(route('queue.call', $visit), false);
        }

        // One calls, the other (seeing the change) starts, the first finishes.
        $this->actingAs($this->doctor)->post(route('queue.call', $visit))->assertSessionHasNoErrors();
        $this->actingAs($secondDoctor)->get(route('queue.index'))->assertSee(route('queue.start', $visit), false);
        $this->actingAs($secondDoctor)->post(route('queue.start', $visit))->assertSessionHasNoErrors();
        $this->actingAs($this->doctor)->post(route('queue.complete', $visit))->assertSessionHasNoErrors();

        $this->assertSame(VisitStatus::Completed, $visit->fresh()->status);
        $this->assertSame(
            [$this->doctor->id, $secondDoctor->id, $this->doctor->id],
            $visit->events->pluck('user_id')->all(),
        );
    }

    public function test_a_refusal_is_explained_on_the_page_the_staff_member_lands_on(): void
    {
        $visit = $this->visit($this->consultation, VisitStatus::Called);

        $this->actingAs($this->doctor)
            ->followingRedirects()
            ->post(route('queue.call', $visit))
            ->assertOk()
            ->assertSeeText("#19 is already Called, so that can't be done. The queue shows the latest status.");
    }

    public function test_pressing_the_same_button_twice_only_counts_once(): void
    {
        $visit = $this->visit($this->consultation);

        $this->actingAs($this->doctor)->post(route('queue.call', $visit))->assertSessionHasNoErrors();
        $this->actingAs($this->doctor)->post(route('queue.call', $visit))->assertSessionHasErrors('queue');

        $this->assertCount(1, $visit->events);
    }

    public function test_a_doctor_in_another_department_cannot_act_on_a_visit_and_nothing_changes(): void
    {
        $labVisit = $this->visit($this->laboratory, VisitStatus::InService);

        foreach (['call', 'start', 'complete', 'cancel'] as $action) {
            $this->actingAs($this->doctor)->post(route("queue.{$action}", $labVisit))->assertForbidden();
        }

        $this->assertSame(VisitStatus::InService, $labVisit->fresh()->status);
        $this->assertDatabaseCount('visit_events', 0);
    }

    public function test_a_receptionist_cannot_act_on_a_consultation_visit(): void
    {
        $reception = Department::factory()->for($this->facility)->create(['type' => DepartmentType::Reception]);
        $receptionist = User::factory()->for($this->facility)->receptionist()->create(['department_id' => $reception->id]);
        $visit = $this->visit($this->consultation);

        $this->actingAs($receptionist)->post(route('queue.call', $visit))->assertForbidden();

        $this->assertSame(VisitStatus::Waiting, $visit->fresh()->status);
    }

    public function test_staff_with_no_department_cannot_act_on_anything(): void
    {
        $unassigned = User::factory()->for($this->facility)->nurse()->create(['department_id' => null]);

        $this->actingAs($unassigned)->post(route('queue.call', $this->visit($this->consultation)))->assertForbidden();
        $this->actingAs($unassigned)->post(route('queue.call', $this->visit(null)))->assertForbidden();
    }

    public function test_nurses_and_receptionists_can_work_their_own_departments_queue_too(): void
    {
        foreach ([UserRole::Nurse, UserRole::Receptionist] as $role) {
            $user = User::factory()->for($this->facility)->withRole($role)->create(['department_id' => $this->laboratory->id]);
            $visit = $this->visit($this->laboratory);

            $this->actingAs($user)->post(route('queue.call', $visit))->assertSessionHasNoErrors();

            $this->assertSame(VisitStatus::Called, $visit->fresh()->status);
        }
    }

    public function test_an_admin_can_act_on_any_department_and_returns_to_that_departments_tab(): void
    {
        $labVisit = $this->visit($this->laboratory);

        $this->actingAs($this->admin)
            ->post(route('queue.call', $labVisit))
            ->assertRedirect(route('queue.index', ['department' => $this->laboratory->id]));

        $this->assertSame(VisitStatus::Called, $labVisit->fresh()->status);
        $this->assertSame($this->admin->id, $labVisit->events->sole()->user_id);
    }

    public function test_an_admin_can_work_visits_that_have_no_department(): void
    {
        $orphan = $this->visit(null);

        $this->actingAs($this->admin)
            ->post(route('queue.call', $orphan))
            ->assertRedirect(route('queue.index', ['department' => 'none']));

        $this->assertSame(VisitStatus::Called, $orphan->fresh()->status);
    }

    public function test_another_facilitys_visit_does_not_exist_for_anyone_here_not_even_an_admin(): void
    {
        $otherFacility = Facility::factory()->create();
        $theirs = $this->visit(Department::factory()->for($otherFacility)->create(), facility: $otherFacility);

        $this->actingAs($this->admin)->post(route('queue.call', $theirs))->assertNotFound();
        $this->actingAs($this->doctor)->post(route('queue.call', $theirs))->assertNotFound();

        $this->assertSame(VisitStatus::Waiting, $theirs->fresh()->status);
    }

    public function test_actions_only_answer_to_post(): void
    {
        $visit = $this->visit($this->consultation);

        $this->actingAs($this->doctor)->get("/queue/{$visit->id}/call")->assertStatus(405);
        $this->assertSame(VisitStatus::Waiting, $visit->fresh()->status);
    }

    public function test_a_system_admin_and_a_guest_cannot_act(): void
    {
        $visit = $this->visit($this->consultation);

        $this->actingAs(User::factory()->systemAdmin()->create())->post(route('queue.call', $visit))->assertForbidden();

        auth()->logout();
        $this->post(route('queue.call', $visit))->assertRedirect(route('login'));

        $this->assertSame(VisitStatus::Waiting, $visit->fresh()->status);
    }

    public function test_a_missing_visit_is_a_404(): void
    {
        $this->actingAs($this->doctor)->post(route('queue.call', 999999))->assertNotFound();
    }
}
