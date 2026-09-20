<?php

namespace Tests\Feature\Actions;

use App\Actions\RegisterPatientVisit;
use App\Enums\VisitEventType;
use App\Models\Department;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\QueueCounter;
use App\Models\User;
use App\Models\Visit;
use App\Services\QueueNumberGenerator;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class RegisterPatientVisitTest extends TestCase
{
    use RefreshDatabase;

    private User $receptionist;

    protected function setUp(): void
    {
        parent::setUp();

        $this->receptionist = User::factory()->for(Facility::factory())->receptionist()->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function data(array $overrides = []): array
    {
        return ['phone' => '+254712345678', 'name' => 'Wanjiru Kamau', ...$overrides];
    }

    public function test_creates_the_patient_and_the_visit_together(): void
    {
        $visit = app(RegisterPatientVisit::class)->handle($this->receptionist, $this->data());

        $this->assertSame(1, $visit->queue_number);
        $this->assertSame(Patient::sole()->id, $visit->patient_id);
        $this->assertSame($this->receptionist->id, $visit->created_by);
    }

    public function test_registering_starts_the_visits_audit_log_with_a_registered_event(): void
    {
        $department = Department::factory()->for($this->receptionist->facility)->create();

        $visit = app(RegisterPatientVisit::class)->handle($this->receptionist, $this->data(['department_id' => $department->id]));

        $event = $visit->events->sole();
        $this->assertSame(VisitEventType::Registered, $event->event);
        $this->assertSame($this->receptionist->id, $event->user_id);
        $this->assertSame($department->id, $event->department_id);
    }

    public function test_a_new_visit_records_when_it_joined_its_first_queue_and_has_no_local_number_yet(): void
    {
        $this->travelTo(now()->setDateTime(2026, 9, 18, 9, 15, 0));

        $visit = app(RegisterPatientVisit::class)->handle($this->receptionist, $this->data());

        $this->assertSame('2026-09-18 09:15:00', $visit->department_entered_at->toDateTimeString());
        $this->assertNull($visit->department_queue_number);
    }

    public function test_a_failed_registration_leaves_no_audit_entry_behind(): void
    {
        try {
            app(RegisterPatientVisit::class)->handle($this->receptionist, $this->data(['department_id' => 999999]));
        } catch (QueryException) {
        }

        $this->assertDatabaseCount('visit_events', 0);
    }

    public function test_nothing_is_left_behind_if_the_queue_number_cannot_be_taken(): void
    {
        $this->mock(QueueNumberGenerator::class)
            ->shouldReceive('next')->once()->andThrow(new RuntimeException('counter unavailable'));

        try {
            app(RegisterPatientVisit::class)->handle($this->receptionist, $this->data());
            $this->fail('The failure should have propagated.');
        } catch (RuntimeException $exception) {
            $this->assertSame('counter unavailable', $exception->getMessage());
        }

        $this->assertDatabaseCount('patients', 0);
        $this->assertDatabaseCount('visits', 0);
    }

    public function test_a_visit_that_cannot_be_saved_leaves_no_patient_and_does_not_use_up_a_number(): void
    {
        try {
            // A department that does not exist: the visit insert breaks its foreign key.
            app(RegisterPatientVisit::class)->handle($this->receptionist, $this->data(['department_id' => 999999]));
            $this->fail('The visit should not have been saved.');
        } catch (QueryException) {
        }

        $this->assertDatabaseCount('patients', 0);
        $this->assertDatabaseCount('visits', 0);
        $this->assertSame(0, QueueCounter::sum('last_number'));

        $visit = app(RegisterPatientVisit::class)->handle($this->receptionist, $this->data());

        $this->assertSame(1, $visit->queue_number);
    }

    public function test_losing_a_race_to_create_the_same_new_patient_reuses_the_winner_instead_of_failing(): void
    {
        $raced = false;

        // Just after our lookup finds no patient, "another registration" creates
        // that patient, so our own insert then hits the unique key.
        DB::listen(function (QueryExecuted $query) use (&$raced) {
            if ($raced || ! str_starts_with($query->sql, 'select') || ! str_contains($query->sql, 'patients')) {
                return;
            }

            $raced = true;

            DB::table('patients')->insert([
                'facility_id' => $query->bindings[0],
                'phone' => $query->bindings[1],
                'name' => 'Winner',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $visit = app(RegisterPatientVisit::class)->handle($this->receptionist, $this->data(['name' => 'Latecomer']));

        $this->assertTrue($raced);
        $this->assertDatabaseCount('patients', 1);
        $this->assertSame(Patient::sole()->id, $visit->patient_id);
        $this->assertSame(1, $visit->queue_number);
        $this->assertSame('Latecomer', Patient::sole()->name);
    }

    public function test_a_returning_patient_is_reused_not_recreated(): void
    {
        $action = app(RegisterPatientVisit::class);

        $first = $action->handle($this->receptionist, $this->data());
        $second = $action->handle($this->receptionist, $this->data(['name' => 'Wanjiru K.']));

        $this->assertSame($first->patient_id, $second->patient_id);
        $this->assertSame([1, 2], Visit::orderBy('id')->pluck('queue_number')->all());
        $this->assertSame('Wanjiru K.', Patient::sole()->name);
    }

    public function test_the_visit_keeps_a_hash_of_the_pin_it_was_given_and_never_the_pin(): void
    {
        $visit = app(RegisterPatientVisit::class)->handle($this->receptionist, $this->data(), '7394');

        $this->assertTrue(Hash::check('7394', $visit->access_pin_hash));
        $this->assertNotSame('7394', $visit->access_pin_hash);
        $this->assertDatabaseMissing('visits', ['access_pin_hash' => '7394']);
    }

    public function test_a_visit_registered_without_a_pin_still_gets_a_hash_so_none_is_left_without_one(): void
    {
        $visit = app(RegisterPatientVisit::class)->handle($this->receptionist, $this->data());

        $this->assertNotNull($visit->access_pin_hash);
        $this->assertNotEmpty(Hash::info($visit->access_pin_hash)['algoName']);
    }
}
