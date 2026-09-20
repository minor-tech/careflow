<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Enums\UserStatus;
use App\Models\Department;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class StaffTemporaryPasswordTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    private User $admin;

    private Department $consultation;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->facility = Facility::factory()->create();
        $this->admin = User::factory()->for($this->facility)->create();
        $this->consultation = Department::factory()->for($this->facility)->create(['name' => 'Consultation']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createDoctor(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->admin)->post(route('staff.store'), [
            'name' => 'Otieno Odhiambo',
            'email' => 'otieno@upendo.test',
            'phone' => '0733 222 333',
            'role' => 'doctor',
            'department_id' => $this->consultation->id,
            ...$overrides,
        ]);
    }

    private function passwordOn(TestResponse $page): ?string
    {
        preg_match('/id="temporary-password"[^>]*>([^<]+)</', $page->getContent(), $matches);

        return isset($matches[1]) ? trim($matches[1]) : null;
    }

    public function test_after_creating_a_doctor_the_admin_is_shown_the_temporary_password_and_told_to_pass_it_on(): void
    {
        $page = $this->followRedirects($this->createDoctor());

        $page->assertOk()
            ->assertSeeText('Account created for Otieno Odhiambo.')
            ->assertSeeText('Temporary password')
            ->assertSeeText('Share this with Otieno Odhiambo directly.')
            ->assertSeeText("It won't be shown again.")
            ->assertSeeText("They'll be asked to choose their own password when they first log in.");

        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Za-km-z2-9]{12}$/', $this->passwordOn($page));
    }

    public function test_the_password_shown_is_the_one_that_works_and_only_its_hash_is_stored(): void
    {
        $shown = $this->passwordOn($this->followRedirects($this->createDoctor()));
        $doctor = User::where('email', 'otieno@upendo.test')->sole();

        $this->assertNotSame($shown, $doctor->password);
        $this->assertTrue(Hash::check($shown, $doctor->password));

        auth()->logout();
        $this->post('/login', ['email' => 'otieno@upendo.test', 'password' => $shown])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($doctor);
    }

    public function test_the_password_is_shown_exactly_once(): void
    {
        $this->createDoctor()->assertRedirect(route('staff.index'));

        $first = $this->get(route('staff.index'));
        $second = $this->get(route('staff.index'));
        $third = $this->get(route('staff.index'));

        $this->assertNotNull($this->passwordOn($first));
        $this->assertNull($this->passwordOn($second));
        $this->assertNull($this->passwordOn($third));
        $second->assertDontSeeText("It won't be shown again.");
    }

    public function test_the_password_is_not_kept_readable_in_the_session_between_the_two_requests(): void
    {
        $this->createDoctor();

        $flashed = session('temporary_password');
        $shown = $this->passwordOn($this->get(route('staff.index')));

        $this->assertNotNull($shown);
        $this->assertNotSame($shown, $flashed['password']);
        $this->assertStringNotContainsString($shown, serialize(session()->all()));
    }

    public function test_the_page_showing_a_password_may_not_be_cached_but_the_ordinary_list_may_as_before(): void
    {
        $this->createDoctor();

        $this->get(route('staff.index'))->assertHeader('Cache-Control', 'no-store, private');

        $ordinary = $this->get(route('staff.index'));
        $this->assertStringNotContainsString('no-store', $ordinary->headers->get('Cache-Control'));
    }

    public function test_a_tampered_or_undecryptable_reveal_shows_nothing_and_does_not_break_the_page(): void
    {
        $this->actingAs($this->admin)
            ->withSession(['temporary_password' => ['name' => 'Someone', 'password' => 'not-encrypted', 'created' => true]])
            ->get(route('staff.index'))
            ->assertOk()
            ->assertDontSeeText('Temporary password');
    }

    public function test_a_new_account_starts_active_with_two_factor_on_and_must_set_its_own_password(): void
    {
        $this->createDoctor();

        $doctor = User::where('email', 'otieno@upendo.test')->sole();

        $this->assertSame(UserStatus::Active, $doctor->status);
        $this->assertTrue($doctor->two_factor_enabled);
        $this->assertTrue($doctor->must_change_password);
    }

    public function test_the_list_marks_people_who_have_not_yet_set_their_own_password(): void
    {
        User::factory()->for($this->facility)->nurse()->mustChangePassword()->create(['name' => 'New Nancy']);
        User::factory()->for($this->facility)->nurse()->create(['name' => 'Settled Sam']);

        $html = $this->actingAs($this->admin)->get(route('staff.index'))->getContent();

        $this->assertSame(1, substr_count($html, "Hasn't set their own password yet"));
    }

    public function test_the_create_form_explains_the_password_is_shown_once_not_sent(): void
    {
        $this->actingAs($this->admin)
            ->get(route('staff.create'))
            ->assertSeeText('show it to you once, to pass on to them yourself')
            ->assertDontSeeText('notification channels');
    }

    public function test_a_failed_creation_shows_no_password(): void
    {
        $page = $this->actingAs($this->admin)->followingRedirects()->post(route('staff.store'), ['name' => '']);

        $this->assertNull($this->passwordOn($page));
        $this->assertDatabaseCount('users', 1);
    }

    public function test_only_a_facility_admin_can_be_given_a_password_to_hand_over(): void
    {
        $this->actingAs(User::factory()->for($this->facility)->receptionist()->create())
            ->post(route('staff.store'), ['name' => 'Sneaky', 'email' => 's@upendo.test', 'phone' => '0733 222 333', 'role' => 'nurse', 'department_id' => $this->consultation->id])
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 's@upendo.test']);
    }

    public function test_resetting_a_password_shows_a_new_one_once_and_the_old_one_stops_working(): void
    {
        $doctor = User::factory()->for($this->facility)->doctor()->create(['email' => 'otieno@upendo.test', 'password' => 'Old-password-1']);

        $this->actingAs($this->admin)->post(route('staff.reset-password', $doctor))->assertRedirect(route('staff.index'));

        $page = $this->get(route('staff.index'));
        $page->assertSeeText('New temporary password for '.$doctor->name.'.');
        $newPassword = $this->passwordOn($page);
        $this->assertNull($this->passwordOn($this->get(route('staff.index'))));

        $doctor->refresh();
        $this->assertTrue(Hash::check($newPassword, $doctor->password));
        $this->assertFalse(Hash::check('Old-password-1', $doctor->password));
        $this->assertTrue($doctor->must_change_password);
    }

    public function test_only_own_facility_non_admin_staff_can_have_their_password_reset(): void
    {
        $outsider = User::factory()->for(Facility::factory())->doctor()->create();
        $otherAdmin = User::factory()->for($this->facility)->create();
        $hash = $outsider->password;

        $this->actingAs($this->admin)->post(route('staff.reset-password', $outsider))->assertNotFound();
        $this->actingAs($this->admin)->post(route('staff.reset-password', $otherAdmin))->assertForbidden();
        $this->actingAs(User::factory()->for($this->facility)->doctor()->create())->post(route('staff.reset-password', $outsider))->assertForbidden();

        $this->assertSame($hash, $outsider->fresh()->password);
    }

    public function test_the_edit_page_offers_the_reset_for_lost_passwords(): void
    {
        $doctor = User::factory()->for($this->facility)->doctor()->create();

        $this->actingAs($this->admin)
            ->get(route('staff.edit', $doctor))
            ->assertSeeText('Lost or forgotten password')
            ->assertSee(route('staff.reset-password', $doctor), false);
    }
}
