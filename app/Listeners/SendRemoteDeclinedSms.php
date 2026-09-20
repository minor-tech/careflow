<?php

namespace App\Listeners;

use App\Events\RemoteRequestDeclined;
use App\Listeners\Concerns\NotifiesPatientSafely;
use App\Services\MessageTemplates;
use App\Services\SmsSender;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class SendRemoteDeclinedSms implements ShouldHandleEventsAfterCommit
{
    use NotifiesPatientSafely;

    public function __construct(private SmsSender $sms, private MessageTemplates $templates) {}

    public function handle(RemoteRequestDeclined $event): void
    {
        $this->safely(function () use ($event) {
            $request = $event->request->loadMissing('facility');

            // The requester was never a patient, so this goes to the number they gave.
            $this->sms->sendToNumber($request->facility, $request->phone, $this->templates->remoteDeclined($request));
        });
    }
}
