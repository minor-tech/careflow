<?php

namespace App\Actions;

use App\Models\Facility;
use App\Notifications\StaffAccountCreated;
use App\Support\NewStaffAccount;
use App\Support\TemporaryPassword;
use Illuminate\Support\Facades\DB;

class CreateStaffMember
{
    /**
     * Create a staff account with a generated temporary password, and send it
     * over the facility's chosen channels once any surrounding transaction has
     * committed. The account must set its own password on first login.
     *
     * The password is handed back so a caller that has an admin in front of it
     * can show it to them; nothing here stores it in the clear.
     *
     * @param  array{name: string, email: string, phone: string, role: string, department_id?: int|null}  $attributes
     */
    public function handle(Facility $facility, array $attributes): NewStaffAccount
    {
        $temporaryPassword = TemporaryPassword::generate();

        $user = $facility->users()->create([
            ...$attributes,
            'password' => $temporaryPassword,
            'must_change_password' => true,
        ]);

        DB::afterCommit(fn () => $user->notify(new StaffAccountCreated($facility, $temporaryPassword)));

        return new NewStaffAccount($user, $temporaryPassword);
    }
}
