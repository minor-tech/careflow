<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\DepartmentType;
use App\Enums\FacilityStatus;
use App\Enums\FeedbackIssue;
use App\Enums\VisitEventType;
use App\Enums\VisitStatus;
use App\Models\Department;
use App\Models\Facility;
use App\Models\Feedback;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\LogsVisitHistory;
use Tests\TestCase;

class AnalyticsControllerTest extends TestCase
{
    use LogsVisitHistory;
    use RefreshDatabase;

    private Facility $facility;

    private User $admin;

    private Department $reception;

    private Department $consultation;

    private Department $pharmacy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now(config('careflow.timezone'))->setTime(12, 0));

        $this->facility = Facility::factory()->create();
        $this->admin = User::factory()->for($this->facility)->create();
        $this->reception = Department::factory()->for($this->facility)->create(['name' => 'Reception', 'type' => DepartmentType::Reception]);
        $this->consultation = Department::factory()->for($this->facility)->create(['name' => 'Consultation', 'type' => DepartmentType::Consultation]);
        $this->pharmacy = Department::factory()->for($this->facility)->create(['name' => 'Pharmacy', 'type' => DepartmentType::Pharmacy]);
    }

    /**
     * A finished day's worth of patients: waits of 10 and 30 minutes, one cancellation, one still waiting.
     */
    private function aBusyMorning(): void
    {
        foreach ([10, 30] as $minutes) {
            $this->visitWith([
                [0, VisitEventType::Registered, $this->reception],
                [$minutes, VisitEventType::Called, $this->reception],
                [$minutes + 5, VisitEventType::Transferred, $this->consultation],
                [$minutes + 45, VisitEventType::Completed, $this->consultation],
            ], attributes: ['status' => VisitStatus::Completed]);
        }

        $this->visitWith([[0, VisitEventType::Registered, $this->pharmacy], [3, VisitEventType::Cancelled, $this->pharmacy]], attributes: ['status' => VisitStatus::Cancelled]);
        $this->visitWith([[0, VisitEventType::Registered, $this->reception]], attributes: ['status' => VisitStatus::Waiting]);
    }

    public function test_it_shows_todays_counts_and_waits(): void
    {
        $this->aBusyMorning();

        $this->actingAs($this->admin)
            ->get(route('analytics.index'))
            ->assertOk()
            ->assertSeeTextInOrder(['4', 'Patients registered', '2', 'Completed', '1', 'Cancelled', '1', 'Still in progress'])
            ->assertSeeTextInOrder(['20 min', 'Average wait to be called', '30 min', 'Longest wait to be called']);
    }

    public function test_it_lists_departments_slowest_first_and_singles_out_the_slowest(): void
    {
        $this->aBusyMorning();

        $this->actingAs($this->admin)
            ->get(route('analytics.index'))
            ->assertSeeTextInOrder(['Department performance', 'Consultation', '40 min', 'Reception', '25 min'])
            ->assertSeeText('Slowest today')
            ->assertSee('cf-dot--gold', false);
    }

    public function test_only_the_slowest_department_is_flagged(): void
    {
        $this->aBusyMorning();

        $content = $this->actingAs($this->admin)->get(route('analytics.index'))->getContent();

        $this->assertSame(1, substr_count($content, 'Slowest today'));
    }

    public function test_nothing_is_flagged_when_there_is_only_one_department_to_show(): void
    {
        $this->visitWith([[0, VisitEventType::Registered, $this->pharmacy], [9, VisitEventType::Completed, $this->pharmacy]]);

        $this->actingAs($this->admin)->get(route('analytics.index'))
            ->assertSeeText('Pharmacy')
            ->assertDontSeeText('Slowest today');
    }

    public function test_the_bars_are_as_long_as_each_departments_share_of_the_slowest(): void
    {
        $this->visitWith([[0, VisitEventType::Registered, $this->consultation], [40, VisitEventType::Completed, $this->consultation]]);
        $this->visitWith([[0, VisitEventType::Registered, $this->pharmacy], [10, VisitEventType::Completed, $this->pharmacy]]);

        $this->actingAs($this->admin)
            ->get(route('analytics.index'))
            ->assertSee('width: 100%', false)
            ->assertSee('width: 25%', false);
    }

    public function test_a_quiet_day_says_so_instead_of_showing_zeros_as_facts(): void
    {
        $this->actingAs($this->admin)
            ->get(route('analytics.index'))
            ->assertOk()
            ->assertSeeText('Nothing to show yet.')
            ->assertSeeTextInOrder(['Average wait to be called', '—', 'Longest wait to be called', '—']);
    }

    public function test_it_shows_only_this_facilitys_numbers(): void
    {
        $otherFacility = Facility::factory()->create();
        $theirs = Department::factory()->for($otherFacility)->create(['name' => 'Their Ward']);
        $this->visitWith([[0, VisitEventType::Registered, $theirs], [77, VisitEventType::Completed, $theirs]], facility: $otherFacility, attributes: ['status' => VisitStatus::Completed]);

        $this->actingAs($this->admin)
            ->get(route('analytics.index'))
            ->assertDontSeeText('Their Ward')
            ->assertDontSeeText('77 min')
            ->assertSeeTextInOrder(['Patients registered', '0']);
    }

    /**
     * @param  list<FeedbackIssue>  $issues
     */
    private function rated(int $rating, array $issues = [], ?Facility $facility = null, int $daysAgo = 0): Feedback
    {
        return Feedback::factory()->create([
            'visit_id' => Visit::factory()->for($facility ?? $this->facility)->create(['status' => VisitStatus::Completed])->id,
            'rating' => $rating,
            'issues' => $issues === [] ? null : array_map(fn (FeedbackIssue $issue) => $issue->value, $issues),
            'created_at' => now()->subDays($daysAgo),
        ]);
    }

    public function test_it_shows_the_average_rating_and_the_issues_raised_with_their_shares(): void
    {
        foreach ([5, 5, 4, 4, 4] as $rating) {
            $this->rated($rating);
        }
        $this->rated(2, [FeedbackIssue::LongWait, FeedbackIssue::Billing]);
        $this->rated(1, [FeedbackIssue::LongWait]);
        $this->rated(3, [FeedbackIssue::LongWait, FeedbackIssue::Doctor]);

        $this->actingAs($this->admin)
            ->get(route('analytics.index'))
            ->assertOk()
            ->assertSeeTextInOrder(['Customer experience', 'last 30 days', '3.5', 'Average rating', 'From 8 responses'])
            ->assertSeeTextInOrder(['Issues raised', 'From 3 visits rated 3 stars or fewer', 'Long wait', '60%', '(3)', 'Billing', '20%', '(1)', 'Doctor', '20%', '(1)']);
    }

    public function test_it_explains_that_the_shares_are_of_mentions_not_of_patients(): void
    {
        $this->rated(1, [FeedbackIssue::LongWait, FeedbackIssue::Staff]);

        $this->actingAs($this->admin)
            ->get(route('analytics.index'))
            ->assertSeeText('a share of all issues mentioned, not of patients');
    }

    public function test_the_experience_section_sits_on_the_same_page_right_after_department_performance(): void
    {
        $this->aBusyMorning();
        $this->rated(2, [FeedbackIssue::LongWait]);

        $this->actingAs($this->admin)
            ->get(route('analytics.index'))
            ->assertSeeTextInOrder(['Department performance', 'Consultation', 'Slowest today', 'Customer experience', 'Long wait', '100%']);
    }

    public function test_without_any_feedback_it_says_so(): void
    {
        $this->actingAs($this->admin)
            ->get(route('analytics.index'))
            ->assertSeeText('Customer experience')
            ->assertSeeText('No feedback yet.')
            ->assertDontSeeText('Average rating');
    }

    public function test_happy_feedback_alone_shows_the_rating_and_no_issues(): void
    {
        $this->rated(5);

        $this->actingAs($this->admin)
            ->get(route('analytics.index'))
            ->assertSeeText('5.0')
            ->assertSeeText('No issues have been named.');
    }

    public function test_it_shows_only_this_facilitys_feedback_and_only_the_last_30_days(): void
    {
        $this->rated(1, [FeedbackIssue::Pharmacy], Facility::factory()->create());
        $this->rated(1, [FeedbackIssue::Billing], daysAgo: 40);
        $this->rated(4);

        $this->actingAs($this->admin)
            ->get(route('analytics.index'))
            ->assertSeeText('From 1 response.')
            ->assertSeeText('4.0')
            ->assertDontSeeText('Pharmacy')
            ->assertDontSeeText('Billing');
    }

    public function test_a_patients_comment_is_not_shown_on_the_analytics_page(): void
    {
        $feedback = $this->rated(2, [FeedbackIssue::Staff]);
        $feedback->update(['comment' => 'A private remark about Dr Someone']);

        $this->actingAs($this->admin)
            ->get(route('analytics.index'))
            ->assertDontSeeText('A private remark');
    }

    public function test_the_page_names_todays_date_in_clinic_time(): void
    {
        $this->actingAs($this->admin)
            ->get(route('analytics.index'))
            ->assertSeeText(now(config('careflow.timezone'))->format('l j F'));
    }

    public function test_the_navigation_has_analytics_in_place_of_the_old_reports_stub(): void
    {
        $this->actingAs($this->admin)
            ->get(route('analytics.index'))
            ->assertSee(route('analytics.index'), false)
            ->assertDontSeeText('Reports arrive in a later sprint');

        $this->assertFalse(Route::has('admin.reports'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function everyoneButAFacilityAdmin(): array
    {
        return [
            'receptionist' => ['receptionist'],
            'doctor' => ['doctor'],
            'nurse' => ['nurse'],
            'system admin' => ['systemAdmin'],
        ];
    }

    #[DataProvider('everyoneButAFacilityAdmin')]
    public function test_only_a_facility_admin_can_open_it(string $role): void
    {
        $user = User::factory()->for($this->facility)->{$role}()->create();

        $this->actingAs($user)->get(route('analytics.index'))->assertForbidden();
    }

    #[DataProvider('everyoneButAFacilityAdmin')]
    public function test_other_roles_are_not_offered_the_link(string $role): void
    {
        $user = User::factory()->for($this->facility)->{$role}()->create();

        $this->actingAs($user)->get(route('queue.index'))->assertDontSee(route('analytics.index'), false);
    }

    public function test_a_guest_is_sent_to_log_in(): void
    {
        $this->get(route('analytics.index'))->assertRedirect(route('login'));
    }

    public function test_an_admin_of_a_facility_that_is_not_active_is_sent_to_the_status_page(): void
    {
        $this->facility->update(['status' => FacilityStatus::Suspended]);

        $this->actingAs($this->admin)->get(route('analytics.index'))->assertRedirect(route('facility.status'));
    }

    public function test_an_admin_still_on_a_temporary_password_is_held_at_the_password_page(): void
    {
        $admin = User::factory()->for($this->facility)->mustChangePassword()->create();

        $this->actingAs($admin)->get(route('analytics.index'))->assertRedirect(route('password.force'));
    }

    public function test_shows_each_doctors_real_workload_over_the_last_30_days(): void
    {
        $doctor = User::factory()->for($this->facility)->doctor()->onDuty()->create(['name' => 'Wanjiku Mwangi', 'department_id' => $this->consultation->id]);

        foreach ([[10, 12, 30], [20, 21, 33]] as [$called, $started, $completed]) {
            $this->visitWith([
                [0, VisitEventType::Registered, $this->consultation],
                [0, VisitEventType::DoctorAssigned, $this->consultation],
                [$called, VisitEventType::Called, $this->consultation],
                [$started, VisitEventType::Started, $this->consultation],
                [$completed, VisitEventType::Completed, $this->consultation],
            ], attributes: ['status' => VisitStatus::Completed, 'department_id' => $this->consultation->id, 'assigned_doctor_id' => $doctor->id, 'doctor_queue_number' => 1]);
        }

        $this->actingAs($this->admin)
            ->get(route('analytics.index'))
            ->assertOk()
            ->assertSeeText('Doctor workload')
            ->assertSeeTextInOrder(['Dr. Wanjiku Mwangi', '2 patients', 'Avg wait', '15 min', 'Avg consultation', '15 min']);
    }

    public function test_the_doctor_workload_says_so_when_no_patient_has_been_assigned_yet(): void
    {
        $this->actingAs($this->admin)
            ->get(route('analytics.index'))
            ->assertOk()
            ->assertSeeText('Doctor workload')
            ->assertSeeText('A doctor appears here once patients have been assigned to them.');
    }

    public function test_doctor_workload_is_only_for_the_admin_of_that_facility(): void
    {
        $doctor = User::factory()->for($this->facility)->doctor()->create(['department_id' => $this->consultation->id]);

        $this->actingAs($doctor)->get(route('analytics.index'))->assertForbidden();
    }
}
