<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Enums\NotificationChannel;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Department;
use App\Models\Facility;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitEvent;
use App\Notifications\StaffAccountCreated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class StaffControllerTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    private User $admin;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facility = Facility::factory()->create(['notification_channels' => [NotificationChannel::Email->value]]);
        $this->admin = User::factory()->for($this->facility)->create();
        $this->department = Department::factory()->for($this->facility)->create(['name' => 'Consultation']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function staffPayload(array $overrides = []): array
    {
        return [
            'name' => 'Otieno Odhiambo',
            'email' => 'otieno@upendo.test',
            'phone' => '0733 222 333',
            'role' => 'doctor',
            'department_id' => $this->department->id,
            ...$overrides,
        ];
    }

    public function test_index_lists_this_facilitys_staff_with_their_department_and_no_one_elses(): void
    {
        User::factory()->for($this->facility)->doctor()->create(['name' => 'Dr Ours', 'department_id' => $this->department->id]);
        User::factory()->for(Facility::factory())->doctor()->create(['name' => 'Dr Theirs']);

        $this->actingAs($this->admin)
            ->get(route('staff.index'))
            ->assertOk()
            ->assertSeeText('Dr Ours')
            ->assertSeeText('Consultation')
            ->assertDontSeeText('Dr Theirs');
    }

    public function test_suspended_staff_are_marked_on_the_list(): void
    {
        User::factory()->for($this->facility)->nurse()->suspended()->create(['name' => 'Nurse Paused']);

        $this->actingAs($this->admin)
            ->get(route('staff.index'))
            ->assertSeeText('Nurse Paused')
            ->assertSeeText('Suspended')
            ->assertSee('cf-status-pill--danger', false);
    }

    public function test_the_create_form_offers_staff_roles_but_not_admin_and_only_active_departments_of_this_facility(): void
    {
        Department::factory()->for($this->facility)->inactive()->create(['name' => 'Closed Ward']);
        Department::factory()->for(Facility::factory())->create(['name' => 'Someone Elses Ward']);

        $this->actingAs($this->admin)
            ->get(route('staff.create'))
            ->assertOk()
            ->assertSee('value="doctor"', false)
            ->assertSee('value="nurse"', false)
            ->assertSee('value="receptionist"', false)
            ->assertDontSee('value="admin"', false)
            ->assertSeeText('Consultation')
            ->assertDontSeeText('Closed Ward')
            ->assertDontSeeText('Someone Elses Ward');
    }

    public function test_store_creates_staff_tied_to_a_department_in_the_admins_facility(): void
    {
        Notification::fake();

        $this->actingAs($this->admin)
            ->post(route('staff.store'), $this->staffPayload())
            ->assertRedirect(route('staff.index'))
            ->assertSessionHas('temporary_password');

        $member = User::where('email', 'otieno@upendo.test')->sole();

        $this->assertSame(UserRole::Doctor, $member->role);
        $this->assertSame($this->facility->id, $member->facility_id);
        $this->assertSame($this->department->id, $member->department_id);
        $this->assertSame(UserStatus::Active, $member->status);
    }

    public function test_store_sends_a_working_temporary_password_and_only_stores_its_hash(): void
    {
        Notification::fake();

        $this->actingAs($this->admin)->post(route('staff.store'), $this->staffPayload());

        $member = User::where('email', 'otieno@upendo.test')->sole();
        $sentPassword = null;

        Notification::assertSentToTimes($member, StaffAccountCreated::class, 1);
        Notification::assertSentTo($member, StaffAccountCreated::class, function (StaffAccountCreated $notification) use ($member, &$sentPassword) {
            $lines = implode("\n", $notification->toMail($member)->introLines);
            preg_match('/Temporary password: (\S+)/', $lines, $matches);
            $sentPassword = $matches[1] ?? null;

            return $sentPassword !== null;
        });

        $this->assertNotSame($sentPassword, $member->password);
        $this->assertTrue(Hash::check($sentPassword, $member->password));
    }

    public function test_the_new_member_can_log_in_with_the_temporary_password(): void
    {
        Notification::fake();
        $this->actingAs($this->admin)->post(route('staff.store'), $this->staffPayload());
        $member = User::where('email', 'otieno@upendo.test')->sole();

        $password = null;
        Notification::assertSentTo($member, StaffAccountCreated::class, function (StaffAccountCreated $notification) use ($member, &$password) {
            preg_match('/Temporary password: (\S+)/', implode("\n", $notification->toMail($member)->introLines), $matches);
            $password = $matches[1];

            return true;
        });

        auth()->logout();
        $this->post('/login', ['email' => 'otieno@upendo.test', 'password' => $password])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($member);
    }

    public function test_store_rejects_bad_input(): void
    {
        $this->actingAs($this->admin)
            ->post(route('staff.store'), $this->staffPayload([
                'name' => '',
                'email' => 'not-an-email',
                'phone' => 'call me',
                'role' => 'admin',
                'department_id' => '',
            ]))
            ->assertSessionHasErrors(['name', 'email', 'phone', 'role', 'department_id']);

        $this->assertDatabaseMissing('users', ['email' => 'not-an-email']);
    }

    public function test_store_rejects_a_department_from_another_facility(): void
    {
        $foreignDepartment = Department::factory()->for(Facility::factory())->create();

        $this->actingAs($this->admin)
            ->post(route('staff.store'), $this->staffPayload(['department_id' => $foreignDepartment->id]))
            ->assertSessionHasErrors('department_id');

        $this->assertDatabaseMissing('users', ['email' => 'otieno@upendo.test']);
    }

    public function test_store_rejects_an_email_that_already_has_an_account_whatever_its_case(): void
    {
        User::factory()->for(Facility::factory())->create(['email' => 'otieno@upendo.test']);

        $this->actingAs($this->admin)
            ->post(route('staff.store'), $this->staffPayload(['email' => 'Otieno@Upendo.test']))
            ->assertSessionHasErrors(['email' => 'An account with this email already exists.']);
    }

    public function test_a_posted_facility_id_cannot_place_staff_in_another_facility(): void
    {
        Notification::fake();
        $other = Facility::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('staff.store'), $this->staffPayload(['facility_id' => $other->id, 'status' => 'suspended']))
            ->assertSessionHasNoErrors();

        $member = User::where('email', 'otieno@upendo.test')->sole();

        $this->assertSame($this->facility->id, $member->facility_id);
        $this->assertSame(UserStatus::Active, $member->status);
    }

    public function test_edit_shows_the_member_and_update_changes_role_department_and_status(): void
    {
        $member = User::factory()->for($this->facility)->nurse()->create();
        $newDepartment = Department::factory()->for($this->facility)->create();

        $this->actingAs($this->admin)->get(route('staff.edit', $member))->assertOk()->assertSee($member->email);

        $this->actingAs($this->admin)
            ->patch(route('staff.update', $member), [
                'name' => 'Renamed Nurse',
                'phone' => '0700 111 222',
                'role' => 'receptionist',
                'department_id' => $newDepartment->id,
                'status' => 'suspended',
            ])
            ->assertRedirect(route('staff.index'));

        $member->refresh();

        $this->assertSame('Renamed Nurse', $member->name);
        $this->assertSame(UserRole::Receptionist, $member->role);
        $this->assertSame($newDepartment->id, $member->department_id);
        $this->assertTrue($member->isSuspended());
    }

    public function test_update_cannot_promote_someone_to_admin_or_move_them_to_another_facility(): void
    {
        $member = User::factory()->for($this->facility)->nurse()->create(['department_id' => $this->department->id]);
        $foreignDepartment = Department::factory()->for(Facility::factory())->create();

        $this->actingAs($this->admin)
            ->patch(route('staff.update', $member), [
                'name' => $member->name,
                'phone' => $member->phone,
                'role' => 'admin',
                'department_id' => $foreignDepartment->id,
                'status' => 'active',
            ])
            ->assertSessionHasErrors(['role', 'department_id']);

        $this->assertSame(UserRole::Nurse, $member->fresh()->role);
    }

    public function test_removing_a_staff_member_suspends_them_and_never_deletes_the_account(): void
    {
        $member = User::factory()->for($this->facility)->nurse()->create();

        $this->actingAs($this->admin)
            ->delete(route('staff.destroy', $member))
            ->assertRedirect(route('staff.index'))
            ->assertSessionHasNoErrors();

        $this->assertModelExists($member);
        $this->assertTrue($member->fresh()->isSuspended());
    }

    public function test_suspending_leaves_the_visit_history_pointing_at_the_same_person(): void
    {
        $member = User::factory()->for($this->facility)->doctor()->create(['name' => 'Dr Recorded']);
        $visit = Visit::factory()->for($this->facility)->create(['department_id' => $this->department->id, 'created_by' => $member->id]);
        $event = VisitEvent::factory()->for($visit)->create(['user_id' => $member->id]);

        $this->actingAs($this->admin)->delete(route('staff.destroy', $member))->assertSessionHasNoErrors();

        $this->assertModelExists($event);
        $this->assertSame($member->id, $event->fresh()->user_id);
        $this->assertSame($member->id, $visit->fresh()->created_by);
        $this->assertSame('Dr Recorded', $event->fresh()->user->name);
    }

    public function test_a_suspended_member_cannot_log_in_and_a_session_they_already_had_ends_while_their_history_stays(): void
    {
        $member = User::factory()->for($this->facility)->doctor()->create(['email' => 'otieno@upendo.test', 'department_id' => $this->department->id]);
        $visit = Visit::factory()->for($this->facility)->create(['department_id' => $this->department->id]);
        $event = VisitEvent::factory()->for($visit)->create(['user_id' => $member->id]);

        $this->post('/login', ['email' => 'otieno@upendo.test', 'password' => 'password']);
        $this->assertAuthenticatedAs($member);
        auth()->logout();

        $this->actingAs($this->admin)->delete(route('staff.destroy', $member));
        auth()->logout();

        $this->post('/login', ['email' => 'otieno@upendo.test', 'password' => 'password'])
            ->assertSessionHasErrors(['email' => 'Your account has been suspended. Contact your facility admin.']);
        $this->assertGuest();

        // Someone already signed in when it happened is signed out on their next request.
        $this->actingAs($member->fresh())->get(route('queue.index'))->assertRedirect(route('login'));
        $this->assertGuest();

        $this->assertSame($member->id, $event->fresh()->user_id);
        $this->assertSame(1, $member->visitEvents()->count());
    }

    public function test_the_suspension_is_confirmed_on_the_staff_list_and_can_be_undone_by_editing(): void
    {
        $member = User::factory()->for($this->facility)->receptionist()->create(['name' => 'Rita Registered', 'department_id' => $this->department->id]);

        $this->actingAs($this->admin)
            ->followingRedirects()
            ->delete(route('staff.destroy', $member))
            ->assertSeeText('Rita Registered was suspended. They can no longer log in, and their history is kept.');

        $this->actingAs($this->admin)->patch(route('staff.update', $member), [
            'name' => $member->name,
            'phone' => $member->phone,
            'role' => 'receptionist',
            'department_id' => $this->department->id,
            'status' => 'active',
        ])->assertSessionHasNoErrors();

        $this->assertFalse($member->fresh()->isSuspended());
    }

    public function test_the_list_offers_suspend_not_remove_and_only_for_people_who_are_not_already_suspended(): void
    {
        User::factory()->for($this->facility)->nurse()->create(['name' => 'Active Ann']);
        User::factory()->for($this->facility)->nurse()->suspended()->create(['name' => 'Paused Pat']);

        $html = $this->actingAs($this->admin)->get(route('staff.index'))->getContent();

        $this->assertStringContainsString('Suspend', $html);
        $this->assertStringNotContainsString('Remove', $html);
        // One delete form for the one person who is not suspended already (the admin has none).
        $this->assertSame(1, substr_count($html, 'name="_method" value="DELETE"'));
    }

    public function test_admin_accounts_cannot_be_edited_or_removed_from_here_including_your_own(): void
    {
        $otherAdmin = User::factory()->for($this->facility)->create();

        foreach ([$this->admin, $otherAdmin] as $target) {
            $this->actingAs($this->admin)->get(route('staff.edit', $target))->assertForbidden();
            $this->actingAs($this->admin)->delete(route('staff.destroy', $target))->assertForbidden();
        }

        $this->assertModelExists($this->admin);
        $this->assertModelExists($otherAdmin);
    }

    public function test_another_facilitys_staff_are_invisible_to_edit_update_and_destroy(): void
    {
        $outsider = User::factory()->for(Facility::factory())->nurse()->create(['name' => 'Outsider']);

        $this->actingAs($this->admin)->get(route('staff.edit', $outsider))->assertNotFound();
        $this->actingAs($this->admin)
            ->patch(route('staff.update', $outsider), [
                'name' => 'Hijacked',
                'phone' => '0700 111 222',
                'role' => 'nurse',
                'department_id' => $this->department->id,
                'status' => 'suspended',
            ])
            ->assertNotFound();
        $this->actingAs($this->admin)->delete(route('staff.destroy', $outsider))->assertNotFound();

        $outsider->refresh();
        $this->assertSame('Outsider', $outsider->name);
        $this->assertFalse($outsider->isSuspended());
    }

    public function test_a_system_admin_cannot_manage_a_facilitys_staff(): void
    {
        $this->actingAs(User::factory()->systemAdmin()->create())
            ->get(route('staff.index'))
            ->assertForbidden();
    }

    public function test_a_facility_admin_cannot_create_a_system_admin_or_any_admin_through_the_staff_form(): void
    {
        foreach (['system_admin', 'admin'] as $role) {
            $this->actingAs($this->admin)
                ->post(route('staff.store'), $this->staffPayload(['role' => $role, 'email' => "{$role}@upendo.test"]))
                ->assertSessionHasErrors('role');

            $this->assertDatabaseMissing('users', ['email' => "{$role}@upendo.test"]);
        }
    }

    public function test_a_facility_admin_cannot_promote_staff_to_system_admin(): void
    {
        $member = User::factory()->for($this->facility)->nurse()->create(['department_id' => $this->department->id]);

        $this->actingAs($this->admin)
            ->patch(route('staff.update', $member), [
                'name' => $member->name,
                'phone' => $member->phone,
                'role' => 'system_admin',
                'department_id' => $this->department->id,
                'status' => 'active',
            ])
            ->assertSessionHasErrors('role');

        $this->assertSame(UserRole::Nurse, $member->fresh()->role);
    }

    public function test_the_create_form_does_not_offer_the_system_admin_role(): void
    {
        $this->actingAs($this->admin)
            ->get(route('staff.create'))
            ->assertDontSee('value="system_admin"', false);
    }

    public function test_every_person_is_their_own_card_with_a_silhouette_avatar_and_never_a_dot(): void
    {
        User::factory()->for($this->facility)->nurse()->count(2)->create();

        $html = $this->actingAs($this->admin)->get(route('staff.index'))->getContent();

        $this->assertSame(3, substr_count($html, '<article class="cf-person-card"'));
        $this->assertSame(3, substr_count($html, 'class="cf-person-avatar"'));
        $this->assertStringNotContainsString('cf-dot', $html);
        $this->assertStringNotContainsString('cf-list-row', $html);
    }

    public function test_a_card_shows_the_role_department_status_and_the_actions_for_that_person(): void
    {
        $doctor = User::factory()->for($this->facility)->doctor()->create(['name' => 'Dr Otieno', 'department_id' => $this->department->id]);
        User::factory()->for($this->facility)->nurse()->suspended()->create(['name' => 'Nurse Paused']);

        $this->actingAs($this->admin)
            ->get(route('staff.index'))
            ->assertSeeTextInOrder(['Dr Otieno', 'Doctor', 'Consultation', 'Active', 'Edit', 'Suspend'])
            ->assertSee(route('staff.edit', $doctor), false)
            ->assertSeeTextInOrder(['Nurse Paused', 'Nurse', 'No department', 'Suspended', 'Edit'])
            ->assertSee('btn-danger btn-sm', false)
            ->assertSee('btn-outline btn-sm', false);
    }
}
