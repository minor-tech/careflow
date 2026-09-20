<?php

namespace App\Listeners;

use App\Events\VisitCalled;
use App\Listeners\Concerns\NotifiesPatientSafely;
use App\Services\MessageTemplates;
use App\Services\SmsSender;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class SendCalledSms implements ShouldHandleEventsAfterCommit
{
    use NotifiesPatientSafely;

    public function __construct(private SmsSender $sms, private MessageTemplates $templates) {}

    public function handle(VisitCalled $event): void
    {
        $this->safely(function () use ($event) {
            $visit = $event->visit->loadMissing(['patient.facility', 'facility', 'department']);

            $this->sms->send($visit->patient, $this->templates->called($visit), $visit);
        });
    }
}
