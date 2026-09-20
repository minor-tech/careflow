<?php

namespace App\Exceptions;

use App\Enums\RemoteQueueAvailability;
use App\Models\User;
use RuntimeException;

/**
 * A queue request couldn't be made or handled. The message is written to be
 * shown to the person as it is, and never says anything about anyone else.
 */
class RemoteRequestRefused extends RuntimeException
{
    public static function notOffered(): self
    {
        return new self('This facility is not taking queue requests online.');
    }

    public static function unavailable(RemoteQueueAvailability $availability): self
    {
        return new self($availability->reason() ?? 'This facility is not taking queue requests online.');
    }

    public static function selfCheckinFull(): self
    {
        return new self('Too many people are waiting to be checked in. Please go to the front desk.');
    }

    public static function alreadyWaiting(): self
    {
        return new self('A request for this phone number is already waiting at this facility. Please wait for it to be reviewed, or ask the front desk.');
    }

    public static function alreadyInQueue(): self
    {
        return new self('This phone number already has a place in today\'s queue at this facility.');
    }

    public static function alreadyReviewed(): self
    {
        return new self('This request has already been dealt with.');
    }

    public static function needsDoctor(): self
    {
        return new self('This service gives each patient their own doctor: choose one.');
    }

    public static function doctorNotAvailable(User $doctor): self
    {
        return new self("{$doctor->doctorName()} isn't on duty for this service right now. Choose another doctor.");
    }

    public static function noDoctorOnDuty(): self
    {
        return new self('No doctor is on duty for this service right now, so the request can\'t be accepted yet.');
    }
}
