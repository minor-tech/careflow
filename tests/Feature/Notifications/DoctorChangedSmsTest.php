<?php

namespace Tests\Feature\Notifications;

use App\Enums\DepartmentType;
use App\Enums\NotificationStatus;
use App\Models\Department;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\PatientNotification;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesSmsGateway;
use Tests\TestCase;

/**
 * The text a patient gets when their doctor is changed, through the same route
 * staff use, with the gateway faked at the network edge.
 */
class DoctorChangedSmsTest extends TestCase
{
    use FakesSmsGateway;
    use RefreshDatabase;

    private Facility $facility;

    private Department $consultation;

    private User $wanjiku;

    private User $kamau;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureGateway();

        $this->facility = Facility::factory()->create(['name' => 'Upendo Clinic', 'notification_channels' => ['sms'], 'sms_sender_id' => 'UPENDO']);
        $this->consultation = Department::factory()->for($this->facility)->assigningDoctors()->create(['name' => 'Consultation', 'type' => DepartmentType::Consultation]);
        $this->wanjiku = User::factory()->for($this->facility)->doctor()->onDuty()->create(['name' => 'Wanjiku Mwangi', 'department_id' => $this->consultation->id]);
        $this->kamau = User::factory()->for($this->facility)->doctor()->onDuty()->create(['name' => 'Kamau Njoroge', 'department_id' => $this->consultation->id]);
        $this->admin = User::factory()->for($this->facility)->create();
    }

    private function patientOfWanjiku(): Visit
    {
        return Visit::factory()->inLineOf($this->wanjiku, 1)->create([
            'patient_id' => Patient::factory()->for($this->facility)->create(['name' => 'Brian Otieno', 'phone' => '+254722000005'])->id,
        ]);
    }

    private function handOver(Visit $visit)
    {
        return $this->actingAs($this->admin)->post(route('queue.reassign-doctor', $visit), ['doctor_id' => $this->kamau->id, 'reason' => 'Emergency']);
    }

    public function test_handing_a_patient_to_another_doctor_texts_them_the_new_doctor_and_their_new_number(): void
    {
        $this->gatewayAccepts();
        $visit = $this->patientOfWanjiku();

        $this->handOver($visit)->assertSessionHasNoErrors();

        $notification = PatientNotification::sole();
        $this->assertSame('Upendo Clinic: Your assigned doctor has changed to Dr. Kamau Njoroge. Your number: C-1.', $notification->message);
        $this->assertSame(NotificationStatus::Sent, $notification->status);
        $this->assertSame($visit->id, $notification->visit_id);
        Http::assertSent(fn (Request $request) => $request['to'] === '+254722000005' && $request['message'] === $notification->message);
    }

    public function test_the_text_fits_in_one_sms_for_a_long_facility_and_doctor_name(): void
    {
        $this->gatewayAccepts();
        $this->facility->update(['name' => 'Upendo Community Health Centre']);
        $this->kamau->update(['name' => 'Kamau Njoroge-Wainaina']);

        $this->handOver($this->patientOfWanjiku());

        $this->assertLessThanOrEqual(160, mb_strlen(PatientNotification::sole()->message));
    }

    public function test_a_refused_handover_sends_nothing(): void
    {
        $this->gatewayAccepts();
        $visit = $this->patientOfWanjiku();

        $this->actingAs($this->admin)->post(route('queue.reassign-doctor', $visit), ['doctor_id' => $this->wanjiku->id, 'reason' => 'Same doctor'])
            ->assertSessionHasErrors('queue');

        $this->assertSame(0, PatientNotification::count());
    }

    public function test_a_facility_that_did_not_choose_sms_sends_nothing_and_the_handover_still_happens(): void
    {
        $this->gatewayAccepts();
        $this->facility->update(['notification_channels' => ['email']]);
        $visit = $this->patientOfWanjiku();

        $this->handOver($visit)->assertSessionHasNoErrors();

        $this->assertSame($this->kamau->id, $visit->fresh()->assigned_doctor_id);
        $this->assertSame(0, PatientNotification::count());
    }

    public function test_a_gateway_that_rejects_us_never_breaks_the_handover(): void
    {
        $this->gatewayRejects();
        $visit = $this->patientOfWanjiku();

        $this->handOver($visit)->assertSessionHasNoErrors();

        $this->assertSame($this->kamau->id, $visit->fresh()->assigned_doctor_id);
        $this->assertSame(NotificationStatus::Failed, PatientNotification::sole()->status);
    }
}
