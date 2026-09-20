<?php

namespace App\Listeners\Concerns;

use Closure;
use Throwable;

trait NotifiesPatientSafely
{
    /**
     * A patient's SMS is a courtesy on top of the real work (registering,
     * calling, transferring, completing), which has already been saved by the
     * time this runs. So if anything at all goes wrong preparing it, the
     * problem is reported and the caller carries on as though nothing happened.
     */
    protected function safely(Closure $work): void
    {
        try {
            $work();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
