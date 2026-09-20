<?php

namespace Tests\Feature\Notifications;

use App\Enums\NotificationStatus;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\PatientNotification;
use App\Models\RemoteRequest;
use App\Models\User;
use App\Models\Visit;
use App\Services\MessageTemplates;
use App\Services\SmsSender;
use App\Support\ArrivalWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesSmsGateway;
use Tests\Concerns\SetsUpRemoteQueue;
use Tests\TestCase;

/**
 * The texts a requester gets, including one to a number that was never a patient.
 */
class RemoteRequestSmsTest extends TestCase
{
    use FakesSmsGateway;
    use RefreshDatabase;
    use SetsUpRemoteQueue;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureGateway();
        $this->setUpRemoteQueue();
    }

    public function test_a_message_to_a_number_that_is_no_patients_is_recorded_with_that_number_and_sent_to_it(): void
    {
        $this->gatewayAccepts();

        $notification = app(SmsSender::class)->sendToNumber($this->facility, '+254712345678', 'Upendo Clinic: hello');

        $this->assertNull($notification->patient_id);
        $this->assertSame('+254712345678', $notification->phone);
        $this->assertSame($this->facility->id, $notification->facility_id);
        $this->assertSame(NotificationStatus::Sent, $notification->fresh()->status);
        Http::assertSent(fn (Request $request) => $request['to'] === '+254712345678' && $request['from'] === 'UPENDO');
    }

    public function test_a_patients_own_message_still_records_no_separate_number_and_uses_the_patients(): void
    {
        $this->gatewayAccepts();
        $patient = Patient::factory()->for($this->facility)->create(['phone' => '+254700111222']);

        $notification = app(SmsSender::class)->send($patient, 'Upendo Clinic: hi');

        $this->assertNull($notification->phone);
        $this->assertSame('+254700111222', $notification->recipientPhone());
        Http::assertSent(fn (Request $request) => $request['to'] === '+254700111222');
    }

    public function test_a_facility_that_did_not_choose_sms_sends_nothing_to_a_number_either(): void
    {
        $this->facility->update(['notification_channels' => ['email']]);

        $this->assertNull(app(SmsSender::class)->sendToNumber($this->facility, '+254712345678', 'hello'));
        $this->assertSame(0, PatientNotification::count());
    }

    public function test_a_rejecting_gateway_marks_such_a_message_failed_without_an_error(): void
    {
        $this->gatewayRejects();

        $notification = app(SmsSender::class)->sendToNumber($this->facility, '+254712345678', 'hello');

        $this->assertSame(NotificationStatus::Failed, $notification->fresh()->status);
    }

    public function test_the_admin_list_shows_such_a_message_by_its_number_not_as_a_patient(): void
    {
        $this->gatewayAccepts();
        app(SmsSender::class)->sendToNumber($this->facility, '+254712345678', 'Upendo Clinic: Sorry, we couldn\'t accept your queue request.');

        $this->actingAs($this->admin)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSeeText('Not a patient')
            ->assertSeeText('+254712345678')
            ->assertSeeText('couldn\'t accept your queue request');
    }

    public function test_the_accepted_and_declined_wording_fills_every_placeholder_and_fits_one_sms(): void
    {
        config(['app.url' => 'https://careflow.co.ke']);
        $visit = Visit::factory()->inLineOf($this->wanjiku, 12)->create([
            'patient_id' => Patient::factory()->for($this->facility)->create(['name' => 'Kevin Otieno'])->id,
        ]);
        $visit->load(['facility', 'patient', 'department', 'assignedDoctor']);
        $window = new ArrivalWindow(now(config('careflow.timezone'))->setTime(11, 50)->utc(), now(config('careflow.timezone'))->setTime(12, 0)->utc());
        $templates = app(MessageTemplates::class);

        $accepted = $templates->remoteAccepted($visit, $window);
        $declined = $templates->remoteDeclined(RemoteRequest::factory()->for($this->facility)->declined('The clinic is closing early today, sorry.')->create());

        $this->assertSame("Upendo Clinic: You're in Dr. Wanjiku Mwangi's queue (C-12). Please arrive 11:50 AM–12:00 PM. Track: ".$visit->trackingUrl(), $accepted);
        foreach ([$accepted, $declined, $templates->almostTurnRemote($visit)] as $message) {
            $this->assertDoesNotMatchRegularExpression('/[{}]/', $message, 'A placeholder is left in it.');
            $this->assertLessThanOrEqual(160, mb_strlen($message), $message);
        }
    }

    public function test_the_wording_lives_in_config(): void
    {
        config(['notification_templates.remote_declined' => '{facility} says no.{reason}']);
        $request = RemoteRequest::factory()->for($this->facility)->declined('Full today.')->create();

        $this->assertSame('Upendo Clinic says no. Full today.', app(MessageTemplates::class)->remoteDeclined($request));
    }

    public function test_it_does_not_send_a_text_when_a_request_is_merely_received(): void
    {
        $this->gatewayAccepts();

        $this->post(route('remote.request.store', 'upendo'), ['name' => 'Kevin Otieno', 'phone' => '0712 345 678', 'requested_arrival' => '10:30', 'service_id' => $this->general->id]);

        $this->assertSame(0, PatientNotification::count());
        Http::assertNothingSent();
        $this->assertInstanceOf(User::class, $this->receptionist);
        $this->assertInstanceOf(Facility::class, $this->facility);
    }
}
