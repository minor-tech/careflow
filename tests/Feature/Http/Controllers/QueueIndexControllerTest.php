<?php

namespace Tests\Feature\Http\Controllers;

use App\Actions\RegisterPatientVisit;
use App\Enums\DepartmentType;
use App\Enums\UserRole;
use App\Enums\VisitStatus;
use App\Models\Department;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QueueIndexControllerTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    private Department $reception;

    private Department $consultation;

    private Department $laboratory;

    private User $doctor;

    private User $receptionist;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facility = Facility::factory()->create();
        $this->reception = Department::factory()->for($this->facility)->create(['name' => 'Reception', 'type' => DepartmentType::Reception]);
        $this->consultation = Department::factory()->for($this->facility)->create(['name' => 'Consultation', 'type' => DepartmentType::Consultation]);
        $this->laboratory = Department::factory()->for($this->facility)->create(['name' => 'Laboratory', 'type' => DepartmentType::Laboratory]);
        $this->doctor = User::factory()->for($this->facility)->doctor()->create(['department_id' => $this->consultation->id]);
        $this->receptionist = User::factory()->for($this->facility)->receptionist()->create(['department_id' => $this->reception->id]);
        $this->admin = User::factory()->for($this->facility)->create();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function visit(?Department $department, string $patientName, VisitStatus $status = VisitStatus::Waiting, int $number = 1, array $attributes = [], ?Facility $facility = null): Visit
    {
        $facility ??= $this->facility;

        return Visit::factory()->for($facility)->create([
            'department_id' => $department?->id,
            'patient_id' => Patient::factory()->for($facility)->create(['name' => $patientName])->id,
            'queue_number' => $number,
            'status' => $status,
            ...$attributes,
        ]);
    }

    public function test_staff_see_only_their_own_departments_queue(): void
    {
        $this->visit($this->consultation, 'Consult Carol');
        $this->visit($this->laboratory, 'Lab Larry');
        $this->visit($this->reception, 'Reception Rita');
        $this->visit(null, 'Nowhere Nick');
        $otherFacility = Facility::factory()->create();
        $this->visit(Department::factory()->for($otherFacility)->create(), 'Elsewhere Ellen', facility: $otherFacility);

        $this->actingAs($this->doctor)
            ->get(route('queue.index'))
            ->assertOk()
            ->assertSeeText('Consultation')
            ->assertSeeText('Consult Carol')
            ->assertDontSeeText('Lab Larry')
            ->assertDontSeeText('Reception Rita')
            ->assertDontSeeText('Nowhere Nick')
            ->assertDontSeeText('Elsewhere Ellen');
    }

    public function test_a_receptionist_sees_the_patients_registered_at_the_front_desk(): void
    {
        $action = app(RegisterPatientVisit::class);
        $action->handle($this->receptionist, ['phone' => '+254711000001', 'name' => 'Walk In Wanjiru']);
        $action->handle($this->receptionist, ['phone' => '+254711000002', 'name' => 'Sent To Consultation', 'department_id' => $this->consultation->id]);

        $this->actingAs($this->receptionist)
            ->get(route('queue.index'))
            ->assertSeeText('Walk In Wanjiru')
            ->assertDontSeeText('Sent To Consultation');

        $this->actingAs($this->doctor)
            ->get(route('queue.index'))
            ->assertSeeText('Sent To Consultation')
            ->assertDontSeeText('Walk In Wanjiru');
    }

    public function test_only_visits_still_in_the_queue_are_listed(): void
    {
        $this->visit($this->consultation, 'Waiting Wendy', VisitStatus::Waiting, 1);
        $this->visit($this->consultation, 'Called Cathy', VisitStatus::Called, 2);
        $this->visit($this->consultation, 'Serving Sam', VisitStatus::InService, 3);
        $this->visit($this->consultation, 'Done Dan', VisitStatus::Completed, 4);
        $this->visit($this->consultation, 'Gone Gina', VisitStatus::Cancelled, 5);
        $this->visit($this->consultation, 'Onward Olga', VisitStatus::WaitingDepartment, 6);

        $this->actingAs($this->doctor)
            ->get(route('queue.index'))
            ->assertSeeText('Waiting Wendy')
            ->assertSeeText('Called Cathy')
            ->assertSeeText('Serving Sam')
            ->assertDontSeeText('Done Dan')
            ->assertDontSeeText('Gone Gina')
            ->assertDontSeeText('Onward Olga');
    }

    public function test_only_todays_visits_are_listed_by_the_clinics_nairobi_calendar(): void
    {
        // 13:00 on the 18th in Nairobi; the clinic day began at 21:00 UTC on the 17th.
        $this->travelTo(now()->setDateTime(2026, 9, 18, 10, 0, 0)->utc());

        $this->visit($this->consultation, 'Just After Midnight', attributes: ['created_at' => '2026-09-17 21:00:00']);
        $this->visit($this->consultation, 'Just Before Midnight', attributes: ['created_at' => '2026-09-17 20:59:59']);
        $this->visit($this->consultation, 'Yesterday Yusuf', attributes: ['created_at' => '2026-09-16 09:00:00']);
        $this->visit($this->consultation, 'This Morning Mary', attributes: ['created_at' => '2026-09-18 05:00:00']);

        $this->actingAs($this->doctor)
            ->get(route('queue.index'))
            ->assertSeeText('Just After Midnight')
            ->assertSeeText('This Morning Mary')
            ->assertDontSeeText('Just Before Midnight')
            ->assertDontSeeText('Yesterday Yusuf');
    }

    public function test_people_needing_attention_come_first_then_the_rest_in_queue_order(): void
    {
        $this->visit($this->consultation, 'Wait Nine', VisitStatus::Waiting, 9);
        $this->visit($this->consultation, 'Wait Three', VisitStatus::Waiting, 3);
        $this->visit($this->consultation, 'Called Seven', VisitStatus::Called, 7);
        $this->visit($this->consultation, 'Serving Twelve', VisitStatus::InService, 12);
        $this->visit($this->consultation, 'Called Two', VisitStatus::Called, 2);
        $this->visit($this->consultation, 'Serving Eleven', VisitStatus::InService, 11);

        $this->actingAs($this->doctor)
            ->get(route('queue.index'))
            ->assertSeeInOrder([
                'Serving Eleven', 'Serving Twelve',
                'Called Two', 'Called Seven',
                'Wait Three', 'Wait Nine',
            ]);
    }

    public function test_the_header_counts_waiting_called_and_in_service(): void
    {
        $this->visit($this->consultation, 'A', VisitStatus::Waiting, 1);
        $this->visit($this->consultation, 'B', VisitStatus::Waiting, 2);
        $this->visit($this->consultation, 'C', VisitStatus::Waiting, 3);
        $this->visit($this->consultation, 'D', VisitStatus::Called, 4);
        $this->visit($this->consultation, 'E', VisitStatus::InService, 5);
        $this->visit($this->consultation, 'F', VisitStatus::Completed, 6);

        $this->actingAs($this->doctor)
            ->get(route('queue.index'))
            ->assertSeeInOrder(['Waiting', '3', 'Called', '1', 'In service', '1']);
    }

    public function test_each_row_offers_exactly_the_next_action_for_its_status(): void
    {
        $waiting = $this->visit($this->consultation, 'Wait', VisitStatus::Waiting, 1);
        $called = $this->visit($this->consultation, 'Call', VisitStatus::Called, 2);
        $serving = $this->visit($this->consultation, 'Serve', VisitStatus::InService, 3);

        $page = $this->actingAs($this->doctor)->get(route('queue.index'));

        $page->assertSee(route('queue.call', $waiting), false)
            ->assertDontSee(route('queue.start', $waiting), false)
            ->assertDontSee(route('queue.complete', $waiting), false)
            ->assertSee(route('queue.start', $called), false)
            ->assertDontSee(route('queue.call', $called), false)
            ->assertDontSee(route('queue.complete', $called), false)
            ->assertSee(route('queue.complete', $serving), false)
            ->assertDontSee(route('queue.call', $serving), false)
            ->assertDontSee(route('queue.start', $serving), false);
    }

    public function test_cancel_is_offered_for_waiting_and_called_patients_but_not_for_one_being_served(): void
    {
        $waiting = $this->visit($this->consultation, 'Wait', VisitStatus::Waiting, 1);
        $called = $this->visit($this->consultation, 'Call', VisitStatus::Called, 2);
        $serving = $this->visit($this->consultation, 'Serve', VisitStatus::InService, 3);

        $this->actingAs($this->doctor)
            ->get(route('queue.index'))
            ->assertSee(route('queue.cancel', $waiting), false)
            ->assertSee(route('queue.cancel', $called), false)
            ->assertDontSee(route('queue.cancel', $serving), false);
    }

    public function test_a_patient_being_served_is_marked_green_and_the_others_amber(): void
    {
        $this->visit($this->consultation, 'Wait', VisitStatus::Waiting, 1);

        $this->actingAs($this->doctor)
            ->get(route('queue.index'))
            ->assertSee('cf-dot--wait', false)
            ->assertDontSee('cf-dot--ok', false);

        $this->visit($this->consultation, 'Serve', VisitStatus::InService, 2);

        $this->actingAs($this->doctor)
            ->get(route('queue.index'))
            ->assertSee('cf-dot--ok', false);
    }

    public function test_a_patient_being_served_offers_send_to_and_complete_visit_and_neither_is_the_filled_default(): void
    {
        $serving = $this->visit($this->consultation, 'Serve', VisitStatus::InService, 3);

        $this->actingAs($this->doctor)
            ->get(route('queue.index'))
            ->assertSeeText('Send to…')
            ->assertSeeText('Complete visit')
            ->assertSee(route('queue.complete', $serving), false)
            ->assertDontSee(route('queue.call', $serving), false)
            ->assertDontSee(route('queue.start', $serving), false)
            // Every filled action button in a row carries min-w-24; this row has none.
            ->assertDontSee('min-w-24', false);
    }

    public function test_send_to_lists_the_facilitys_other_active_departments_only(): void
    {
        $serving = $this->visit($this->consultation, 'Serve', VisitStatus::InService, 3);
        $closed = Department::factory()->for($this->facility)->inactive()->create(['name' => 'Closed Ward']);
        $foreign = Department::factory()->for(Facility::factory())->create(['name' => 'Foreign Ward']);

        $page = $this->actingAs($this->doctor)->get(route('queue.index'));

        $page->assertSee(route('queue.transfer', [$serving, $this->laboratory]), false)
            ->assertSee(route('queue.transfer', [$serving, $this->reception]), false)
            ->assertDontSee(route('queue.transfer', [$serving, $this->consultation]), false)
            ->assertDontSee(route('queue.transfer', [$serving, $closed]), false)
            ->assertDontSee(route('queue.transfer', [$serving, $foreign]), false)
            ->assertDontSeeText('Closed Ward')
            ->assertDontSeeText('Foreign Ward');
    }

    public function test_send_to_shows_each_departments_queue_letter(): void
    {
        $this->visit($this->consultation, 'Serve', VisitStatus::InService, 3);

        $html = $this->actingAs($this->doctor)->get(route('queue.index'))->getContent();

        $this->assertMatchesRegularExpression('/Laboratory<\/span>\s*<span class="text-ink\/60">L</', $html);
        $this->assertMatchesRegularExpression('/Reception<\/span>\s*<span class="text-ink\/60">R</', $html);
    }

    public function test_send_to_is_only_offered_for_a_patient_being_served(): void
    {
        $waiting = $this->visit($this->consultation, 'Wait', VisitStatus::Waiting, 1);
        $called = $this->visit($this->consultation, 'Call', VisitStatus::Called, 2);

        $this->actingAs($this->doctor)
            ->get(route('queue.index'))
            ->assertDontSeeText('Send to…')
            ->assertDontSeeText('Complete visit')
            ->assertDontSee(route('queue.transfer', [$waiting, $this->laboratory]), false)
            ->assertDontSee(route('queue.transfer', [$called, $this->laboratory]), false);
    }

    public function test_a_facility_with_only_one_department_offers_complete_visit_and_no_send_to(): void
    {
        $solo = Facility::factory()->create();
        $only = Department::factory()->for($solo)->create(['type' => DepartmentType::Consultation]);
        $doctor = User::factory()->for($solo)->doctor()->create(['department_id' => $only->id]);
        $this->visit($only, 'Serve', VisitStatus::InService, 1, facility: $solo);

        $this->actingAs($doctor)
            ->get(route('queue.index'))
            ->assertSeeText('Complete visit')
            ->assertDontSeeText('Send to…');
    }

    public function test_a_patient_who_has_been_sent_on_shows_under_their_departments_own_number(): void
    {
        $this->visit($this->laboratory, 'Sent On Sam', VisitStatus::Waiting, 27, ['department_queue_number' => 8]);
        $this->visit($this->laboratory, 'Walk In Wendy', VisitStatus::Waiting, 30);

        $this->actingAs(User::factory()->for($this->facility)->nurse()->create(['department_id' => $this->laboratory->id]))
            ->get(route('queue.index'))
            ->assertSeeText('L-8')
            ->assertSeeText('#30')
            ->assertDontSeeText('#27');
    }

    public function test_someone_sent_on_joins_the_back_of_the_queue_whatever_their_registration_number(): void
    {
        $this->visit($this->laboratory, 'Waiting Longer', VisitStatus::Waiting, 40, ['department_entered_at' => now()->subMinutes(30)]);
        $this->visit($this->laboratory, 'Just Sent On', VisitStatus::Waiting, 3, ['department_queue_number' => 2, 'department_entered_at' => now()->subMinute()]);

        $this->actingAs(User::factory()->for($this->facility)->nurse()->create(['department_id' => $this->laboratory->id]))
            ->get(route('queue.index'))
            ->assertSeeInOrder(['Waiting Longer', 'Just Sent On']);
    }

    public function test_time_waited_counts_from_arriving_at_this_department_not_from_registration(): void
    {
        // Registered 90 minutes ago must still be "today", so not just after the clinic's midnight.
        $this->travelTo(now(config('careflow.timezone'))->setTime(12, 0));

        $this->visit($this->laboratory, 'Sent On Sam', VisitStatus::Waiting, 27, [
            'created_at' => now()->subMinutes(90),
            'department_entered_at' => now()->subMinutes(7),
            'department_queue_number' => 1,
        ]);

        $this->actingAs(User::factory()->for($this->facility)->nurse()->create(['department_id' => $this->laboratory->id]))
            ->get(route('queue.index'))
            ->assertSeeText('7 min')
            ->assertDontSeeText('90 min');
    }

    public function test_shows_the_queue_number_and_how_long_each_patient_has_waited(): void
    {
        $this->visit($this->consultation, 'Wait', VisitStatus::Waiting, 19, ['created_at' => now()->subMinutes(15)]);

        $this->actingAs($this->doctor)
            ->get(route('queue.index'))
            ->assertSeeText('#19')
            ->assertSeeText('15 min');
    }

    public function test_staff_cannot_view_another_departments_queue_by_asking_for_it(): void
    {
        $this->visit($this->consultation, 'Consult Carol');
        $this->visit($this->laboratory, 'Lab Larry');

        $this->actingAs($this->doctor)
            ->get(route('queue.index', ['department' => $this->laboratory->id]))
            ->assertSeeText('Consult Carol')
            ->assertDontSeeText('Lab Larry')
            ->assertDontSee('aria-label="Department queues"', false);
    }

    public function test_staff_with_no_department_are_told_to_ask_their_admin(): void
    {
        $this->visit($this->consultation, 'Consult Carol');
        $unassigned = User::factory()->for($this->facility)->nurse()->create(['department_id' => null]);

        $this->actingAs($unassigned)
            ->get(route('queue.index'))
            ->assertOk()
            ->assertSeeText("You aren't assigned to a department yet")
            ->assertDontSeeText('Consult Carol');
    }

    public function test_an_empty_queue_says_so(): void
    {
        $this->actingAs($this->doctor)
            ->get(route('queue.index'))
            ->assertSeeText('Nobody is waiting right now.');
    }

    public function test_an_admin_can_switch_between_department_queues(): void
    {
        $this->visit($this->consultation, 'Consult Carol', number: 1);
        $this->visit($this->laboratory, 'Lab Larry', number: 1);
        $this->visit($this->laboratory, 'Lab Lucy', number: 2);

        $this->actingAs($this->admin)
            ->get(route('queue.index'))
            ->assertSeeText('Reception')
            ->assertSeeText('Consultation')
            ->assertSeeText('Laboratory')
            ->assertSee(route('queue.index', ['department' => $this->laboratory->id]), false);

        $this->actingAs($this->admin)
            ->get(route('queue.index', ['department' => $this->laboratory->id]))
            ->assertSeeText('Lab Larry')
            ->assertSeeText('Lab Lucy')
            ->assertDontSeeText('Consult Carol');

        $this->actingAs($this->admin)
            ->get(route('queue.index', ['department' => $this->consultation->id]))
            ->assertSeeText('Consult Carol')
            ->assertDontSeeText('Lab Larry');
    }

    public function test_an_admins_tabs_show_how_many_are_waiting_in_each_department(): void
    {
        foreach (range(1, 4) as $number) {
            $this->visit($this->laboratory, "Lab {$number}", number: $number);
        }
        $this->visit($this->laboratory, 'Lab Called', VisitStatus::Called, 5);

        $this->actingAs($this->admin)
            ->get(route('queue.index'))
            ->assertSee('aria-label="4 waiting"', false)
            ->assertSee('aria-label="0 waiting"', false);
    }

    public function test_an_admin_lands_on_the_first_department_and_ignores_unknown_or_foreign_ones(): void
    {
        $this->visit($this->reception, 'Reception Rita');
        $foreign = Department::factory()->for(Facility::factory())->create(['name' => 'Foreign Ward']);

        foreach ([[], ['department' => 'nonsense'], ['department' => $foreign->id], ['department' => '0']] as $query) {
            $this->actingAs($this->admin)
                ->get(route('queue.index', $query))
                ->assertSeeText('Reception Rita')
                ->assertDontSeeText('Foreign Ward');
        }
    }

    public function test_inactive_departments_have_a_tab_only_while_patients_are_still_queued_in_them(): void
    {
        $closed = Department::factory()->for($this->facility)->inactive()->create(['name' => 'Closed Ward']);

        $this->actingAs($this->admin)->get(route('queue.index'))->assertDontSeeText('Closed Ward');

        $this->visit($closed, 'Left Behind Lena');

        $this->actingAs($this->admin)
            ->get(route('queue.index', ['department' => $closed->id]))
            ->assertSeeText('Closed Ward')
            ->assertSeeText('Left Behind Lena');
    }

    public function test_visits_with_no_department_get_their_own_tab_so_nobody_is_invisible(): void
    {
        $this->actingAs($this->admin)->get(route('queue.index'))->assertDontSeeText('No department');

        $this->visit(null, 'Nowhere Nick');

        $this->actingAs($this->admin)
            ->get(route('queue.index'))
            ->assertSeeText('No department');

        $this->actingAs($this->admin)
            ->get(route('queue.index', ['department' => 'none']))
            ->assertSeeText('Nowhere Nick');
    }

    public function test_an_admin_in_a_facility_with_no_departments_is_pointed_to_add_one(): void
    {
        $bare = Facility::factory()->create();

        $this->actingAs(User::factory()->for($bare)->create())
            ->get(route('queue.index'))
            ->assertOk()
            ->assertSeeText('Add one under Departments');
    }

    public function test_the_page_asks_for_just_the_board_when_it_polls_and_the_whole_page_otherwise(): void
    {
        $this->visit($this->consultation, 'Consult Carol');

        $whole = $this->actingAs($this->doctor)->get(route('queue.index'));
        $whole->assertSee('<html', false)->assertSee('queueBoard', false)->assertSeeText('Consult Carol');

        $board = $this->actingAs($this->doctor)->get(route('queue.index'), ['X-Requested-With' => 'XMLHttpRequest']);
        $board->assertOk()->assertSeeText('Consult Carol')->assertDontSee('<html', false)->assertDontSee('queueBoard', false);
    }

    public function test_a_change_made_by_a_colleague_shows_up_in_the_next_poll(): void
    {
        $visit = $this->visit($this->consultation, 'Consult Carol', VisitStatus::Waiting, 5);
        $poll = fn () => $this->actingAs($this->doctor)->get(route('queue.index'), ['X-Requested-With' => 'XMLHttpRequest']);

        $poll()->assertSee(route('queue.call', $visit), false);

        $visit->update(['status' => VisitStatus::Called]);

        $poll()->assertSee(route('queue.start', $visit), false)->assertDontSee(route('queue.call', $visit), false);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rolesThatWorkTheQueue(): array
    {
        return ['receptionist' => ['receptionist'], 'doctor' => ['doctor'], 'nurse' => ['nurse'], 'admin' => ['admin']];
    }

    #[DataProvider('rolesThatWorkTheQueue')]
    public function test_every_facility_role_can_open_the_queue(string $role): void
    {
        $user = User::factory()->for($this->facility)->withRole(UserRole::from($role))->create(['department_id' => $this->consultation->id]);

        $this->actingAs($user)->get(route('queue.index'))->assertOk();
    }

    public function test_a_system_admin_has_no_queue_and_guests_are_sent_to_login(): void
    {
        $this->actingAs(User::factory()->systemAdmin()->create())->get(route('queue.index'))->assertForbidden();

        auth()->logout();
        $this->get(route('queue.index'))->assertRedirect(route('login'));
    }

    public function test_the_queue_is_not_available_while_the_facility_is_not_active(): void
    {
        $pending = Facility::factory()->pendingReview()->create();
        $nurse = User::factory()->for($pending)->nurse()->create();

        $this->actingAs($nurse)->get(route('queue.index'))->assertRedirect(route('login'));
    }
}
