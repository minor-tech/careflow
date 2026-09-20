<?php

namespace App\Console\Commands;

use App\Enums\FacilityStatus;
use App\Models\Facility;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('facility:set-status {facility : The facility ID or slug} {status : pending_review, active or suspended}')]
#[Description('Approve, suspend or return a facility to review (the manual go-live check)')]
class SetFacilityStatus extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $status = FacilityStatus::tryFrom((string) $this->argument('status'));

        if ($status === null) {
            $this->components->error('Status must be one of: '.implode(', ', FacilityStatus::values()).'.');

            return self::FAILURE;
        }

        $reference = (string) $this->argument('facility');

        $facility = Facility::where('slug', $reference)
            ->when(ctype_digit($reference), fn ($query) => $query->orWhere('id', $reference))
            ->first();

        if ($facility === null) {
            $this->components->error("No facility found for [{$reference}].");

            return self::FAILURE;
        }

        $facility->update(['status' => $status]);

        $this->components->info("{$facility->name} is now {$status->label()}.");

        return self::SUCCESS;
    }
}
