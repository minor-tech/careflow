<?php

namespace Tests\Feature\Http\Middleware;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EnsureUserHasRoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'auth', 'role:admin,doctor'])->get('/_test/admin-or-doctor', fn () => 'allowed');
        Route::middleware(['web', 'auth', 'role:admin'])->get('/_test/admin-only', fn () => 'allowed');
    }

    /**
     * @return array<string, array{UserRole}>
     */
    public static function listedRoles(): array
    {
        return [
            'admin' => [UserRole::Admin],
            'doctor' => [UserRole::Doctor],
        ];
    }

    /**
     * @return array<string, array{UserRole}>
     */
    public static function unlistedRoles(): array
    {
        return [
            'receptionist' => [UserRole::Receptionist],
            'nurse' => [UserRole::Nurse],
        ];
    }

    #[DataProvider('listedRoles')]
    public function test_allows_every_role_in_the_list(UserRole $role): void
    {
        $this->actingAs(User::factory()->withRole($role)->create())
            ->get('/_test/admin-or-doctor')
            ->assertOk()
            ->assertSee('allowed');
    }

    #[DataProvider('unlistedRoles')]
    public function test_forbids_roles_missing_from_the_list_with_403(UserRole $role): void
    {
        $this->actingAs(User::factory()->withRole($role)->create())
            ->get('/_test/admin-or-doctor')
            ->assertForbidden();
    }

    public function test_a_single_role_is_enough_for_a_route_and_blocks_everyone_else(): void
    {
        $this->actingAs(User::factory()->doctor()->create())
            ->get('/_test/admin-only')
            ->assertForbidden();

        $this->actingAs(User::factory()->create())
            ->get('/_test/admin-only')
            ->assertOk();
    }

    public function test_guests_are_sent_to_login_rather_than_shown_a_403(): void
    {
        $this->get('/_test/admin-only')->assertRedirect(route('login'));
    }
}
