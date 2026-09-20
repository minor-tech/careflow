<?php

namespace Tests\Feature\Services;

use App\Enums\DepartmentType;
use App\Enums\VisitStatus;
use App\Models\Department;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\PatientNotification;
use App\Models\Visit;
use App\Services\AlmostTurnNotifier;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AlmostTurnNotifierTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    private Department $consultation;

    private Department $laboratory;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->facility = Facility::factory()->create(['name' => 'Upendo Clinic', 'notification_channels' => ['sms']]);
        $this->consultation = Department::factory()->for($this->facility)->create(['name' => 'Consultation', 'type' => DepartmentType::Consultation]);
        $this->laboratory = Department::factory()->for($this->facility)->create(['name' => 'Laboratory', 'type' => DepartmentType::Laboratory]);
    }

    /**
     * Someone waiting who arrived in the order of their number: 1 first.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function waiting(int $number, ?Department $department = null, array $attributes = []): Visit
    {
        return Visit::factory()->for($this->facility)->create([
            'department_id' => ($department ?? $this->consultation)->id,
            'patient_id' => Patient::factory()->for($this->facility)->create(['name' => "Patient{$number} Surname"])->id,
            'queue_number' => $number,
            'status' => VisitStatus::Waiting,
            'department_entered_at' => now()->subMinutes(100 - $number),
            ...$attributes,
        ]);
    }

    private function justCompleted(?Department $department = null, ?Facility $facility = null): Visit
    {
        $facility ??= $this->facility;

        return Visit::factory()->for($facility)->create([
            'department_id' => ($department ?? $this->consultation)->id,
            'patient_id' => Patient::factory()->for($facility)->create()->id,
            'queue_number' => 999,
            'status' => VisitStatus::Completed,
        ]);
    }

    private function told(): array
    {
        return PatientNotification::orderBy('id')->get()->map(fn ($n) => $n->visit->queue_number)->all();
    }

    private function notify(Visit $completed): void
    {
        app(AlmostTurnNotifier::class)->notifyFor($completed);
    }

    public function test_the_first_two_waiting_are_told_they_are_almost_up_and_nobody_else(): void
    {
        foreach ([1, 2, 3, 4, 5] as $n) {
            $this->waiting($n);
        }

        $this->notify($this->justCompleted());

        $this->assertSame([1, 2], $this->told());
        $this->assertSame(
            "Upendo Clinic: You're almost up in Consultation. Please be ready.",
            PatientNotification::first()->message,
        );
        $this->assertSame(2, Visit::where('almost_turn_notified', true)->count());
    }

    public function test_each_person_is_told_exactly_once_however_many_completions_follow(): void
    {
        foreach ([1, 2, 3, 4] as $n) {
            $this->waiting($n);
        }

        foreach (range(1, 5) as $ignored) {
            $this->notify($this->justCompleted());
        }

        $this->assertSame([1, 2], $this->told());
    }

    public function test_as_the_queue_moves_up_each_new_person_at_the_front_is_told_once(): void
    {
        $visits = collect([1, 2, 3, 4])->mapWithKeys(fn ($n) => [$n => $this->waiting($n)]);

        $this->notify($this->justCompleted());
        $this->assertSame([1, 2], $this->told());

        // Number 1 is called in; number 3 is now within two of the front.
        $visits[1]->update(['status' => VisitStatus::Called]);
        $this->notify($this->justCompleted());
        $this->assertSame([1, 2, 3], $this->told());

        // Number 2 is called in; number 4 is next.
        $visits[2]->update(['status' => VisitStatus::Called]);
        $this->notify($this->justCompleted());
        $this->assertSame([1, 2, 3, 4], $this->told());
    }

    public function test_the_third_in_line_is_not_reached_just_because_someone_ahead_was_already_told(): void
    {
        $this->waiting(1, attributes: ['almost_turn_notified' => true]);
        $this->waiting(2);
        $this->waiting(3);
        $this->waiting(4);

        $this->notify($this->justCompleted());

        // Front two are 1 and 2; only 2 is new. Skipping 1 first and taking two more would have told 3 as well.
        $this->assertSame([2], $this->told());
        $this->assertFalse(Visit::where('queue_number', 3)->value('almost_turn_notified'));
    }

    public function test_only_the_department_where_someone_finished_is_looked_at(): void
    {
        $this->waiting(1, $this->consultation);
        $this->waiting(2, $this->laboratory);
        $otherFacility = Facility::factory()->create(['notification_channels' => ['sms']]);
        $foreign = Department::factory()->for($otherFacility)->create();
        Visit::factory()->for($otherFacility)->create([
            'department_id' => $foreign->id,
            'patient_id' => Patient::factory()->for($otherFacility)->create()->id,
            'queue_number' => 3,
            'status' => VisitStatus::Waiting,
        ]);

        $this->notify($this->justCompleted($this->consultation));

        $this->assertSame([1], $this->told());
    }

    public function test_only_people_who_are_waiting_count(): void
    {
        $this->waiting(1, attributes: ['status' => VisitStatus::Called]);
        $this->waiting(2, attributes: ['status' => VisitStatus::InService]);
        $this->waiting(3, attributes: ['status' => VisitStatus::Cancelled]);
        $this->waiting(4);

        $this->notify($this->justCompleted());

        $this->assertSame([4], $this->told());
    }

    public function test_yesterdays_leftover_waiting_patients_are_not_texted(): void
    {
        $this->waiting(1, attributes: ['created_at' => now()->subDays(2)]);
        $this->waiting(2);

        $this->notify($this->justCompleted());

        $this->assertSame([2], $this->told());
    }

    public function test_the_front_of_the_queue_is_by_arrival_at_the_department_not_by_registration_number(): void
    {
        $this->waiting(40, attributes: ['department_entered_at' => now()->subMinutes(30)]);
        $this->waiting(41, attributes: ['department_entered_at' => now()->subMinutes(20)]);
        $this->waiting(3, attributes: ['department_entered_at' => now()->subMinute(), 'department_queue_number' => 7]);

        $this->notify($this->justCompleted());

        $this->assertSame([40, 41], $this->told());
    }

    public function test_a_facility_that_did_not_choose_sms_sends_nothing_and_marks_nobody(): void
    {
        $this->facility->update(['notification_channels' => ['email']]);
        $this->waiting(1);
        $this->waiting(2);

        $this->notify($this->justCompleted());

        $this->assertSame(0, PatientNotification::count());
        $this->assertSame(0, Visit::where('almost_turn_notified', true)->count());
    }

    public function test_a_completed_visit_with_no_department_tells_nobody(): void
    {
        $this->waiting(1);
        $completed = $this->justCompleted();
        $completed->update(['department_id' => null]);

        $this->notify($completed->fresh());

        $this->assertSame(0, PatientNotification::count());
    }

    public function test_an_empty_queue_or_a_queue_of_one_is_fine(): void
    {
        $this->notify($this->justCompleted());
        $this->assertSame(0, PatientNotification::count());

        $this->waiting(1);
        $this->notify($this->justCompleted());
        $this->assertSame([1], $this->told());
    }

    public function test_someone_recalled_to_waiting_after_being_told_is_not_told_again(): void
    {
        $visit = $this->waiting(1);
        $this->notify($this->justCompleted());

        $visit->update(['status' => VisitStatus::Called]);
        $visit->update(['status' => VisitStatus::Waiting]);
        $this->notify($this->justCompleted());

        $this->assertSame([1], $this->told());
    }

    public function test_two_completions_at_the_same_moment_do_not_text_the_same_person_twice(): void
    {
        $first = $this->waiting(1);
        $this->waiting(2);
        $raced = false;

        // Just after this run has picked who is at the front, a second completion "gets there first" and claims number 1.
        DB::listen(function (QueryExecuted $query) use (&$raced, $first) {
            if ($raced || ! str_contains($query->sql, 'coalesce(department_entered_at, created_at)')) {
                return;
            }

            $raced = true;
            DB::table('visits')->where('id', $first->id)->update(['almost_turn_notified' => true]);
        });

        $this->notify($this->justCompleted());

        $this->assertTrue($raced);
        // Number 1 is left to whoever claimed it; this run only told number 2.
        $this->assertSame([2], $this->told());
    }

    public function test_someone_still_on_their_way_who_is_near_the_front_is_told_to_come_now(): void
    {
        $onTheirWay = $this->waiting(1, attributes: ['status' => VisitStatus::AwaitingArrival]);
        $here = $this->waiting(2);
        $this->waiting(3);

        $this->notify($this->justCompleted());

        $this->assertSame([1, 2], $this->told());
        $this->assertSame(
            "Upendo Clinic: You're almost up in Consultation. Please come to the facility now.",
            PatientNotification::where('visit_id', $onTheirWay->id)->sole()->message,
        );
        $this->assertSame(
            "Upendo Clinic: You're almost up in Consultation. Please be ready.",
            PatientNotification::where('visit_id', $here->id)->sole()->message,
        );
    }
}
