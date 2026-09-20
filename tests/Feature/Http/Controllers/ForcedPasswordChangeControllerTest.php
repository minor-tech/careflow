<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\DepartmentType;
use App\Models\Department;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ForcedPasswordChangeControllerTest extends TestCase
{
    use RefreshDatabase;

    private const TEMPORARY = 'Temp-Pass-123';

    private User $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $facility = Facility::factory()->create();
        $department = Department::factory()->for($facility)->create(['type' => DepartmentType::Consultation]);
        $this->doctor = User::factory()->for($facility)->doctor()->mustChangePassword()->create([
            'email' => 'otieno@upendo.test',
            'password' => self::TEMPORARY,
            'department_id' => $department->id,
        ]);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function newPassword(array $overrides = []): array
    {
        return ['password' => 'My-own-secret-9', 'password_confirmation' => 'My-own-secret-9', ...$overrides];
    }

    public function test_logging_in_with_the_temporary_password_leads_straight_to_choosing_a_new_one(): void
    {
        $this->post('/login', ['email' => 'otieno@upendo.test', 'password' => self::TEMPORARY])
            ->assertRedirect(route('dashboard'));

        $this->get(route('dashboard'))->assertRedirect(route('password.force'));

        $this->get(route('password.force'))
            ->assertOk()
            ->assertSeeText('Choose your own password')
            ->assertSee(route('password.force.store'), false);
    }

    public function test_the_screen_offers_a_way_out_by_logging_out(): void
    {
        $this->actingAs($this->doctor)
            ->get(route('password.force'))
            ->assertSee(route('logout'), false)
            ->assertSeeText('Log out instead');
    }

    public function test_choosing_a_new_password_ends_the_temporary_state_and_lets_them_in(): void
    {
        $this->actingAs($this->doctor)
            ->post(route('password.force.store'), $this->newPassword())
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasNoErrors();

        $this->doctor->refresh();
        $this->assertFalse($this->doctor->must_change_password);
        $this->assertTrue(Hash::check('My-own-secret-9', $this->doctor->password));
        $this->assertNotSame('My-own-secret-9', $this->doctor->password);

        $this->get(route('dashboard'))->assertRedirect(route('queue.index'));
        $this->get(route('queue.index'))->assertOk();
    }

    public function test_afterwards_only_the_new_password_works(): void
    {
        $this->actingAs($this->doctor)->post(route('password.force.store'), $this->newPassword());
        auth()->logout();

        $this->post('/login', ['email' => 'otieno@upendo.test', 'password' => self::TEMPORARY]);
        $this->assertGuest();

        $this->post('/login', ['email' => 'otieno@upendo.test', 'password' => 'My-own-secret-9']);
        $this->assertAuthenticatedAs($this->doctor);
        $this->get(route('queue.index'))->assertOk();
    }

    public function test_the_session_gets_a_new_id_once_the_credential_changes(): void
    {
        $this->actingAs($this->doctor)->get(route('password.force'));
        $before = session()->getId();

        $this->post(route('password.force.store'), $this->newPassword());

        $this->assertNotSame($before, session()->getId());
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function unacceptablePasswords(): array
    {
        return [
            'nothing entered' => [['password' => '', 'password_confirmation' => ''], 'required'],
            'too short' => [['password' => 'ab1', 'password_confirmation' => 'ab1'], 'at least 8'],
            'no numbers' => [['password' => 'onlyletters', 'password_confirmation' => 'onlyletters'], 'number'],
            'no letters' => [['password' => '1234567890', 'password_confirmation' => '1234567890'], 'letter'],
            'confirmation does not match' => [['password' => 'My-own-secret-9', 'password_confirmation' => 'different-1'], 'match'],
            'the temporary password again' => [['password' => self::TEMPORARY, 'password_confirmation' => self::TEMPORARY], 'different from the temporary'],
        ];
    }

    /**
     * @param  array<string, string>  $input
     */
    #[DataProvider('unacceptablePasswords')]
    public function test_a_password_that_will_not_do_is_refused_with_a_reason_and_they_stay_locked_to_this_screen(array $input, string $reasonContains): void
    {
        $this->actingAs($this->doctor)
            ->from(route('password.force'))
            ->post(route('password.force.store'), $input)
            ->assertRedirect(route('password.force'))
            ->assertSessionHasErrors('password');

        $this->assertStringContainsStringIgnoringCase($reasonContains, session('errors')->first('password'));
        $this->assertTrue($this->doctor->fresh()->must_change_password);
        $this->assertTrue(Hash::check(self::TEMPORARY, $this->doctor->fresh()->password));
        $this->get(route('queue.index'))->assertRedirect(route('password.force'));
    }

    public function test_someone_who_has_already_set_their_own_password_is_sent_on_from_the_screen(): void
    {
        $settled = User::factory()->for($this->doctor->facility)->doctor()->create(['password' => 'Settled-pass-1']);

        $this->actingAs($settled)->get(route('password.force'))->assertRedirect(route('dashboard'));
        $this->actingAs($settled)->post(route('password.force.store'), $this->newPassword())->assertRedirect(route('dashboard'));

        $this->assertTrue(Hash::check('Settled-pass-1', $settled->fresh()->password));
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get(route('password.force'))->assertRedirect(route('login'));
        $this->post(route('password.force.store'), $this->newPassword())->assertRedirect(route('login'));
    }

    public function test_a_suspended_account_is_signed_out_rather_than_allowed_to_choose_a_password(): void
    {
        $this->doctor->update(['status' => 'suspended']);

        $this->actingAs($this->doctor)->get(route('password.force'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertTrue(Hash::check(self::TEMPORARY, $this->doctor->fresh()->password));
    }
}
