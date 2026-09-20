<?php

namespace App\Actions;

use App\Enums\VisitEventType;
use App\Enums\VisitStatus;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitEvent;
use App\Support\AccessPin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class ResetVisitAccessPin
{
    /**
     * Give a visit a new access PIN, for a patient who lost theirs (or fears
     * someone else has it) and log who did it.
     *
     * The old hash is simply overwritten, so the old PIN stops working the
     * instant this commits: there is no grace period in which both open the
     * visit. Wrong guesses already counted against the visit are cleared too,
     * or a patient locked out by someone else's guessing would stay locked out
     * of the brand-new PIN.
     *
     * Returns the new PIN, which the caller shows to staff once, or null when
     * the visit is already over (a finished visit's PIN opens nothing).
     */
    public function handle(Visit $visit, User $actor): ?string
    {
        $pin = AccessPin::generate();

        // Hashing is slow on purpose, so it happens before the visit row is locked.
        $hash = Hash::make($pin);

        $reset = DB::transaction(function () use ($visit, $actor, $hash): bool {
            $current = Visit::whereKey($visit->getKey())->lockForUpdate()->firstOrFail();

            if (in_array($current->status, [VisitStatus::Completed, VisitStatus::Cancelled], true)) {
                return false;
            }

            $current->update(['access_pin_hash' => $hash]);

            VisitEvent::create([
                'visit_id' => $current->id,
                'department_id' => $current->department_id,
                'event' => VisitEventType::PinReset,
                'user_id' => $actor->id,
            ]);

            return true;
        });

        if (! $reset) {
            return null;
        }

        RateLimiter::clear($visit->accessPinMissesKey());

        return $pin;
    }
}
