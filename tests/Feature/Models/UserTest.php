<?php

namespace Tests\Feature\Models;

use App\Enums\UserRole;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{UserRole, string}>
     */
    public static function roleHelpers(): array
    {
        return [
            'admin' => [UserRole::Admin, 'isAdmin'],
            'receptionist' => [UserRole::Receptionist, 'isReceptionist'],
            'doctor' => [UserRole::Doctor, 'isDoctor'],
            'nurse' => [UserRole::Nurse, 'isNurse'],
            'system admin' => [UserRole::SystemAdmin, 'isSystemAdmin'],
        ];
    }

    #[DataProvider('roleHelpers')]
    public function test_only_the_matching_role_helper_returns_true(UserRole $role, string $helper): void
    {
        $user = User::factory()->withRole($role)->make();

        foreach (self::roleHelpers() as [, $otherHelper]) {
            $this->assertSame($otherHelper === $helper, $user->{$otherHelper}());
        }
    }

    public function test_belongs_to_facility_compares_the_facility_id(): void
    {
        $facility = Facility::factory()->create();
        $user = User::factory()->for($facility)->create();

        $this->assertTrue($user->belongsToFacility($facility->id));
        $this->assertFalse($user->belongsToFacility($facility->id + 1));
    }

    public function test_system_admin_belongs_to_no_facility_and_needs_no_phone(): void
    {
        $user = User::factory()->systemAdmin()->create(['phone' => null]);

        $this->assertNull($user->facility);
        $this->assertNull($user->fresh()->phone);
        $this->assertFalse($user->belongsToFacility(1));
    }

    public function test_staff_role_options_are_only_the_roles_a_facility_admin_may_hand_out(): void
    {
        $this->assertSame(['receptionist', 'doctor', 'nurse'], array_keys(UserRole::staffOptions()));
    }

    public function test_password_is_hashed_and_two_factor_defaults_to_enabled(): void
    {
        $user = User::factory()->create(['password' => 'plain-secret']);

        $this->assertNotSame('plain-secret', $user->password);
        $this->assertTrue($user->fresh()->two_factor_enabled);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function names(): array
    {
        return [
            'first two names only' => ['Amina Wanjiru Njoroge', 'AW'],
            'a single name' => ['Amina', 'A'],
            'lower case and stray spaces' => ['  otieno   kamau ', 'OK'],
        ];
    }

    #[DataProvider('names')]
    public function test_initials_are_the_first_letters_of_the_first_two_names(string $name, string $expected): void
    {
        $this->assertSame($expected, User::factory()->make(['name' => $name])->initials());
    }

    public function test_each_role_has_an_accent_colour_for_lists(): void
    {
        $this->assertSame('blue', UserRole::Doctor->accent());
        $this->assertSame('sage', UserRole::Nurse->accent());
        $this->assertSame('gold', UserRole::Receptionist->accent());
        $this->assertSame('primary', UserRole::Admin->accent());
    }
}
