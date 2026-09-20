<?php

namespace App\Services;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Jobs\SendSmsJob;
use App\Models\Patient;
use App\Models\PatientNotification;
use App\Models\Visit;

class SmsSender
{
    /**
     * Record an SMS to a patient and queue it for sending.
     *
     * A facility that didn't choose SMS at registration never has one sent (or
     * charged for): nothing is recorded and nothing is queued, and null comes
     * back. Otherwise the message is stored as "queued" straight away and the
     * queue worker hands it to the gateway.
     */
    public function send(Patient $patient, string $message, ?Visit $visit = null): ?PatientNotification
    {
        $facility = $patient->facility;

        if (! $facility->usesChannel(NotificationChannel::Sms)) {
            return null;
        }

        $notification = PatientNotification::create([
            'facility_id' => $facility->id,
            'patient_id' => $patient->id,
            'visit_id' => $visit?->id,
            'channel' => NotificationChannel::Sms,
            'message' => $message,
            'status' => NotificationStatus::Queued,
        ]);

        SendSmsJob::dispatch($notification);

        return $notification;
    }
}
