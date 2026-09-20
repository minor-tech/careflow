<?php

namespace Tests\Feature\Http\Middleware;

use App\Enums\UserRole;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EnsureFacilityIsActiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'auth', 'facility.active'])->get('/_test/working-area', fn () => 'working area');
    }

    /**
     * @return array<string, array{UserRole}>
     */
    public static function nonAdminRoles(): array
    {
        return [
            'receptionist' => [UserRole::Receptionist],
            'doctor' => [UserRole::Doctor],
            'nurse' => [UserRole::Nurse],
        ];
    }

    public function test_lets_staff_of_an_active_facility_through(): void
    {
        foreach (UserRole::cases() as $role) {
            $this->actingAs(User::factory()->withRole($role)->create())
                ->get('/_test/working-area')
                ->assertOk()
                ->assertSee('working area');
        }
    }

    public function test_sends_the_admin_of_a_pending_facility_to_the_status_page(): void
    {
        $admin = User::factory()->for(Facility::factory()->pendingReview())->create();

        $this->actingAs($admin)
            ->get('/_test/working-area')
            ->assertRedirect(route('facility.status'));

        $this->assertAuthenticatedAs($admin);
    }

    public function test_sends_the_admin_of_a_suspended_facility_to_the_status_page(): void
    {
        $admin = User::factory()->for(Facility::factory()->suspended())->create();

        $this->actingAs($admin)
            ->get('/_test/working-area')
            ->assertRedirect(route('facility.status'));
    }

    #[DataProvider('nonAdminRoles')]
    public function test_signs_out_non_admin_staff_of_a_pending_facility_and_says_why(UserRole $role): void
    {
        $user = User::factory()->for(Facility::factory()->pendingReview())->withRole($role)->create();

        $this->actingAs($user)
            ->get('/_test/working-area')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'Your facility registration is still under review. Ask your facility admin for an update.']);

        $this->assertGuest();
    }

    #[DataProvider('nonAdminRoles')]
    public function test_signs_out_non_admin_staff_of_a_suspended_facility_and_says_why(UserRole $role): void
    {
        $user = User::factory()->for(Facility::factory()->suspended())->withRole($role)->create();

        $this->actingAs($user)
            ->get('/_test/working-area')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'Your facility has been suspended. Contact your facility admin.']);

        $this->assertGuest();
    }

    public function test_never_blocks_a_system_admin_who_has_no_facility(): void
    {
        $this->actingAs(User::factory()->systemAdmin()->create())
            ->get('/_test/working-area')
            ->assertOk();
    }

    public function test_exempts_a_system_admin_by_role_whatever_their_facility(): void
    {
        $systemAdmin = User::factory()->systemAdmin()->state(['facility_id' => Facility::factory()->pendingReview()])->create();

        $this->actingAs($systemAdmin)
            ->get('/_test/working-area')
            ->assertOk();
    }

    public function test_signs_out_an_account_with_no_facility_unless_it_is_a_system_admin(): void
    {
        $this->actingAs(User::factory()->state(['facility_id' => null])->create())
            ->get('/_test/working-area')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'Your account is not linked to a facility. Contact your facility admin.']);

        $this->assertGuest();
    }

    #[DataProvider('nonAdminRoles')]
    public function test_signs_out_non_admin_staff_of_a_rejected_facility_and_says_why(UserRole $role): void
    {
        $user = User::factory()->for(Facility::factory()->rejected())->withRole($role)->create();

        $this->actingAs($user)
            ->get('/_test/working-area')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'Your facility registration was not approved. Ask your facility admin for details.']);

        $this->assertGuest();
    }

    public function test_sends_the_admin_of_a_rejected_facility_to_the_status_page(): void
    {
        $this->actingAs(User::factory()->for(Facility::factory()->rejected())->create())
            ->get('/_test/working-area')
            ->assertRedirect(route('facility.status'));
    }

    public function test_signs_out_a_suspended_account_even_when_its_facility_is_active(): void
    {
        $this->actingAs(User::factory()->receptionist()->suspended()->create())
            ->get('/_test/working-area')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_leaves_guests_to_the_auth_middleware(): void
    {
        $this->get('/_test/working-area')->assertRedirect(route('login'));
    }
}
