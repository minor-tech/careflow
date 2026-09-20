<?php

namespace App\Listeners;

use App\Events\VisitCompleted;
use App\Listeners\Concerns\NotifiesPatientSafely;
use App\Services\AlmostTurnNotifier;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * When someone finishes, the people now at the front of that department's
 * queue are told they're almost up.
 */
class SendAlmostTurnSms implements ShouldHandleEventsAfterCommit
{
    use NotifiesPatientSafely;

    public function __construct(private AlmostTurnNotifier $notifier) {}

    public function handle(VisitCompleted $event): void
    {
        $this->safely(fn () => $this->notifier->notifyFor($event->visit));
    }
}
