<?php

namespace App\Listeners;

use App\Events\VisitDoctorChanged;
use App\Listeners\Concerns\NotifiesPatientSafely;
use App\Services\MessageTemplates;
use App\Services\SmsSender;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class SendDoctorChangedSms implements ShouldHandleEventsAfterCommit
{
    use NotifiesPatientSafely;

    public function __construct(private SmsSender $sms, private MessageTemplates $templates) {}

    public function handle(VisitDoctorChanged $event): void
    {
        $this->safely(function () use ($event) {
            // The visit was just handed over, so its doctor is the new one; make sure it isn't a stale, cached one.
            $visit = $event->visit->unsetRelation('assignedDoctor')->loadMissing(['patient.facility', 'facility', 'department', 'assignedDoctor']);

            $this->sms->send($visit->patient, $this->templates->doctorChanged($visit), $visit);
        });
    }
}
