<?php

namespace Tests\Feature\View\Components;

use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppLayoutTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->for(Facility::factory()->create(['name' => 'Upendo Health Centre']))->create(['name' => 'Amina Wanjiru']);
    }

    public function test_an_admin_sees_every_section_in_three_labelled_groups(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.facility'))
            ->assertSeeInOrder([
                'Overview', 'Facility', 'Analytics',
                'Patient flow', 'Queue', 'Register patient', 'Notifications',
                'Staff &amp; structure', 'Staff', 'Departments', 'Settings',
            ], false);
    }

    public function test_a_receptionist_sees_only_the_patient_flow_group_with_queue_and_registration(): void
    {
        $facility = Facility::factory()->create();
        $receptionist = User::factory()->for($facility)->receptionist()->create();

        $this->actingAs($receptionist)
            ->get(route('queue.index'))
            ->assertSeeInOrder(['Patient flow', 'Queue', 'Register patient'])
            ->assertDontSeeText('Overview')
            ->assertDontSeeText('Analytics')
            ->assertDontSeeText('Departments')
            ->assertDontSeeText('Settings')
            ->assertDontSee(route('staff.index'), false)
            ->assertDontSee(route('notifications.index'), false);
    }

    public function test_a_doctor_sees_only_the_queue(): void
    {
        $doctor = User::factory()->for(Facility::factory()->create())->doctor()->create();

        $this->actingAs($doctor)
            ->get(route('queue.index'))
            ->assertSeeText('Patient flow')
            ->assertDontSeeText('Register patient');
    }

    public function test_a_system_admin_sees_the_platform_group_and_no_facility_name(): void
    {
        Facility::factory()->create(['name' => 'Upendo Health Centre']);

        $this->actingAs(User::factory()->systemAdmin()->create())
            ->get(route('system.facilities.pending'))
            ->assertSeeInOrder(['Platform', 'Pending facilities'])
            ->assertDontSeeText('Upendo Health Centre')
            ->assertDontSeeText('Patient flow');
    }

    public function test_only_the_current_section_is_highlighted_even_on_a_page_below_it(): void
    {
        $html = $this->actingAs($this->admin())->get(route('staff.create'))->getContent();

        $this->assertSame(1, substr_count($html, 'is-active'));
        $this->assertSame(1, substr_count($html, 'aria-current="page"'));
        $this->assertMatchesRegularExpression('/is-active">\s*<svg.*?<\/svg>\s*Staff\s*<\/a>/s', $html);
    }

    public function test_the_top_bar_names_the_group_and_the_page(): void
    {
        $this->actingAs($this->admin())
            ->get(route('staff.create'))
            ->assertSee('<div class="cf-topbar__crumb">Staff &amp; structure</div>', false)
            ->assertSee('<div class="cf-topbar__title">Add staff</div>', false);
    }

    public function test_a_page_outside_the_sections_falls_back_to_the_product_name_as_its_group(): void
    {
        $this->actingAs($this->admin())
            ->get(route('profile.edit'))
            ->assertSee('<div class="cf-topbar__crumb">CareFlow</div>', false)
            ->assertDontSee('is-active', false);
    }

    public function test_the_sidebar_shows_the_facility_and_the_signed_in_person_with_a_way_to_their_profile_and_out(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.facility'))
            ->assertSeeText('Upendo Health Centre')
            ->assertSee('<div class="cf-avatar gloss-fill" aria-hidden="true">AW</div>', false)
            ->assertSee('href="'.route('profile.edit').'"', false)
            ->assertSee('action="'.route('logout').'"', false);
    }

    public function test_the_public_site_does_not_use_the_dashboard_shell(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('cf-shell', false)
            ->assertDontSee('cf-sidebar', false);
    }
}
