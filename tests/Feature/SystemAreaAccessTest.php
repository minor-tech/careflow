<?php

namespace Tests\Feature;

use App\Enums\FacilityStatus;
use App\Enums\UserRole;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SystemAreaAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function systemRoutes(): array
    {
        $routes = [
            'queue' => ['get', 'system.facilities.pending'],
            'detail' => ['get', 'system.facilities.show'],
            'approve' => ['post', 'system.facilities.approve'],
            'reject' => ['post', 'system.facilities.reject'],
        ];

        $cases = [];

        foreach ([UserRole::Admin, UserRole::Receptionist, UserRole::Doctor, UserRole::Nurse] as $role) {
            foreach ($routes as $name => [$method, $route]) {
                $cases["{$role->value} on {$name}"] = [$role->value, $method, $route];
            }
        }

        return $cases;
    }

    #[DataProvider('systemRoutes')]
    public function test_facility_staff_of_every_role_are_forbidden_from_the_system_area(string $role, string $method, string $routeName): void
    {
        $target = Facility::factory()->pendingReview()->create();
        $user = User::factory()->for(Facility::factory())->withRole(UserRole::from($role))->create();

        $this->actingAs($user)
            ->{$method}(route($routeName, $routeName === 'system.facilities.pending' ? [] : $target), ['reason' => 'Trying to reject this one.'])
            ->assertForbidden();

        $this->assertSame(FacilityStatus::PendingReview, $target->fresh()->status);
    }

    public function test_a_facility_admin_cannot_approve_their_own_facility(): void
    {
        Notification::fake();
        $facility = Facility::factory()->pendingReview()->create();
        $admin = User::factory()->for($facility)->create();

        // Pending admins are bounced to the status page; either way it must not activate.
        $this->actingAs($admin)->post(route('system.facilities.approve', $facility))->assertRedirect(route('facility.status'));

        $this->assertSame(FacilityStatus::PendingReview, $facility->fresh()->status);
        Notification::assertNothingSent();
    }

    public function test_guests_are_sent_to_login_from_every_system_route(): void
    {
        $facility = Facility::factory()->pendingReview()->create();

        $this->get(route('system.facilities.pending'))->assertRedirect(route('login'));
        $this->get(route('system.facilities.show', $facility))->assertRedirect(route('login'));
        $this->post(route('system.facilities.approve', $facility))->assertRedirect(route('login'));
        $this->post(route('system.facilities.reject', $facility), ['reason' => 'Not allowed to do this.'])->assertRedirect(route('login'));

        $this->assertSame(FacilityStatus::PendingReview, $facility->fresh()->status);
    }

    public function test_a_suspended_system_admin_is_signed_out_of_the_system_area(): void
    {
        $this->actingAs(User::factory()->systemAdmin()->suspended()->create())
            ->get(route('system.facilities.pending'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_the_system_admin_can_open_the_queue_and_the_sidebar_offers_only_that(): void
    {
        $this->actingAs(User::factory()->systemAdmin()->create())
            ->get(route('system.facilities.pending'))
            ->assertOk()
            ->assertSeeText('Pending facilities')
            ->assertSeeText('System admin')
            ->assertDontSee(route('staff.index'), false)
            ->assertDontSee(route('departments.index'), false);
    }

    public function test_the_system_admin_can_change_their_own_password_from_the_profile(): void
    {
        $this->actingAs(User::factory()->systemAdmin()->create())
            ->get(route('profile.edit'))
            ->assertOk();
    }
}
