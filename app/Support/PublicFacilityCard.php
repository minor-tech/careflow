<?php

namespace App\Support;

use App\Enums\RemoteQueueAvailability;

/**
 * What the public directory may say about a facility, and nothing more: its
 * name and area, what it offers, whether it is taking remote requests, roughly
 * how long the wait is and how many doctors are on duty. It holds no patient's
 * name, no doctor's name or workload, and nothing clinical, so nothing else
 * can leak into a public page through it.
 */
final readonly class PublicFacilityCard
{
    /**
     * @param  list<string>  $services  What can be seen for, by name.
     * @param  string|null  $waitLabel  The shortest wait among doctors on duty, "35–50 minutes", or "No wait"; null when it can't be said.
     */
    public function __construct(
        public string $name,
        public string $slug,
        public ?string $county,
        public ?string $subCounty,
        public array $services,
        public RemoteQueueAvailability $remoteQueue,
        public ?string $waitLabel,
        public int $doctorsOnDuty,
        public bool $selfCheckin,
    ) {}

    public function area(): string
    {
        return collect([$this->subCounty, $this->county])->filter()->implode(', ');
    }
}
