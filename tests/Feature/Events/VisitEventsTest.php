<?php

namespace Tests\Feature\Events;

use App\Actions\RegisterPatientVisit;
use App\Enums\DepartmentType;
use App\Enums\VisitStatus;
use App\Events\VisitCalled;
use App\Events\VisitCompleted;
use App\Events\VisitRegistered;
use App\Events\VisitTransferred;
use App\Exceptions\InvalidVisitTransition;
use App\Models\Department;
use App\Models\Facility;
use App\Models\User;
use App\Models\Visit;
use App\Services\VisitStatusTransitioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VisitEventsTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    private Department $consultation;

    private User $staff;

    private VisitStatusTransitioner $transitioner;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([VisitRegistered::class, VisitCalled::class, VisitTransferred::class, VisitCompleted::class]);

        $this->facility = Facility::factory()->create();
        $this->consultation = Department::factory()->for($this->facility)->create(['type' => DepartmentType::Consultation]);
        $this->staff = User::factory()->for($this->facility)->create();
        $this->transitioner = app(VisitStatusTransitioner::class);
    }

    private function visitAt(VisitStatus $status): Visit
    {
        return Visit::factory()->for($this->facility)->create(['department_id' => $this->consultation->id, 'status' => $status]);
    }

    public function test_registering_a_patient_announces_it_once(): void
    {
        $visit = app(RegisterPatientVisit::class)->handle($this->staff, ['phone' => '+254712345678', 'name' => 'Wanjiru Kamau']);

        Event::assertDispatchedTimes(VisitRegistered::class, 1);
        Event::assertDispatched(VisitRegistered::class, fn (VisitRegistered $event) => $event->visit->is($visit));
    }

    public function test_calling_a_patient_announces_it(): void
    {
        $visit = $this->visitAt(VisitStatus::Waiting);

        $this->transitioner->transition($visit, VisitStatus::Called, $this->staff);

        Event::assertDispatchedTimes(VisitCalled::class, 1);
        Event::assertDispatched(VisitCalled::class, fn (VisitCalled $event) => $event->visit->is($visit) && $event->visit->status === VisitStatus::Called);
    }

    public function test_completing_a_visit_announces_it(): void
    {
        $visit = $this->visitAt(VisitStatus::InService);

        $this->transitioner->transition($visit, VisitStatus::Completed, $this->staff);

        Event::assertDispatchedTimes(VisitCompleted::class, 1);
        Event::assertDispatched(VisitCompleted::class, fn (VisitCompleted $event) => $event->visit->is($visit) && $event->visit->status === VisitStatus::Completed);
    }

    public function test_transferring_a_patient_announces_it_with_the_visit_already_in_its_new_department(): void
    {
        $laboratory = Department::factory()->for($this->facility)->create(['type' => DepartmentType::Laboratory]);
        $visit = $this->visitAt(VisitStatus::InService);

        $this->transitioner->transferToDepartment($visit, $laboratory, $this->staff);

        Event::assertDispatchedTimes(VisitTransferred::class, 1);
        Event::assertDispatched(VisitTransferred::class, fn (VisitTransferred $event) => $event->visit->department_id === $laboratory->id);
    }

    /**
     * @return array<string, array{VisitStatus, VisitStatus}>
     */
    public static function movesThatSayNothing(): array
    {
        return [
            'starting' => [VisitStatus::Called, VisitStatus::InService],
            'recalling' => [VisitStatus::Called, VisitStatus::Waiting],
            'cancelling a waiting patient' => [VisitStatus::Waiting, VisitStatus::Cancelled],
            'cancelling a called patient' => [VisitStatus::Called, VisitStatus::Cancelled],
        ];
    }

    #[DataProvider('movesThatSayNothing')]
    public function test_moves_that_are_not_worth_a_message_announce_nothing(VisitStatus $from, VisitStatus $to): void
    {
        $this->transitioner->transition($this->visitAt($from), $to, $this->staff);

        Event::assertNotDispatched(VisitCalled::class);
        Event::assertNotDispatched(VisitCompleted::class);
        Event::assertNotDispatched(VisitTransferred::class);
    }

    public function test_a_move_that_is_refused_announces_nothing(): void
    {
        try {
            $this->transitioner->transition($this->visitAt(VisitStatus::Completed), VisitStatus::Called, $this->staff);
            $this->fail('The move should have been refused.');
        } catch (InvalidVisitTransition) {
        }

        Event::assertNothingDispatched();
    }

    public function test_a_transfer_that_is_refused_announces_nothing(): void
    {
        try {
            $this->transitioner->transferToDepartment($this->visitAt(VisitStatus::Waiting), Department::factory()->for($this->facility)->create(), $this->staff);
        } catch (InvalidVisitTransition) {
        }

        Event::assertNotDispatched(VisitTransferred::class);
    }
}
