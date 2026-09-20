<?php

namespace App\Services;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Jobs\SendSmsJob;
use App\Models\Facility;
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
        return $this->queue($patient->facility, $patient, $patient->phone, $message, $visit);
    }

    /**
     * The same, to a number that belongs to no patient: someone whose queue
     * request was declined never became one. Nothing else about it differs, so a
     * facility without SMS still sends nothing.
     */
    public function sendToNumber(Facility $facility, string $phone, string $message): ?PatientNotification
    {
        return $this->queue($facility, null, $phone, $message, null);
    }

    private function queue(Facility $facility, ?Patient $patient, string $phone, string $message, ?Visit $visit): ?PatientNotification
    {
        if (! $facility->usesChannel(NotificationChannel::Sms)) {
            return null;
        }

        $notification = PatientNotification::create([
            'facility_id' => $facility->id,
            'patient_id' => $patient?->id,
            // Only a message with no patient needs its own number; the rest use their patient's.
            'phone' => $patient === null ? $phone : null,
            'visit_id' => $visit?->id,
            'channel' => NotificationChannel::Sms,
            'message' => $message,
            'status' => NotificationStatus::Queued,
        ]);

        SendSmsJob::dispatch($notification);

        return $notification;
    }
}
