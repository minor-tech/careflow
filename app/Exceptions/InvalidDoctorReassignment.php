<?php

namespace App\Exceptions;

use App\Models\User;
use RuntimeException;

/**
 * A patient was to be given to a doctor they can't be given to. The message is
 * written to be shown to staff as it is.
 */
class InvalidDoctorReassignment extends RuntimeException
{
    public static function notDoctorQueue(): self
    {
        return new self("This patient's department doesn't assign patients to doctors.");
    }

    public static function consultationStarted(): self
    {
        return new self('Cannot reassign after consultation has started.');
    }

    public static function alreadyAssigned(User $doctor): self
    {
        return new self("This patient is already assigned to {$doctor->doctorName()}.");
    }

    public static function notAvailable(User $doctor): self
    {
        return new self("{$doctor->doctorName()} isn't on duty in this department right now. Choose another doctor.");
    }
}
