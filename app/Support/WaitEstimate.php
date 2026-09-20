<?php

namespace App\Support;

/**
 * A guess at how long a patient will wait, kept as a range because a single
 * number would claim more than anyone knows.
 */
final readonly class WaitEstimate
{
    public function __construct(
        public int $lowMinutes,
        public int $highMinutes,
    ) {}

    /**
     * "25–35 minutes", or "5 minutes or less" when the low end is nothing.
     */
    public function label(): string
    {
        return $this->lowMinutes <= 0
            ? "{$this->highMinutes} minutes or less"
            : "{$this->lowMinutes}–{$this->highMinutes} minutes";
    }
}
