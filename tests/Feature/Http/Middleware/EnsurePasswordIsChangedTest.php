<?php

namespace Tests\Feature\Http\Middleware;

use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class EnsurePasswordIsChangedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'auth', 'password.changed'])->get('/_test/anywhere', fn () => 'reached');
        Route::middleware(['web', 'password.changed'])->get('/_test/open', fn () => 'reached');
    }

    public function test_holds_an_account_on_its_temporary_password_at_the_set_a_password_screen(): void
    {
        $this->actingAs(User::factory()->doctor()->mustChangePassword()->create())
            ->get('/_test/anywhere')
            ->assertRedirect(route('password.force'));
    }

    public function test_lets_everyone_else_through(): void
    {
        $this->actingAs(User::factory()->doctor()->create())->get('/_test/anywhere')->assertOk()->assertSee('reached');
        $this->actingAs(User::factory()->create())->get('/_test/anywhere')->assertOk();
        $this->actingAs(User::factory()->systemAdmin()->create())->get('/_test/anywhere')->assertOk();
    }

    public function test_leaves_guests_to_the_auth_middleware(): void
    {
        $this->get('/_test/open')->assertOk();
        $this->get('/_test/anywhere')->assertRedirect(route('login'));
    }

    public function test_holds_a_flagged_account_off_every_working_page(): void
    {
        $facility = Facility::factory()->create();

        foreach (['doctor', 'nurse', 'receptionist'] as $role) {
            $user = User::factory()->for($facility)->{$role}()->mustChangePassword()->create();

            foreach (['queue.index', 'patients.register', 'profile.edit', 'dashboard'] as $page) {
                $this->actingAs($user)->get(route($page))->assertRedirect(route('password.force'));
            }
        }
    }

    public function test_holds_the_queues_background_refresh_too_so_an_open_page_is_sent_to_the_screen(): void
    {
        $this->actingAs(User::factory()->doctor()->mustChangePassword()->create())
            ->get(route('queue.index'), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertRedirect(route('password.force'));
    }

    public function test_the_screen_and_logging_out_stay_reachable(): void
    {
        $user = User::factory()->doctor()->mustChangePassword()->create();

        $this->actingAs($user)->get(route('password.force'))->assertOk();
        $this->actingAs($user)->post(route('logout'))->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_a_facility_admin_who_chose_their_own_password_is_never_held(): void
    {
        $this->actingAs(User::factory()->create())->get(route('admin.facility'))->assertOk();
    }

    public function test_staff_are_signed_out_before_being_asked_for_a_password_if_their_facility_is_not_active(): void
    {
        $user = User::factory()->for(Facility::factory()->suspended())->doctor()->mustChangePassword()->create();

        $this->actingAs($user)->get(route('queue.index'))->assertRedirect(route('login'));

        $this->assertGuest();
    }
}
