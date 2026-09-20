<?php

namespace Tests\Feature\Notifications;

use App\Actions\RegisterPatientVisit;
use App\Enums\DepartmentType;
use App\Enums\NotificationStatus;
use App\Enums\VisitStatus;
use App\Models\Department;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\PatientNotification;
use App\Models\User;
use App\Models\Visit;
use App\Services\SmsSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\FakesSmsGateway;
use Tests\TestCase;

/**
 * The whole journey a patient's phone sees, through the same HTTP routes staff
 * use, with the gateway faked at the network edge.
 */
class PatientSmsFlowTest extends TestCase
{
    use FakesSmsGateway;
    use RefreshDatabase;

    private Facility $facility;

    private Department $reception;

    private Department $consultation;

    private Department $laboratory;

    private User $receptionist;

    private User $doctor;

    private User $labTech;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureGateway();

        $this->facility = Facility::factory()->create([
            'name' => 'Upendo Clinic',
            'notification_channels' => ['sms'],
            'sms_sender_id' => 'UPENDO',
        ]);
        $this->reception = Department::factory()->for($this->facility)->create(['name' => 'Reception', 'type' => DepartmentType::Reception]);
        $this->consultation = Department::factory()->for($this->facility)->create(['name' => 'Consultation', 'type' => DepartmentType::Consultation]);
        $this->laboratory = Department::factory()->for($this->facility)->create(['name' => 'Laboratory', 'type' => DepartmentType::Laboratory]);
        $this->receptionist = User::factory()->for($this->facility)->receptionist()->create(['department_id' => $this->reception->id]);
        $this->doctor = User::factory()->for($this->facility)->doctor()->create(['department_id' => $this->consultation->id]);
        $this->labTech = User::factory()->for($this->facility)->nurse()->create(['department_id' => $this->laboratory->id]);
    }

    private function register(string $name = 'Wanjiru Kamau', string $phone = '0712345678', ?Department $into = null): void
    {
        $this->actingAs($this->receptionist)
            ->post(route('patients.register.store'), [
                'phone' => $phone,
                'name' => $name,
                'dob' => '',
                'gender' => '',
                'department_id' => ($into ?? $this->consultation)->id,
            ])
            ->assertSessionHasNoErrors();
    }

    private function waitingVisit(string $name = 'Brian Otieno', int $number = 5, ?Department $department = null, VisitStatus $status = VisitStatus::Waiting): Visit
    {
        return Visit::factory()->for($this->facility)->create([
            'department_id' => ($department ?? $this->consultation)->id,
            'patient_id' => Patient::factory()->for($this->facility)->create(['name' => $name, 'phone' => sprintf('+254722000%03d', $number)])->id,
            'queue_number' => $number,
            'status' => $status,
            'department_entered_at' => now()->subMinutes(60 - $number),
        ]);
    }

    /**
     * @return list<string>
     */
    private function messages(): array
    {
        return PatientNotification::orderBy('id')->pluck('message')->all();
    }

    private function act(User $as, string $route, Visit $visit, ?Department $to = null): void
    {
        $this->actingAs($as)
            ->post($to ? route($route, [$visit, $to]) : route($route, $visit))
            ->assertRedirect(route('queue.index'))
            ->assertSessionHasNoErrors();
    }

    public function test_registering_a_patient_texts_their_queue_number(): void
    {
        $this->gatewayAccepts();

        $this->register();

        $number = Visit::sole()->queue_number;
        $notification = PatientNotification::sole();
        $this->assertSame("Upendo Clinic: Hi Wanjiru, your queue number is {$number}. Track your visit: ".Visit::sole()->trackingUrl(), $notification->message);
        $this->assertSame(NotificationStatus::Sent, $notification->status);
        $this->assertSame(Visit::sole()->id, $notification->visit_id);
        Http::assertSent(fn (Request $request) => $request['to'] === '+254712345678'
            && $request['from'] === 'UPENDO'
            && $request['message'] === $notification->message);
    }

    public function test_calling_a_patient_texts_that_it_is_their_turn(): void
    {
        $this->gatewayAccepts();
        $visit = $this->waitingVisit();

        $this->act($this->doctor, 'queue.call', $visit);

        $this->assertSame(["Upendo Clinic: It's your turn - please proceed to Consultation."], $this->messages());
        $this->assertSame(NotificationStatus::Sent, PatientNotification::sole()->status);
        Http::assertSent(fn (Request $request) => $request['to'] === '+254722000005');
    }

    public function test_transferring_a_patient_texts_the_new_department_and_the_local_number(): void
    {
        $this->gatewayAccepts();
        $visit = $this->waitingVisit(status: VisitStatus::InService);

        $this->act($this->doctor, 'queue.transfer', $visit, $this->laboratory);

        $this->assertSame(["Upendo Clinic: You've been moved to Laboratory. Your number: L-1."], $this->messages());
    }

    public function test_completing_a_visit_texts_a_thank_you(): void
    {
        $this->gatewayAccepts();
        $visit = $this->waitingVisit(status: VisitStatus::InService);

        $this->act($this->doctor, 'queue.complete', $visit);

        $this->assertSame(['Upendo Clinic: Your visit is complete. Thank you for choosing Upendo Clinic.'], $this->messages());
    }

    public function test_starting_or_cancelling_sends_nothing(): void
    {
        $this->gatewayAccepts();
        $called = $this->waitingVisit('Called Person', 1, status: VisitStatus::Called);
        $toCancel = $this->waitingVisit('Cancelled Person', 2);

        $this->act($this->doctor, 'queue.start', $called);
        $this->act($this->doctor, 'queue.cancel', $toCancel);

        $this->assertSame(0, PatientNotification::count());
        Http::assertNothingSent();
    }

    public function test_a_full_visit_produces_each_message_once_in_order(): void
    {
        $this->gatewayAccepts();

        $this->register();
        $visit = Visit::sole();
        $this->act($this->doctor, 'queue.call', $visit);
        $this->act($this->doctor, 'queue.start', $visit);
        $this->act($this->doctor, 'queue.transfer', $visit, $this->laboratory);
        $this->act($this->labTech, 'queue.call', $visit);
        $this->act($this->labTech, 'queue.start', $visit);
        $this->act($this->labTech, 'queue.complete', $visit);

        $this->assertSame([
            "Upendo Clinic: Hi Wanjiru, your queue number is {$visit->queue_number}. Track your visit: {$visit->trackingUrl()}",
            "Upendo Clinic: It's your turn - please proceed to Consultation.",
            "Upendo Clinic: You've been moved to Laboratory. Your number: L-1.",
            "Upendo Clinic: It's your turn - please proceed to Laboratory.",
            'Upendo Clinic: Your visit is complete. Thank you for choosing Upendo Clinic.',
        ], $this->messages());
        $this->assertSame(0, PatientNotification::where('status', '!=', NotificationStatus::Sent)->count());
    }

    public function test_finishing_with_the_patient_in_front_tells_each_of_the_next_two_they_are_almost_up_exactly_once(): void
    {
        $this->gatewayAccepts();
        $serving = $this->waitingVisit('Being Served', 1, status: VisitStatus::InService);
        $this->waitingVisit('Second Person', 2);
        $this->waitingVisit('Third Person', 3);
        $this->waitingVisit('Fourth Person', 4);

        $this->act($this->doctor, 'queue.complete', $serving);

        $almostUp = PatientNotification::where('message', 'like', '%almost up%')->get();
        $this->assertCount(2, $almostUp);
        $this->assertEqualsCanonicalizing(
            ['Second Person', 'Third Person'],
            $almostUp->map(fn ($n) => $n->patient->name)->all(),
        );

        // Another completion right after: the same two are not told again.
        $another = $this->waitingVisit('Another Served', 9, status: VisitStatus::InService);
        $this->act($this->doctor, 'queue.complete', $another);

        $this->assertSame(2, PatientNotification::where('message', 'like', '%almost up%')->count());
    }

    public function test_a_wrong_api_key_never_breaks_call_start_complete_or_transfer(): void
    {
        $this->gatewayRejects(401, 'The supplied authentication is invalid');

        $this->register();
        $visit = Visit::sole();

        $this->act($this->doctor, 'queue.call', $visit);
        $this->assertSame(VisitStatus::Called, $visit->fresh()->status);

        $this->act($this->doctor, 'queue.start', $visit);
        $this->assertSame(VisitStatus::InService, $visit->fresh()->status);

        $this->act($this->doctor, 'queue.transfer', $visit, $this->laboratory);
        $this->assertSame($this->laboratory->id, $visit->fresh()->department_id);
        $this->assertSame(VisitStatus::Waiting, $visit->fresh()->status);

        $this->act($this->labTech, 'queue.call', $visit);
        $this->act($this->labTech, 'queue.start', $visit);
        $this->act($this->labTech, 'queue.complete', $visit);
        $this->assertSame(VisitStatus::Completed, $visit->fresh()->status);

        // Every message is on record as failed, with the reason.
        $this->assertSame(5, PatientNotification::count());
        $this->assertSame(5, PatientNotification::where('status', NotificationStatus::Failed)->count());
        $this->assertStringContainsString('The supplied authentication is invalid', PatientNotification::first()->provider_response);
    }

    public function test_a_wrong_api_key_does_not_break_registration_either(): void
    {
        $this->gatewayRejects(401);

        $this->register();

        $this->assertSame(1, Visit::count());
        $this->assertSame(NotificationStatus::Failed, PatientNotification::sole()->status);
    }

    public function test_missing_credentials_do_not_break_the_queue_and_never_reach_the_network(): void
    {
        config(['services.africastalking.api_key' => null, 'services.africastalking.username' => null]);

        $this->register();
        $visit = Visit::sole();
        $this->act($this->doctor, 'queue.call', $visit);

        $this->assertSame(VisitStatus::Called, $visit->fresh()->status);
        $this->assertSame(2, PatientNotification::where('status', NotificationStatus::Failed)->count());
        Http::assertNothingSent();
    }

    public function test_a_gateway_that_cannot_be_reached_does_not_break_the_queue(): void
    {
        Sleep::fake();
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));
        $visit = $this->waitingVisit();

        $this->act($this->doctor, 'queue.call', $visit);

        $this->assertSame(VisitStatus::Called, $visit->fresh()->status);
        $this->assertSame(NotificationStatus::Failed, PatientNotification::sole()->status);
        $this->assertStringContainsString('timed out', PatientNotification::sole()->provider_response);
    }

    public function test_a_gateway_that_accepts_no_one_marks_the_message_failed(): void
    {
        // 403 = invalid phone number, reported per recipient on an HTTP 201.
        $this->gatewayAccepts(403);

        $this->register();

        $this->assertSame(1, Visit::count());
        $this->assertSame(NotificationStatus::Failed, PatientNotification::sole()->status);
    }

    public function test_even_a_bug_in_the_sms_code_cannot_break_the_queue(): void
    {
        Exceptions::fake();
        $this->mock(SmsSender::class)->shouldReceive('send')->andThrow(new RuntimeException('sms code exploded'));
        $visit = $this->waitingVisit(status: VisitStatus::InService);

        $this->act($this->doctor, 'queue.complete', $visit);

        $this->assertSame(VisitStatus::Completed, $visit->fresh()->status);
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'sms code exploded');
    }

    public function test_a_bug_in_a_message_template_cannot_break_registration(): void
    {
        Exceptions::fake();
        config(['notification_templates' => null]);

        $this->register();

        $this->assertSame(1, Visit::count());
        Exceptions::assertReportedCount(1);
    }

    /**
     * @return array<string, array{list<string>}>
     */
    public static function channelsWithoutSms(): array
    {
        return [
            'email only' => [['email']],
            'whatsapp only' => [['whatsapp']],
            'none chosen' => [[]],
        ];
    }

    /**
     * @param  list<string>  $channels
     */
    #[DataProvider('channelsWithoutSms')]
    public function test_a_facility_that_did_not_choose_sms_never_sends_one_and_no_record_is_made(array $channels): void
    {
        $this->gatewayAccepts();
        $this->facility->update(['notification_channels' => $channels]);
        $serving = $this->waitingVisit('Being Served', 1, status: VisitStatus::InService);
        $this->waitingVisit('Next Person', 2);
        $called = $this->waitingVisit('Called Person', 3);
        $moved = $this->waitingVisit('Moved Person', 4, status: VisitStatus::InService);

        $this->register();
        $this->act($this->doctor, 'queue.call', $called);
        $this->act($this->doctor, 'queue.transfer', $moved, $this->laboratory);
        $this->act($this->doctor, 'queue.complete', $serving);

        $this->assertSame(0, PatientNotification::count());
        Http::assertNothingSent();
        // ...and the queue itself carried on as normal.
        $this->assertSame(VisitStatus::Completed, $serving->fresh()->status);
        $this->assertSame(VisitStatus::Called, $called->fresh()->status);
        $this->assertSame($this->laboratory->id, $moved->fresh()->department_id);
    }

    public function test_a_registration_that_rolls_back_sends_nothing(): void
    {
        $this->gatewayAccepts();

        try {
            DB::transaction(function () {
                app(RegisterPatientVisit::class)->handle($this->receptionist, [
                    'phone' => '+254712345678',
                    'name' => 'Wanjiru Kamau',
                    'department_id' => $this->consultation->id,
                ]);

                throw new RuntimeException('something later in the request failed');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, Visit::count());
        $this->assertSame(0, PatientNotification::count());
        Http::assertNothingSent();
    }

    public function test_the_default_sender_id_is_used_when_the_facility_has_none(): void
    {
        $this->gatewayAccepts();
        $this->facility->update(['sms_sender_id' => null]);

        $this->register();

        Http::assertSent(fn (Request $request) => $request['from'] === 'CAREFLOW');
    }

    public function test_every_message_fits_in_one_sms(): void
    {
        $this->gatewayAccepts();
        $this->facility->update(['name' => 'Upendo Community Health Centre']);
        $visit = $this->waitingVisit(status: VisitStatus::InService);
        $this->waitingVisit('Next Person', 6);

        $this->register('Wanjiru Kamau-Mwangi');
        $this->act($this->doctor, 'queue.transfer', $visit, $this->laboratory);
        $this->act($this->labTech, 'queue.call', $visit);
        $this->act($this->labTech, 'queue.start', $visit);
        $this->act($this->labTech, 'queue.complete', $visit);

        foreach (PatientNotification::all() as $notification) {
            $this->assertLessThanOrEqual(160, mb_strlen($notification->message), $notification->message);
        }
    }
}
