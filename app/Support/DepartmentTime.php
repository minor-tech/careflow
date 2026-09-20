<?php

namespace App\Support;

/**
 * Time spent at one department, in minutes: either a whole stay there ("leg",
 * queueing included) or just the time being served, depending on which
 * DepartmentDurationCalculator method produced it.
 */
final readonly class DepartmentTime
{
    public function __construct(
        public int $departmentId,
        public float $minutes,
    ) {}
}
