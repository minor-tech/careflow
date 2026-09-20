<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\RegistersFacilities;
use Tests\TestCase;

class FacilityRegistrationWizardTest extends TestCase
{
    use RefreshDatabase;
    use RegistersFacilities;

    public function test_registration_entry_redirects_to_the_first_screen(): void
    {
        $this->get(route('facility.register'))
            ->assertRedirect(route('facility.register.step', 1));
    }

    public function test_first_screen_renders_with_progress_rail(): void
    {
        $this->get(route('facility.register.step', 1))
            ->assertOk()
            ->assertSeeText('Facility identity')
            ->assertSee('aria-label="Registration progress"', false);
    }

    public function test_every_screen_renders_once_the_earlier_ones_are_done(): void
    {
        $this->completeSteps(8);

        foreach (range(1, 9) as $step) {
            $this->get(route('facility.register.step', $step))->assertOk();
        }
    }

    public function test_later_screens_redirect_back_until_earlier_ones_are_completed(): void
    {
        $this->completeSteps(2);

        $this->get(route('facility.register.step', 5))
            ->assertRedirect(route('facility.register.step', 3));
    }

    public function test_saving_a_screen_out_of_order_is_forbidden(): void
    {
        $this->post(route('facility.register.save', 3), $this->stepPayload(3))
            ->assertForbidden();
    }

    public function test_progress_is_kept_in_the_session_and_resumed(): void
    {
        $this->completeSteps(3);

        $this->get(route('facility.register'))
            ->assertRedirect(route('facility.register.step', 4));

        $this->get(route('facility.register.step', 1))
            ->assertSee('value="Upendo Health Centre"', false);
    }

    public function test_screen_one_rejects_missing_and_unknown_values(): void
    {
        $this->post(route('facility.register.save', 1), [
            'name' => '',
            'facility_type' => 'spa',
            'license_number' => '',
        ])->assertSessionHasErrors(['name', 'facility_type', 'license_number']);

        $this->assertNull(session('facility_registration.data'));
    }

    public function test_screen_two_needs_a_real_county_and_paired_coordinates(): void
    {
        $this->completeSteps(1);

        $this->post(route('facility.register.save', 2), $this->stepPayload(2, [
            'county' => 'Atlantis',
            'longitude' => '',
        ]))->assertSessionHasErrors(['county', 'longitude']);
    }

