<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Seeder;

class SystemAdminSeeder extends Seeder
{
    /**
     * Create (or bring back into shape) the platform's system admin, who
     * approves facility registrations. Safe to run repeatedly.
     *
     * The starting password is only set when the account is first created, so
     * re-running the seeder never overwrites a password that was changed
     * afterwards. Change it before this touches a real deployment.
     */
    public function run(): void
    {
        $admin = User::firstOrNew(['email' => 'ndaujulius@gmail.com']);

        $admin->forceFill([
            'name' => 'Julius Ndau',
            'phone' => null,
            'facility_id' => null,
            'department_id' => null,
            'role' => UserRole::SystemAdmin,
            'status' => UserStatus::Active,
            'two_factor_enabled' => true,
            'email_verified_at' => $admin->email_verified_at ?? now(),
        ]);

        if (! $admin->exists) {
            $admin->password = 'julius995';
        }

        $admin->save();
    }
}
