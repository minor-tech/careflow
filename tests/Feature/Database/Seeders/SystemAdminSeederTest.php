<?php

namespace Tests\Feature\Database\Seeders;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SystemAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SystemAdminSeederTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'ndaujulius@gmail.com';

    public function test_creates_the_system_admin_with_no_facility_department_or_phone(): void
    {
        $this->seed(SystemAdminSeeder::class);

        $admin = User::where('email', self::EMAIL)->sole();

        $this->assertSame('Julius Ndau', $admin->name);
        $this->assertSame(UserRole::SystemAdmin, $admin->role);
        $this->assertSame(UserStatus::Active, $admin->status);
        $this->assertNull($admin->facility_id);
        $this->assertNull($admin->department_id);
        $this->assertNull($admin->phone);
        $this->assertTrue($admin->two_factor_enabled);
        $this->assertNotNull($admin->email_verified_at);
    }

    public function test_running_it_twice_neither_fails_nor_duplicates_the_account(): void
    {
        $this->seed(SystemAdminSeeder::class);
        $this->seed(SystemAdminSeeder::class);

        $this->assertSame(1, User::where('email', self::EMAIL)->count());
        $this->assertDatabaseCount('users', 1);
    }

    public function test_the_system_admin_can_log_in_and_lands_on_the_pending_facilities_queue(): void
    {
        $this->seed(SystemAdminSeeder::class);

        $this->post('/login', ['email' => self::EMAIL, 'password' => 'julius995'])
            ->assertRedirect(route('dashboard'));

        $this->get(route('dashboard'))->assertRedirect(route('system.facilities.pending'));
    }

    public function test_rerunning_restores_the_account_but_never_resets_a_changed_password(): void
    {
        $this->seed(SystemAdminSeeder::class);

        User::where('email', self::EMAIL)->sole()->forceFill([
            'name' => 'Renamed',
            'status' => UserStatus::Suspended,
            'password' => 'a-new-password-set-later',
        ])->save();

        $this->seed(SystemAdminSeeder::class);

        $admin = User::where('email', self::EMAIL)->sole();

        $this->assertSame('Julius Ndau', $admin->name);
        $this->assertSame(UserStatus::Active, $admin->status);
        $this->assertTrue(Hash::check('a-new-password-set-later', $admin->password));
        $this->assertFalse(Hash::check('julius995', $admin->password));
    }

    public function test_the_default_seeder_creates_the_system_admin_and_no_other_account(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', ['email' => self::EMAIL, 'role' => 'system_admin']);
        $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
    }
}