    public function test_screen_two_accepts_a_facility_without_gps(): void
    {
        $this->completeSteps(1);

        $this->post(route('facility.register.save', 2), $this->stepPayload(2, ['latitude' => '', 'longitude' => '']))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('facility.register.step', 3));
    }

    public function test_screen_three_rejects_a_malformed_phone_and_email(): void
    {
        $this->completeSteps(2);

        $this->post(route('facility.register.save', 3), $this->stepPayload(3, [
            'phone' => 'call me',
            'email' => 'not-an-email',
        ]))->assertSessionHasErrors(['phone', 'email']);
    }

    public function test_screen_four_requires_opening_hours_unless_the_facility_is_24_hours(): void
    {
        $this->completeSteps(3);

        $this->post(route('facility.register.save', 4), $this->stepPayload(4, ['opens_at' => '', 'closes_at' => '']))
            ->assertSessionHasErrors(['opens_at', 'closes_at']);

        $this->post(route('facility.register.save', 4), $this->stepPayload(4, [
            'is_24hr' => '1',
            'opens_at' => '',
            'closes_at' => '',
        ]))->assertSessionHasNoErrors();

        $this->assertTrue(session('facility_registration.data.is_24hr'));
        $this->assertNull(session('facility_registration.data.opens_at'));
    }

    public function test_screen_four_needs_a_day_and_a_department(): void
    {
        $this->completeSteps(3);

        $this->post(route('facility.register.save', 4), $this->stepPayload(4, [
            'operating_days' => [],
            'departments' => [],
        ]))->assertSessionHasErrors(['operating_days', 'departments']);
    }

    public function test_admin_password_is_kept_only_as_a_hash(): void
    {
        $this->completeSteps(5);

        $stored = session('facility_registration.data');

        $this->assertArrayNotHasKey('admin_password', $stored);
        $this->assertArrayNotHasKey('admin_password_confirmation', $stored);
        $this->assertTrue(Hash::check(self::ADMIN_PASSWORD, $stored['admin_password_hash']));
        $this->assertStringNotContainsString(self::ADMIN_PASSWORD, serialize(session()->all()));
    }

    public function test_returning_to_the_admin_screen_can_keep_the_existing_password(): void
    {
        $this->completeSteps(5);
        $hash = session('facility_registration.data.admin_password_hash');

        $this->post(route('facility.register.save', 5), $this->stepPayload(5, [
            'admin_name' => 'Wanjiru W. Kamau',
            'admin_password' => '',
            'admin_password_confirmation' => '',
        ]))->assertSessionHasNoErrors();

        $this->assertSame($hash, session('facility_registration.data.admin_password_hash'));
        $this->assertSame('Wanjiru W. Kamau', session('facility_registration.data.admin_name'));
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidAdminInputs(): array
    {
        return [
            'password too short' => [['admin_password' => 'ab1', 'admin_password_confirmation' => 'ab1'], 'admin_password'],
            'password without a number' => [['admin_password' => 'onlyletters', 'admin_password_confirmation' => 'onlyletters'], 'admin_password'],
            'password confirmation mismatch' => [['admin_password_confirmation' => 'Different123'], 'admin_password'],
            'malformed email' => [['admin_email' => 'nope'], 'admin_email'],
            'missing title' => [['admin_title' => ''], 'admin_title'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidAdminInputs')]
    public function test_admin_screen_rejects_invalid_input(array $overrides, string $errorField): void
    {
        $this->completeSteps(4);

        $this->post(route('facility.register.save', 5), $this->stepPayload(5, $overrides))
            ->assertSessionHasErrors($errorField);
    }

    public function test_admin_screen_rejects_an_email_that_already_has_an_account(): void
    {
        User::factory()->create(['email' => 'wanjiru@upendo.test']);
        $this->completeSteps(4);

        $this->post(route('facility.register.save', 5), $this->stepPayload(5))
            ->assertSessionHasErrors(['admin_email' => 'An account with this email already exists.']);
    }

    public function test_two_factor_can_be_switched_off(): void
    {
        $this->completeSteps(4);

        $this->post(route('facility.register.save', 5), $this->stepPayload(5, ['two_factor_enabled' => '0']))
            ->assertSessionHasNoErrors();

        $this->assertFalse(session('facility_registration.data.two_factor_enabled'));
    }

    public function test_staff_screen_can_be_skipped_and_discards_typed_rows(): void
    {
        $this->completeSteps(5);

        $this->post(route('facility.register.save', 6), [...$this->stepPayload(6), 'skip' => '1'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('facility.register.step', 7));

        $this->assertSame([], session('facility_registration.data.staff'));
    }

    public function test_staff_screen_ignores_blank_rows(): void
    {
        $this->completeSteps(5);

        $rows = [...$this->stepPayload(6)['staff'], ['name' => '', 'email' => '', 'phone' => '', 'role' => '', 'department' => '']];

        $this->post(route('facility.register.save', 6), ['staff' => $rows])->assertSessionHasNoErrors();

        $this->assertCount(1, session('facility_registration.data.staff'));
    }

    public function test_staff_screen_validates_each_invited_row(): void
    {
        $this->completeSteps(5);

        $this->post(route('facility.register.save', 6), ['staff' => [[
            'name' => 'Someone',
            'email' => 'wanjiru@upendo.test',
            'phone' => '0733 222 333',
            'role' => 'admin',
            'department' => 'pharmacy',
        ]]])->assertSessionHasErrors(['staff.0.email', 'staff.0.role', 'staff.0.department']);
    }

    public function test_staff_screen_rejects_duplicate_emails_between_rows(): void
    {
        $this->completeSteps(5);
        $row = $this->stepPayload(6)['staff'][0];

        $this->post(route('facility.register.save', 6), ['staff' => [$row, [...$row, 'name' => 'Twin']]])
            ->assertSessionHasErrors(['staff.0.email', 'staff.1.email']);
    }

    public function test_notification_screen_needs_at_least_one_channel(): void
    {
        $this->completeSteps(6);

        $this->post(route('facility.register.save', 7), $this->stepPayload(7, ['notification_channels' => []]))
            ->assertSessionHasErrors('notification_channels');
    }

    public function test_notification_screen_rejects_a_sender_id_with_spaces(): void
    {
        $this->completeSteps(6);

        $this->post(route('facility.register.save', 7), $this->stepPayload(7, ['sms_sender_id' => 'Upendo Health']))
            ->assertSessionHasErrors('sms_sender_id');
    }

    public function test_compliance_screen_needs_both_confirmations(): void
    {
        $this->completeSteps(7);

        $this->post(route('facility.register.save', 8), $this->stepPayload(8, [
            'agreed_dpa' => '',
            'confirms_patient_consent' => '',
        ]))->assertSessionHasErrors(['agreed_dpa', 'confirms_patient_consent']);
    }

    public function test_signed_in_users_are_sent_to_their_dashboard_instead_of_the_wizard(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('facility.register.step', 1))
            ->assertRedirect(route('dashboard'));
    }
}
