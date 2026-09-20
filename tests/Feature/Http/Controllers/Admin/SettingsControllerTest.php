<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facility = Facility::factory()->create(['slug' => 'upendo']);
        $this->admin = User::factory()->for($this->facility)->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function settings(array $overrides = []): array
    {
        return [
            'remote_queue_enabled' => 1,
            'remote_queue_max_pending' => 30,
            'remote_queue_accept_until' => '16:30',
            'remote_queue_grace_minutes' => 7,
            'remote_queue_allow_doctor_choice' => 1,
            'remote_queue_allow_service_choice' => 1,
            'self_checkin_enabled' => 1,
            ...$overrides,
        ];
    }

    public function test_a_new_facility_starts_with_everything_off_and_the_planned_limits(): void
    {
        $facility = Facility::factory()->create()->fresh();

        $this->assertFalse($facility->remote_queue_enabled);
        $this->assertSame(20, $facility->remote_queue_max_pending);
        $this->assertNull($facility->remote_queue_accept_until);
        $this->assertSame(10, $facility->remote_queue_grace_minutes);
        $this->assertFalse($facility->remote_queue_allow_doctor_choice, 'Off by default.');
        $this->assertTrue($facility->remote_queue_allow_service_choice);
        $this->assertFalse($facility->self_checkin_enabled);
    }

    public function test_the_page_shows_the_current_settings_and_the_public_address(): void
    {
        $this->facility->update(['remote_queue_enabled' => true, 'remote_queue_max_pending' => 12, 'remote_queue_accept_until' => '15:45', 'remote_queue_grace_minutes' => 8]);

        $this->actingAs($this->admin)
            ->get(route('admin.settings'))
            ->assertOk()
            ->assertSeeText('Remote queue')
            ->assertSeeText('Self check-in')
            ->assertSee('value="12"', false)
            ->assertSee('value="15:45"', false)
            ->assertSee('value="8"', false)
            ->assertSee(route('directory.show', 'upendo'), false)
            ->assertSee('action="'.route('admin.settings.update').'"', false);
    }

    public function test_saving_changes_every_setting(): void
    {
        $this->actingAs($this->admin)
            ->patch(route('admin.settings.update'), $this->settings())
            ->assertRedirect(route('admin.settings'))
            ->assertSessionHas('success', 'Settings saved.');

        $facility = $this->facility->fresh();
        $this->assertTrue($facility->remote_queue_enabled);
        $this->assertSame(30, $facility->remote_queue_max_pending);
        $this->assertSame('16:30', substr($facility->remote_queue_accept_until, 0, 5));
        $this->assertSame(7, $facility->remote_queue_grace_minutes);
        $this->assertTrue($facility->remote_queue_allow_doctor_choice);
        $this->assertTrue($facility->self_checkin_enabled);
    }

    public function test_unticked_boxes_switch_things_off_and_an_empty_cutoff_means_all_day(): void
    {
        $this->facility->update(['remote_queue_enabled' => true, 'remote_queue_accept_until' => '16:00', 'self_checkin_enabled' => true, 'remote_queue_allow_service_choice' => true]);

        $this->actingAs($this->admin)->patch(route('admin.settings.update'), [
            'remote_queue_max_pending' => 20,
            'remote_queue_accept_until' => '',
            'remote_queue_grace_minutes' => 10,
        ])->assertSessionHasNoErrors();

        $facility = $this->facility->fresh();
        $this->assertFalse($facility->remote_queue_enabled);
        $this->assertNull($facility->remote_queue_accept_until);
        $this->assertFalse($facility->self_checkin_enabled);
        $this->assertFalse($facility->remote_queue_allow_service_choice);
    }

    public function test_the_limits_must_be_sensible_with_a_message_for_each(): void
    {
        $this->actingAs($this->admin)->patch(route('admin.settings.update'), $this->settings(['remote_queue_max_pending' => 0]))
            ->assertSessionHasErrors(['remote_queue_max_pending' => 'Allow at least one waiting request.']);
        $this->actingAs($this->admin)->patch(route('admin.settings.update'), $this->settings(['remote_queue_max_pending' => 501]))
            ->assertSessionHasErrors(['remote_queue_max_pending' => 'Allow at most 500 waiting requests.']);
        $this->actingAs($this->admin)->patch(route('admin.settings.update'), $this->settings(['remote_queue_grace_minutes' => 0]))
            ->assertSessionHasErrors(['remote_queue_grace_minutes' => 'Give patients at least one minute.']);
        $this->actingAs($this->admin)->patch(route('admin.settings.update'), $this->settings(['remote_queue_grace_minutes' => 61]))
            ->assertSessionHasErrors(['remote_queue_grace_minutes' => 'A grace period of more than an hour would hold up the queue.']);
        $this->actingAs($this->admin)->patch(route('admin.settings.update'), $this->settings(['remote_queue_accept_until' => 'teatime']))
            ->assertSessionHasErrors(['remote_queue_accept_until' => 'Enter the time as hours and minutes, for example 16:00.']);
        $this->actingAs($this->admin)->patch(route('admin.settings.update'), $this->settings(['remote_queue_max_pending' => '', 'remote_queue_grace_minutes' => '']))
            ->assertSessionHasErrors(['remote_queue_max_pending', 'remote_queue_grace_minutes']);

        $this->assertSame(20, $this->facility->fresh()->remote_queue_max_pending);
    }

    public function test_only_these_settings_can_be_changed_this_way_and_only_on_your_own_facility(): void
    {
        $other = Facility::factory()->create(['name' => 'Someone Elses Clinic']);

        $this->actingAs($this->admin)->patch(route('admin.settings.update'), $this->settings([
            'facility_id' => $other->id,
            'slug' => 'hijacked',
            'status' => 'suspended',
            'name' => 'Renamed',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('upendo', $this->facility->fresh()->slug);
        $this->assertSame('active', $this->facility->fresh()->status->value);
        $this->assertNotSame('Renamed', $this->facility->fresh()->name);
        $this->assertFalse($other->fresh()->remote_queue_enabled);
    }

    public function test_only_the_admin_can_see_or_change_them(): void
    {
        foreach ([User::factory()->for($this->facility)->receptionist()->create(), User::factory()->for($this->facility)->doctor()->create(), User::factory()->for($this->facility)->nurse()->create()] as $staff) {
            $this->actingAs($staff)->get(route('admin.settings'))->assertForbidden();
            $this->actingAs($staff)->patch(route('admin.settings.update'), $this->settings())->assertForbidden();
        }

        $this->assertFalse($this->facility->fresh()->remote_queue_enabled);
    }

    public function test_a_guest_is_sent_to_log_in(): void
    {
        $this->get(route('admin.settings'))->assertRedirect(route('login'));
        $this->patch(route('admin.settings.update'), $this->settings())->assertRedirect(route('login'));
    }
}
