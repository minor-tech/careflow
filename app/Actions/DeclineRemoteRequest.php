<?php

namespace App\Actions;

use App\Enums\RemoteRequestStatus;
use App\Events\RemoteRequestDeclined;
use App\Exceptions\RemoteRequestRefused;
use App\Models\RemoteRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeclineRemoteRequest
{
    /**
     * Turn a request down, with a reason if staff give one, and tell the
     * requester. No visit or patient record is ever made for it. Like accepting,
     * it works on the locked, re-read request, so it can't undo an acceptance
     * that just happened.
     *
     * @throws RemoteRequestRefused
     */
    public function handle(RemoteRequest $request, ?string $reason, User $actor): RemoteRequest
    {
        return DB::transaction(function () use ($request, $reason, $actor): RemoteRequest {
            $current = RemoteRequest::whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if ($current->status !== RemoteRequestStatus::Pending) {
                throw RemoteRequestRefused::alreadyReviewed();
            }

            $current->update([
                'status' => RemoteRequestStatus::Declined,
                'declined_reason' => filled($reason) ? trim($reason) : null,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
            ]);

            RemoteRequestDeclined::dispatch($current);

            return $current;
        });
    }
}
