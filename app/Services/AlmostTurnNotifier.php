<?php

namespace App\Services;

use App\Enums\NotificationChannel;
use App\Enums\VisitStatus;
use App\Models\Visit;

class AlmostTurnNotifier
{
    /**
     * "Almost up" means among the first this many waiting in a department.
     */
    public const NEAR_THE_FRONT = 2;

    public function __construct(private SmsSender $sms, private MessageTemplates $templates) {}

    /**
     * After a visit is completed, tell the patients now at the front of that
     * department's queue that they're almost up. Each gets the message once,
     * however many completions follow.
     *
     * The front is the first few waiting, in the order the queue is worked
     * (whoever joined this department's queue first), whether or not they were
     * already told; only those not yet told get the message. Filtering out the
     * told ones first would reach past the front and message the third person.
     */
    public function notifyFor(Visit $completed): void
    {
        $completed->loadMissing('facility');

        if ($completed->department_id === null || ! $completed->facility->usesChannel(NotificationChannel::Sms)) {
            return;
        }

        $front = Visit::query()
            ->where('facility_id', $completed->facility_id)
            ->where('department_id', $completed->department_id)
            ->where('status', VisitStatus::Waiting)
            ->registeredToday()
            ->orderByRaw('coalesce(department_entered_at, created_at)')
            ->orderBy('queue_number')
            ->orderBy('id')
            ->limit(self::NEAR_THE_FRONT)
            ->with(['patient.facility', 'facility', 'department'])
            ->get();

        foreach ($front as $visit) {
            if ($visit->almost_turn_notified) {
                continue;
            }

            // Claim it first: if two completions get here at once, only one wins the claim and sends.
            $claimed = Visit::whereKey($visit->id)->where('almost_turn_notified', false)->update(['almost_turn_notified' => true]);

            if ($claimed === 1) {
                $this->sms->send($visit->patient, $this->templates->almostTurn($visit), $visit);
            }
        }
    }
}
