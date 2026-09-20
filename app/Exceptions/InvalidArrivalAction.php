<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Something was done to a patient who is meant to be arriving that can't be
 * done. The message is written to be shown to staff as it is.
 */
class InvalidArrivalAction extends RuntimeException
{
    public static function notAwaitingArrival(): self
    {
        return new self('That patient is not waiting to arrive: they may already be checked in, or the visit is over.');
    }

    public static function nobodyToMoveBehind(): self
    {
        return new self('Nobody who is here is waiting behind them, so there is nobody to move them behind. Wait a little longer, or cancel the request.');
    }
}
