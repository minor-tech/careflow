<?php

namespace Tests\Feature\Jobs;

use App\Enums\NotificationStatus;
use App\Jobs\SendSmsJob;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\PatientNotification;
use App\Services\AfricasTalkingGateway;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Concerns\FakesSmsGateway;
use Tests\TestCase;

class SendSmsJobTest extends TestCase
{
    use FakesSmsGateway;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureGateway();
    }

    private function queuedMessage(?string $senderId = 'UPENDO'): PatientNotification
    {
        $facility = Facility::factory()->create(['notification_channels' => ['sms'], 'sms_sender_id' => $senderId]);
        $patient = Patient::factory()->for($facility)->create(['phone' => '+254712345678']);

        return PatientNotification::factory()->for($facility)->create(['patient_id' => $patient->id, 'message' => 'Hello Wanjiru']);
    }

    private function runJob(PatientNotification $notification): void
    {
        (new SendSmsJob($notification))->handle(app(AfricasTalkingGateway::class));
    }

    public function test_a_message_the_gateway_accepts_is_marked_sent_with_the_time_and_the_gateways_answer(): void
    {
        $this->gatewayAccepts();
        $this->travelTo(now()->setDateTime(2026, 9, 18, 12, 0, 0));
        $notification = $this->queuedMessage();

        $this->runJob($notification);

        $notification->refresh();
        $this->assertSame(NotificationStatus::Sent, $notification->status);
        $this->assertSame('2026-09-18 12:00:00', $notification->sent_at->toDateTimeString());
        $this->assertStringContainsString('ATXid_1', $notification->provider_response);
    }

    public function test_it_goes_to_the_patients_number_from_the_facilitys_sender_id(): void
    {
        $this->gatewayAccepts();

        $this->runJob($this->queuedMessage('UPENDO'));

        Http::assertSent(fn (Request $request) => $request['to'] === '+254712345678'
            && $request['message'] === 'Hello Wanjiru'
            && $request['from'] === 'UPENDO');
    }

    public function test_a_facility_with_no_sender_id_uses_the_default_one(): void
    {
        $this->gatewayAccepts();

        $this->runJob($this->queuedMessage(null));

        Http::assertSent(fn (Request $request) => $request['from'] === 'CAREFLOW');
    }

    public function test_a_message_the_gateway_refuses_is_marked_failed_with_the_reason_and_nothing_is_thrown(): void
    {
        $this->gatewayRejects(401, 'The supplied authentication is invalid');
        $notification = $this->queuedMessage();

        $this->runJob($notification);

        $notification->refresh();
        $this->assertSame(NotificationStatus::Failed, $notification->status);
        $this->assertNull($notification->sent_at);
        $this->assertStringContainsString('HTTP 401', $notification->provider_response);
        $this->assertStringContainsString('The supplied authentication is invalid', $notification->provider_response);
    }

    public function test_without_credentials_it_fails_the_message_without_calling_the_gateway(): void
    {
        config(['services.africastalking.api_key' => '']);
        $notification = $this->queuedMessage();

        $this->runJob($notification);

        $this->assertSame(NotificationStatus::Failed, $notification->fresh()->status);
        $this->assertStringContainsString("isn't set up", $notification->fresh()->provider_response);
        Http::assertNothingSent();
    }

    public function test_an_unexpected_error_is_reported_and_recorded_but_never_thrown(): void
    {
        Exceptions::fake();
        $this->mock(AfricasTalkingGateway::class)->shouldReceive('send')->andThrow(new RuntimeException('something odd'));
        $notification = $this->queuedMessage();

        (new SendSmsJob($notification))->handle(app(AfricasTalkingGateway::class));

        $this->assertSame(NotificationStatus::Failed, $notification->fresh()->status);
        $this->assertStringContainsString('something odd', $notification->fresh()->provider_response);
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'something odd');
    }

    public function test_a_message_already_dealt_with_is_never_sent_again(): void
    {
        $this->gatewayAccepts();

        $sent = PatientNotification::factory()->sent()->create();
        $failed = PatientNotification::factory()->failed()->create();

        $this->runJob($sent);
        $this->runJob($failed);

        Http::assertNothingSent();
        $this->assertSame(NotificationStatus::Failed, $failed->fresh()->status);
    }

    public function test_running_the_same_job_twice_sends_once(): void
    {
        $this->gatewayAccepts();
        $notification = $this->queuedMessage();

        $this->runJob($notification);
        $this->runJob($notification);

        Http::assertSentCount(1);
    }

    public function test_a_message_whose_record_was_deleted_is_quietly_dropped(): void
    {
        $this->gatewayAccepts();
        $notification = $this->queuedMessage();
        $job = new SendSmsJob($notification);
        $notification->delete();

        $job->handle(app(AfricasTalkingGateway::class));

        Http::assertNothingSent();
    }

    public function test_if_the_job_itself_fails_the_message_does_not_stay_queued_forever(): void
    {
        $notification = $this->queuedMessage();

        (new SendSmsJob($notification))->failed(new RuntimeException('timed out'));

        $this->assertSame(NotificationStatus::Failed, $notification->fresh()->status);
        $this->assertStringContainsString('timed out', $notification->fresh()->provider_response);
    }

    public function test_it_runs_once_and_only_after_the_surrounding_transaction_commits(): void
    {
        $job = new SendSmsJob($this->queuedMessage());

        $this->assertSame(1, $job->tries);
        $this->assertInstanceOf(ShouldQueueAfterCommit::class, $job);
    }
}
