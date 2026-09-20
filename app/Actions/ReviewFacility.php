<?php

namespace App\Actions;

use App\Enums\FacilityStatus;
use App\Enums\UserRole;
use App\Models\Facility;
use App\Models\User;
use App\Notifications\FacilityApproved;
use App\Notifications\FacilityRejected;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\Notification;
use Throwable;

class ReviewFacility
{
    /**
     * Approve a pending registration and email the facility's admin(s).
     *
     * @return bool|null null if it was not pending review (nothing changed);
     *                   true if reviewed and the admin was emailed; false if
     *                   reviewed but the email could not be sent.
     */
    public function approve(Facility $facility, User $reviewer): ?bool
    {
        return $this->review($facility, $reviewer, FacilityStatus::Active, null, fn () => new FacilityApproved($facility));
    }

    /**
     * Reject a pending registration, recording why, and email the reason to
     * the facility's admin(s). Returns as approve() does.
     */
    public function reject(Facility $facility, User $reviewer, string $reason): ?bool
    {
        return $this->review($facility, $reviewer, FacilityStatus::Rejected, $reason, fn () => new FacilityRejected($facility, $reason));
    }

    /**
     * The status change is one conditional UPDATE, so if two system admins act
     * at once only one of them changes the facility and sends the email.
     *
     * @param  callable(): BaseNotification  $notification
     */
    private function review(Facility $facility, User $reviewer, FacilityStatus $status, ?string $reason, callable $notification): ?bool
    {
        $changed = Facility::whereKey($facility->getKey())
            ->where('status', FacilityStatus::PendingReview)
            ->update([
                'status' => $status,
                'reviewed_at' => now(),
                'reviewed_by' => $reviewer->getKey(),
                'rejection_reason' => $reason,
            ]);

        if ($changed === 0) {
            return null;
        }

        $facility->refresh();

        try {
            Notification::send(
                $facility->users()->where('role', UserRole::Admin)->get(),
                $notification(),
            );
        } catch (Throwable $exception) {
            // The decision is already recorded; don't undo it over a mail failure.
            report($exception);

            return false;
        }

        return true;
    }
}
