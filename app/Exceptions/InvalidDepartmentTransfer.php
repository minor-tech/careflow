<?php

namespace App\Exceptions;

use App\Models\Department;
use App\Models\Visit;
use RuntimeException;

/**
 * A patient was to be sent to a department they can't be sent to. The message
 * is written to be shown to staff as it is.
 */
class InvalidDepartmentTransfer extends RuntimeException
{
    public static function alreadyThere(Visit $visit, Department $department): self
    {
        return new self("{$visit->loadMissing('department')->queueLabel()} is already in {$department->name}.");
    }

    public static function inactive(Department $department): self
    {
        return new self("{$department->name} isn't taking patients right now, so nobody can be sent there.");
    }

    public static function needsDoctor(Department $department): self
    {
        return new self("{$department->name} gives each patient their own doctor: choose one to send them to.");
    }

    public static function doctorNotAvailable(Department $department): self
    {
        return new self("That doctor isn't on duty in {$department->name} right now. Choose another.");
    }

    public static function otherFacility(): self
    {
        return new self("That department doesn't belong to this facility.");
    }
}
