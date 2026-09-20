<?php

namespace Tests\Feature\Services;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Jobs\SendSmsJob;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\PatientNotification;
use App\Models\Visit;
use App\Services\SmsSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SmsSenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    private function patientAt(array $channels): Patient
    {
        return Patient::factory()->for(Facility::factory()->create(['notification_channels' => $channels]))->create();
    }

    public function test_records_the_message_as_queued_and_queues_it_for_sending(): void
    {
        $patient = $this->patientAt(['sms']);
        $visit = Visit::factory()->for($patient->facility)->for($patient)->create();

        $notification = app(SmsSender::class)->send($patient, 'Hello Wanjiru', $visit);

        $this->assertNotNull($notification);
        $this->assertSame($patient->facility_id, $notification->facility_id);
        $this->assertSame($patient->id, $notification->patient_id);
        $this->assertSame($visit->id, $notification->visit_id);
        $this->assertSame(NotificationChannel::Sms, $notification->channel);
        $this->assertSame('Hello Wanjiru', $notification->message);
        $this->assertSame(NotificationStatus::Queued, $notification->fresh()->status);
        $this->assertNull($notification->sent_at);
        Queue::assertPushed(SendSmsJob::class, fn (SendSmsJob $job) => $job->notification->is($notification));
        Queue::assertPushedTimes(SendSmsJob::class, 1);
    }

    public function test_a_message_need_not_belong_to_a_visit(): void
    {
        $patient = $this->patientAt(['sms']);

        $notification = app(SmsSender::class)->send($patient, 'Hello');

        $this->assertNull($notification->visit_id);
    }

    public function test_a_facility_that_chose_several_channels_including_sms_sends(): void
    {
        $patient = $this->patientAt(['email', 'sms', 'whatsapp']);

        $this->assertNotNull(app(SmsSender::class)->send($patient, 'Hello'));
    }

    /**
     * @return array<string, array{list<string>}>
     */
    public static function channelsWithoutSms(): array
    {
        return [
            'email only' => [['email']],
            'whatsapp only' => [['whatsapp']],
            'email and whatsapp' => [['email', 'whatsapp']],
            'none chosen' => [[]],
        ];
    }

    /**
     * @param  list<string>  $channels
     */
    #[DataProvider('channelsWithoutSms')]
    public function test_a_facility_that_did_not_choose_sms_gets_nothing_recorded_and_nothing_queued(array $channels): void
    {
        $patient = $this->patientAt($channels);

        $result = app(SmsSender::class)->send($patient, 'Hello');

        $this->assertNull($result);
        $this->assertSame(0, PatientNotification::count());
        Queue::assertNothingPushed();
    }
}
