<?php

namespace App\Jobs;

use App\Enums\RemoteRequestStatus;
use App\Models\RemoteRequest;
use App\Support\ClinicDay;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Requests only last for the day they are made. Anything still waiting for a
 * decision from before today is marked expired, so it stops crowding the review
 * list and its link says so. Run by the scheduler (see routes/console.php), and
 * deliberately where it is called rather than through the queue, like the other
 * nightly job: a batch that silently waits for a worker is worse than one whose
 * error shows in the scheduler's output.
 */
class ExpireRemoteRequests
{
    use Dispatchable;

    public function handle(): void
    {
        [$startOfToday] = ClinicDay::bounds();

        RemoteRequest::query()
            ->where('status', RemoteRequestStatus::Pending)
            ->where('created_at', '<', $startOfToday)
            ->update(['status' => RemoteRequestStatus::Expired]);
    }
}
