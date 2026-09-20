<?php

namespace Tests\Feature\Services;

use App\Models\Department;
use App\Models\Facility;
use App\Models\QueueCounter;
use App\Models\User;
use App\Models\Visit;
use App\Services\QueueNumberGenerator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class QueueNumberGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private QueueNumberGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->generator = new QueueNumberGenerator;
    }

    public function test_hands_out_1_then_2_then_3_in_order(): void
    {
        $facility = Facility::factory()->create();

        $numbers = [
            $this->generator->next($facility->id),
            $this->generator->next($facility->id),
            $this->generator->next($facility->id),
        ];

        $this->assertSame([1, 2, 3], $numbers);
    }

    public function test_each_facility_has_its_own_sequence(): void
    {
        $first = Facility::factory()->create();
        $second = Facility::factory()->create();

        $this->generator->next($first->id);
        $this->generator->next($first->id);

        $this->assertSame(1, $this->generator->next($second->id));
        $this->assertSame(3, $this->generator->next($first->id));
    }

    public function test_each_department_has_its_own_sequence_separate_from_the_facility_wide_one(): void
    {
        $facility = Facility::factory()->create();
        $reception = Department::factory()->for($facility)->create();
        $laboratory = Department::factory()->for($facility)->create();

        foreach (range(1, 27) as $ignored) {
            $this->generator->next($facility->id);
        }

        $this->assertSame(1, $this->generator->next($facility->id, $laboratory->id));
        $this->assertSame(2, $this->generator->next($facility->id, $laboratory->id));
        $this->assertSame(1, $this->generator->next($facility->id, $reception->id));
        $this->assertSame(3, $this->generator->next($facility->id, $laboratory->id));
        $this->assertSame(28, $this->generator->next($facility->id));
    }

    public function test_the_same_department_id_at_another_facility_is_not_confused_with_it(): void
    {
        $first = Facility::factory()->create();
        $second = Facility::factory()->create();
        $firstLab = Department::factory()->for($first)->create();
        $secondLab = Department::factory()->for($second)->create();

        $this->generator->next($first->id, $firstLab->id);
        $this->generator->next($first->id, $firstLab->id);

        $this->assertSame(1, $this->generator->next($second->id, $secondLab->id));
    }

    public function test_a_departments_numbers_restart_at_1_each_day(): void
    {
        $facility = Facility::factory()->create();
        $laboratory = Department::factory()->for($facility)->create();

        $this->travelTo(now()->setDateTime(2026, 9, 18, 9, 0, 0)->utc());
        $this->generator->next($facility->id, $laboratory->id);
        $this->assertSame(2, $this->generator->next($facility->id, $laboratory->id));

        $this->travelTo(now()->setDateTime(2026, 9, 19, 9, 0, 0)->utc());
        $this->assertSame(1, $this->generator->next($facility->id, $laboratory->id));
    }

    public function test_keeps_one_facility_wide_counter_and_one_per_department_per_day(): void
    {
        $facility = Facility::factory()->create();
        $laboratory = Department::factory()->for($facility)->create();

        foreach (range(1, 3) as $ignored) {
            $this->generator->next($facility->id);
            $this->generator->next($facility->id, $laboratory->id);
        }

        $this->assertDatabaseCount('queue_counters', 2);
        $this->assertSame(3, QueueCounter::whereNull('department_id')->sole()->last_number);
        $this->assertSame(3, QueueCounter::where('department_id', $laboratory->id)->sole()->last_number);
    }

    public function test_the_database_refuses_a_second_facility_wide_counter_for_the_same_day(): void
    {
        $facility = Facility::factory()->create();
        QueueCounter::factory()->for($facility)->create(['department_id' => null, 'date' => '2026-09-18']);

        $this->expectException(UniqueConstraintViolationException::class);

        QueueCounter::factory()->for($facility)->create(['department_id' => null, 'date' => '2026-09-18']);
    }

    public function test_two_doctors_in_one_department_each_count_from_1_independently(): void
    {
        $facility = Facility::factory()->create();
        $consultation = Department::factory()->for($facility)->assigningDoctors()->create();
        $wanjiku = User::factory()->for($facility)->doctor()->create(['department_id' => $consultation->id]);
        $kamau = User::factory()->for($facility)->doctor()->create(['department_id' => $consultation->id]);

        $this->assertSame(1, $this->generator->next($facility->id, $consultation->id, $wanjiku->id));
        $this->assertSame(2, $this->generator->next($facility->id, $consultation->id, $wanjiku->id));
        $this->assertSame(1, $this->generator->next($facility->id, $consultation->id, $kamau->id));
        $this->assertSame(3, $this->generator->next($facility->id, $consultation->id, $wanjiku->id));
        $this->assertSame(2, $this->generator->next($facility->id, $consultation->id, $kamau->id));
    }

    public function test_a_doctors_numbers_do_not_touch_the_departments_or_the_facilitys(): void
    {
        $facility = Facility::factory()->create();
        $consultation = Department::factory()->for($facility)->assigningDoctors()->create();
        $doctor = User::factory()->for($facility)->doctor()->create(['department_id' => $consultation->id]);

        $this->generator->next($facility->id, $consultation->id, $doctor->id);
        $this->generator->next($facility->id, $consultation->id, $doctor->id);

        $this->assertSame(1, $this->generator->next($facility->id, $consultation->id));
        $this->assertSame(1, $this->generator->next($facility->id));
        $this->assertDatabaseCount('queue_counters', 3);
    }

    public function test_a_doctors_numbers_restart_at_1_each_day(): void
    {
        $facility = Facility::factory()->create();
        $consultation = Department::factory()->for($facility)->assigningDoctors()->create();
        $doctor = User::factory()->for($facility)->doctor()->create(['department_id' => $consultation->id]);

        $this->travelTo(now()->setDateTime(2026, 9, 18, 9, 0, 0)->utc());
        $this->generator->next($facility->id, $consultation->id, $doctor->id);
        $this->assertSame(2, $this->generator->next($facility->id, $consultation->id, $doctor->id));

        $this->travelTo(now()->setDateTime(2026, 9, 19, 9, 0, 0)->utc());
        $this->assertSame(1, $this->generator->next($facility->id, $consultation->id, $doctor->id));
    }

    public function test_the_database_refuses_a_second_counter_for_the_same_doctor_and_day_but_not_for_another_doctor(): void
    {
        $facility = Facility::factory()->create();
        $consultation = Department::factory()->for($facility)->assigningDoctors()->create();
        $wanjiku = User::factory()->for($facility)->doctor()->create(['department_id' => $consultation->id]);
        $kamau = User::factory()->for($facility)->doctor()->create(['department_id' => $consultation->id]);

        $counter = fn (User $doctor) => QueueCounter::factory()->for($facility)->create([
            'department_id' => $consultation->id,
            'doctor_id' => $doctor->id,
            'date' => '2026-09-18',
        ]);

        $counter($wanjiku);
        $counter($kamau);

        $this->expectException(UniqueConstraintViolationException::class);

        $counter($wanjiku);
    }

    public function test_keeps_a_single_counter_row_per_facility_per_day(): void
    {
        $facility = Facility::factory()->create();

        foreach (range(1, 5) as $ignored) {
            $this->generator->next($facility->id);
        }

        $this->assertDatabaseCount('queue_counters', 1);
        $this->assertSame(5, QueueCounter::sole()->last_number);
    }

    public function test_carries_on_from_a_counter_row_that_already_exists(): void
    {
        $facility = Facility::factory()->create();
        QueueCounter::factory()->for($facility)->create(['date' => $this->generator->today(), 'last_number' => 41]);

        $this->assertSame(42, $this->generator->next($facility->id));
        $this->assertDatabaseCount('queue_counters', 1);
    }

    public function test_takes_the_number_from_the_counter_not_from_a_count_of_visits(): void
    {
        $facility = Facility::factory()->create();
        Visit::factory()->for($facility)->count(5)->create();

        $this->assertSame(1, $this->generator->next($facility->id));

        $facility->visits()->delete();

        $this->assertSame(2, $this->generator->next($facility->id));
    }

    public function test_numbers_restart_at_1_at_local_midnight_in_nairobi_not_at_utc_midnight(): void
    {
        $facility = Facility::factory()->create();

        // 23:00 on the 18th in Nairobi (20:00 UTC).
        $this->travelTo(now()->setDateTime(2026, 9, 18, 20, 0, 0)->utc());
        $this->assertSame(1, $this->generator->next($facility->id));
        $this->assertSame(2, $this->generator->next($facility->id));

        // 01:00 on the 19th in Nairobi (22:00 UTC on the 18th): a new clinic day.
        $this->travelTo(now()->setDateTime(2026, 9, 18, 22, 0, 0)->utc());
        $this->assertSame(1, $this->generator->next($facility->id));

        // 03:30 on the 19th in Nairobi (00:30 UTC on the 19th): UTC has rolled
        // over but the clinic day has not, so the sequence carries on.
        $this->travelTo(now()->setDateTime(2026, 9, 19, 0, 30, 0)->utc());
        $this->assertSame(2, $this->generator->next($facility->id));

        $this->assertSame(
            ['2026-09-18' => 2, '2026-09-19' => 2],
            QueueCounter::orderBy('date')->get()->mapWithKeys(fn (QueueCounter $c) => [$c->date->toDateString() => $c->last_number])->all(),
        );
    }

    public function test_a_number_is_not_used_up_if_the_surrounding_transaction_fails(): void
    {
        $facility = Facility::factory()->create();
        $this->assertSame(1, $this->generator->next($facility->id));

        try {
            DB::transaction(function () use ($facility) {
                $this->generator->next($facility->id);

                throw new RuntimeException('visit could not be saved');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(2, $this->generator->next($facility->id));
    }
}
