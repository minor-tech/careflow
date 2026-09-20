<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{UserRole, string, string}>
     */
    public static function roleDashboards(): array
    {
        return [
            'admin' => [UserRole::Admin, 'admin.facility', 'Departments'],
            'receptionist' => [UserRole::Receptionist, 'queue.index', 'Register patient'],
            'doctor' => [UserRole::Doctor, 'queue.index', 'Queue'],
            'nurse' => [UserRole::Nurse, 'queue.index', 'Queue'],
        ];
    }

    #[DataProvider('roleDashboards')]
    public function test_redirects_each_role_to_its_own_dashboard(UserRole $role, string $routeName): void
    {
        $this->actingAs(User::factory()->withRole($role)->create())
            ->get(route('dashboard'))
            ->assertRedirect(route($routeName));
    }

    #[DataProvider('roleDashboards')]
    public function test_each_role_reaches_a_distinct_dashboard_shell(UserRole $role, string $routeName, string $navLabel): void
    {
        $this->actingAs(User::factory()->withRole($role)->create())
            ->get(route($routeName))
            ->assertOk()
            ->assertSeeText($navLabel)
            ->assertSeeText($role->label());
    }

    public function test_the_sidebar_only_offers_the_pages_a_role_can_open(): void
    {
        $this->actingAs(User::factory()->receptionist()->create())
            ->get(route('queue.index'))
            ->assertDontSee(route('staff.index'), false)
            ->assertDontSee(route('departments.index'), false);

        $this->actingAs(User::factory()->create())
            ->get(route('admin.facility'))
            ->assertSee(route('staff.index'), false)
            ->assertSee(route('departments.index'), false)
            ->assertSee(route('analytics.index'), false)
            ->assertSee(route('admin.settings'), false);
    }

    public function test_the_old_placeholder_dashboards_are_gone(): void
    {
        $this->actingAs(User::factory()->receptionist()->create());

        foreach (['/reception', '/doctor', '/nurse'] as $path) {
            $this->get($path)->assertNotFound();
        }
    }

    public function test_the_admin_of_a_pending_facility_is_sent_to_the_status_page(): void
    {
        $admin = User::factory()->for(Facility::factory()->pendingReview())->create();

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertRedirect(route('facility.status'));

        $this->actingAs($admin)
            ->get(route('admin.facility'))
            ->assertRedirect(route('facility.status'));
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }
}
