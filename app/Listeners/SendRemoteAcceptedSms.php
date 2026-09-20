<?php

namespace App\Listeners;

use App\Events\RemoteRequestAccepted;
use App\Listeners\Concerns\NotifiesPatientSafely;
use App\Services\MessageTemplates;
use App\Services\SmsSender;
use App\Support\ArrivalWindow;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class SendRemoteAcceptedSms implements ShouldHandleEventsAfterCommit
{
    use NotifiesPatientSafely;

    public function __construct(private SmsSender $sms, private MessageTemplates $templates) {}

    public function handle(RemoteRequestAccepted $event): void
    {
        $this->safely(function () use ($event) {
            $request = $event->request;
            $visit = $request->visit()->with(['patient.facility', 'facility', 'department', 'assignedDoctor'])->firstOrFail();
            $window = new ArrivalWindow($request->recommended_arrival_from, $request->recommended_arrival_until);

            $this->sms->send($visit->patient, $this->templates->remoteAccepted($visit, $window), $visit);
        });
    }
}
