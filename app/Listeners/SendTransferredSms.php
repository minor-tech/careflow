<?php

namespace App\Listeners;

use App\Events\VisitTransferred;
use App\Listeners\Concerns\NotifiesPatientSafely;
use App\Services\MessageTemplates;
use App\Services\SmsSender;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class SendTransferredSms implements ShouldHandleEventsAfterCommit
{
    use NotifiesPatientSafely;

    public function __construct(private SmsSender $sms, private MessageTemplates $templates) {}

    public function handle(VisitTransferred $event): void
    {
        $this->safely(function () use ($event) {
            // The visit was just moved, so its department is the new one; make sure it isn't a stale, cached one.
            $visit = $event->visit->unsetRelation('department')->loadMissing(['patient.facility', 'facility', 'department']);

            $this->sms->send($visit->patient, $this->templates->transferred($visit), $visit);
        });
    }
}
