<?php

namespace Tests\Feature\Services;

use App\Enums\DepartmentType;
use App\Enums\VisitStatus;
use App\Models\Department;
use App\Models\DepartmentWaitEstimate;
use App\Models\Facility;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Services\DoctorRecommender;
use App\Support\DoctorOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DoctorRecommenderTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    private Department $consultation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facility = Facility::factory()->create();
        $this->consultation = Department::factory()->for($this->facility)->assigningDoctors()->create(['type' => DepartmentType::Consultation]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function doctor(string $name, bool $onDuty = true, array $attributes = []): User
    {
        $factory = User::factory()->for($this->facility)->doctor();

        return ($onDuty ? $factory->onDuty() : $factory)->create([
            'name' => $name,
            'department_id' => $this->consultation->id,
            ...$attributes,
        ]);
    }

    private function queueUp(User $doctor, int $patients, VisitStatus $status = VisitStatus::Waiting): void
    {
        foreach (range(1, $patients) as $place) {
            Visit::factory()->inLineOf($doctor, $place)->create(['status' => $status]);
        }
    }

    /**
     * @param  list<DoctorOption>  $options
     * @return list<string>
     */
    private function names(array $options): array
    {
        return array_map(fn (DoctorOption $option) => $option->doctor->name, $options);
    }

    private function rank(?int $serviceId = null): array
    {
        return app(DoctorRecommender::class)->rank($this->consultation->id, $serviceId, $this->facility->id);
    }

    public function test_the_doctor_with_the_shortest_line_comes_first_and_is_the_only_one_recommended(): void
    {
        $this->queueUp($this->doctor('Dr. Otieno'), 7);
        $this->queueUp($this->doctor('Dr. Wanjiku'), 2);
        $this->queueUp($this->doctor('Dr. Kamau'), 4);

        $ranked = $this->rank();

        $this->assertSame(['Dr. Wanjiku', 'Dr. Kamau', 'Dr. Otieno'], $this->names($ranked));
        $this->assertSame([2, 4, 7], array_map(fn (DoctorOption $option) => $option->activeCount, $ranked));
        $this->assertSame([true, false, false], array_map(fn (DoctorOption $option) => $option->recommended, $ranked));
    }

    public function test_a_doctor_who_is_off_duty_never_appears(): void
    {
        $this->doctor('Dr. Away', onDuty: false);
        $this->doctor('Dr. Here');

        $this->assertSame(['Dr. Here'], $this->names($this->rank()));
    }

    public function test_only_active_doctors_of_this_department_and_facility_are_considered(): void
    {
        $this->doctor('Dr. Suspended', attributes: ['status' => 'suspended']);
        $this->doctor('Dr. Elsewhere', attributes: ['department_id' => Department::factory()->for($this->facility)->create()->id]);
        User::factory()->for(Facility::factory()->create())->doctor()->onDuty()->create(['name' => 'Dr. Other Facility', 'department_id' => $this->consultation->id]);
        User::factory()->for($this->facility)->nurse()->create(['name' => 'Nurse Joy', 'department_id' => $this->consultation->id, 'is_on_duty' => true]);
        $this->doctor('Dr. Fit');

        $this->assertSame(['Dr. Fit'], $this->names($this->rank()));
    }

    public function test_nobody_is_recommended_when_no_doctor_is_on_duty(): void
    {
        $this->doctor('Dr. Away', onDuty: false);

        $this->assertSame([], $this->rank());
    }

    public function test_a_service_keeps_only_the_doctors_with_that_specialty(): void
    {
        $paediatrics = Service::factory()->for($this->consultation)->create(['name' => 'Pediatrics']);
        $general = Service::factory()->for($this->consultation)->create(['name' => 'General Medicine']);
        $this->doctor('Dr. Child', attributes: ['service_id' => $paediatrics->id]);
        $this->doctor('Dr. General', attributes: ['service_id' => $general->id]);
        $this->doctor('Dr. Unspecialised');

        $this->assertSame(['Dr. Child'], $this->names($this->rank($paediatrics->id)));
        $this->assertCount(3, $this->rank(), 'Without a service, every doctor on duty fits.');
    }

    public function test_only_todays_open_patients_in_the_doctors_line_count(): void
    {
        $doctor = $this->doctor('Dr. Wanjiku');

        Visit::factory()->inLineOf($doctor, 1)->create(['status' => VisitStatus::Waiting]);
        Visit::factory()->inLineOf($doctor, 2)->create(['status' => VisitStatus::Called]);
        Visit::factory()->inLineOf($doctor, 3)->create(['status' => VisitStatus::InService]);
        Visit::factory()->inLineOf($doctor, 4)->create(['status' => VisitStatus::Completed]);
        Visit::factory()->inLineOf($doctor, 5)->create(['status' => VisitStatus::Cancelled]);
        Visit::factory()->inLineOf($doctor, 6)->create(['status' => VisitStatus::Waiting, 'created_at' => now()->subDays(2)]);
        // Sent on to the laboratory: still theirs as history, but not in their line any more.
        Visit::factory()->inLineOf($doctor, 7)->create(['status' => VisitStatus::Waiting, 'doctor_queue_number' => null]);

        $this->assertSame(3, $this->rank()[0]->activeCount);
    }

    public function test_equal_lines_are_ordered_by_name_so_the_list_never_shuffles(): void
    {
        $this->doctor('Dr. Zawadi');
        $this->doctor('Dr. Amani');

        $this->assertSame(['Dr. Amani', 'Dr. Zawadi'], $this->names($this->rank()));
        $this->assertTrue($this->rank()[0]->recommended);
    }

    public function test_the_estimate_is_the_line_times_the_departments_time_per_patient_as_a_range(): void
    {
        $this->queueUp($this->doctor('Dr. Wanjiku'), 2);
        $this->doctor('Dr. Idle');
        DepartmentWaitEstimate::factory()->create(['department_id' => $this->consultation->id, 'avg_minutes' => 10, 'sample_size' => 30]);

        $byName = collect($this->rank())->keyBy(fn (DoctorOption $option) => $option->doctor->name);

        // Two patients at ten minutes each is twenty, give or take a fifth, in steps of five.
        $this->assertSame('15–25 minutes', $byName['Dr. Wanjiku']->estimate->label());
        $this->assertSame('No wait', $byName['Dr. Idle']->toArray()['estimate']);
    }
}
