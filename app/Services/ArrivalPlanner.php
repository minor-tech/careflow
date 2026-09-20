<?php

namespace App\Services;

use App\Support\ArrivalWindow;
use App\Support\ClinicDay;
use App\Support\WaitEstimate;
use Illuminate\Support\Carbon;

/**
 * Works out when a patient at home should set off, from how long the line
 * ahead of them is expected to take.
 */
class ArrivalPlanner
{
    /** How long before they expect to be called a patient is advised to arrive, at the earliest and the latest. */
    private const EARLIEST_BEFORE_CALL_MINUTES = 20;

    private const LATEST_BEFORE_CALL_MINUTES = 10;

    private const ROUND_TO_MINUTES = 5;

    /**
     * The window to arrive in: shortly before the estimated call, but never
     * before the time the patient said they could get there.
     *
     * @param  string  $requestedArrival  The time of day they can arrive, "10:30:00", in the clinic's time.
     */
    public function windowFor(WaitEstimate $untilCalled, string $requestedArrival): ArrivalWindow
    {
        $now = ClinicDay::now();
        $canArrive = $now->copy()->setTimeFromTimeString($requestedArrival)->startOfMinute();
        $expectedCall = $now->copy()->addMinutes($untilCalled->lowMinutes);

        $from = $expectedCall->copy()->subMinutes(self::EARLIEST_BEFORE_CALL_MINUTES);

        if ($from->lessThan($canArrive)) {
            $from = $canArrive->copy();
        }

        $until = $expectedCall->copy()->subMinutes(self::LATEST_BEFORE_CALL_MINUTES);

        if ($until->lessThan($from->copy()->addMinutes(self::ROUND_TO_MINUTES))) {
            $until = $from->copy()->addMinutes(2 * self::ROUND_TO_MINUTES);
        }

        return new ArrivalWindow($this->roundToStep($from), $this->roundToStep($until));
    }

    /**
     * Up to the next five minutes, never down: rounding down could advise
     * arriving a little before the time the patient said they could get there.
     */
    private function roundToStep(Carbon $time): Carbon
    {
        $minutes = (int) ceil((int) $time->format('i') / self::ROUND_TO_MINUTES) * self::ROUND_TO_MINUTES;

        return $time->copy()->setTime((int) $time->format('H'), 0)->addMinutes($minutes)->setTimezone(config('app.timezone'));
    }
}
