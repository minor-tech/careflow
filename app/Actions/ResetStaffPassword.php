<?php

namespace App\Actions;

use App\Models\User;
use App\Support\TemporaryPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ResetStaffPassword
{
    /**
     * Give a staff member a new temporary password, for when the first one was
     * lost, or they are locked out. They must set their own on next login, and
     * anything still signed in as them is signed out, so a reset also shuts out
     * whoever might be holding an old session or "remember me" cookie.
     *
     * Returns the new password, which the caller shows to the admin once.
     */
    public function handle(User $staff): string
    {
        $temporaryPassword = TemporaryPassword::generate();

        DB::transaction(function () use ($staff, $temporaryPassword): void {
            $staff->forceFill([
                'password' => $temporaryPassword,
                'must_change_password' => true,
                'remember_token' => Str::random(60),
            ])->save();

            // Only the database session driver keeps sessions we can find by user.
            if (config('session.driver') === 'database') {
                DB::table(config('session.table', 'sessions'))->where('user_id', $staff->getKey())->delete();
            }
        });

        return $temporaryPassword;
    }
}
