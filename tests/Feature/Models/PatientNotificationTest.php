<?php

namespace Tests\Feature\Models;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\PatientNotification;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PatientNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_notification_defaults_to_queued_and_belongs_to_its_facility_patient_and_visit(): void
    {
        $facility = Facility::factory()->create();
        $patient = Patient::factory()->for($facility)->create();
        $visit = Visit::factory()->for($facility)->for($patient)->create();

        $attributes = PatientNotification::factory()->raw(['facility_id' => $facility->id, 'patient_id' => $patient->id, 'visit_id' => $visit->id]);
        unset($attributes['status']);
        $notification = PatientNotification::create($attributes)->fresh();

        $this->assertSame(NotificationStatus::Queued, $notification->status);
        $this->assertSame(NotificationChannel::Sms, $notification->channel);
        $this->assertTrue($notification->facility->is($facility));
        $this->assertTrue($notification->patient->is($patient));
        $this->assertTrue($notification->visit->is($visit));
        $this->assertTrue($facility->patientNotifications->contains($notification));
    }

    public function test_marking_it_sent_records_the_time_and_the_answer(): void
    {
        $this->travelTo(now()->setDateTime(2026, 9, 18, 9, 30, 0));
        $notification = PatientNotification::factory()->create();

        $notification->markSent('{"ok":true}');

        $this->assertSame(NotificationStatus::Sent, $notification->fresh()->status);
        $this->assertSame('2026-09-18 09:30:00', $notification->fresh()->sent_at->toDateTimeString());
        $this->assertSame('{"ok":true}', $notification->fresh()->provider_response);
    }

    public function test_marking_it_failed_records_why_and_no_sent_time(): void
    {
        $notification = PatientNotification::factory()->create();

        $notification->markFailed('InvalidSenderId');

        $this->assertSame(NotificationStatus::Failed, $notification->fresh()->status);
        $this->assertSame('InvalidSenderId', $notification->fresh()->provider_response);
        $this->assertNull($notification->fresh()->sent_at);
    }

    public function test_deleting_a_visit_keeps_the_record_of_the_message(): void
    {
        $visit = Visit::factory()->create();
        $notification = PatientNotification::factory()->create(['facility_id' => $visit->facility_id, 'patient_id' => $visit->patient_id, 'visit_id' => $visit->id]);

        $visit->delete();

        $this->assertNull($notification->fresh()->visit_id);
    }

    public function test_a_facility_knows_whether_it_uses_a_channel(): void
    {
        $facility = Facility::factory()->make(['notification_channels' => ['email', 'sms']]);

        $this->assertTrue($facility->usesChannel(NotificationChannel::Sms));
        $this->assertTrue($facility->usesChannel(NotificationChannel::Email));
        $this->assertFalse($facility->usesChannel(NotificationChannel::Whatsapp));
    }

    public function test_a_facility_with_no_channels_set_uses_none(): void
    {
        $this->assertFalse(Facility::make(['notification_channels' => null])->usesChannel(NotificationChannel::Sms));
        $this->assertFalse(Facility::make(['notification_channels' => []])->usesChannel(NotificationChannel::Sms));
    }
}
