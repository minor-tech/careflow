<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Facility;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PatientRegistrationAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{UserRole}>
     */
    public static function rolesWithoutAccess(): array
    {
        return [
            'doctor' => [UserRole::Doctor],
            'nurse' => [UserRole::Nurse],
        ];
    }

    #[DataProvider('rolesWithoutAccess')]
    public function test_doctors_and_nurses_cannot_open_the_registration_form(UserRole $role): void
    {
        $this->actingAs(User::factory()->withRole($role)->create())
            ->get(route('patients.register'))
            ->assertForbidden();
    }

    #[DataProvider('rolesWithoutAccess')]
    public function test_doctors_and_nurses_cannot_register_a_patient_by_posting_directly(UserRole $role): void
    {
        $this->actingAs(User::factory()->withRole($role)->create())
            ->post(route('patients.register.store'), ['phone' => '0712345678', 'name' => 'Sneaky'])
            ->assertForbidden();

        $this->assertDatabaseCount('patients', 0);
        $this->assertDatabaseCount('visits', 0);
    }

    #[DataProvider('rolesWithoutAccess')]
    public function test_doctors_and_nurses_cannot_see_a_confirmation_screen(UserRole $role): void
    {
        $user = User::factory()->withRole($role)->create();
        $visit = Visit::factory()->for($user->facility)->create();

        $this->actingAs($user)->get(route('visits.confirmation', $visit))->assertForbidden();
    }

    public function test_a_system_admin_cannot_register_patients(): void
    {
        $this->actingAs(User::factory()->systemAdmin()->create())
            ->get(route('patients.register'))
            ->assertForbidden();
    }

    public function test_receptionists_and_admins_can_open_the_form(): void
    {
        $facility = Facility::factory()->create();

        foreach ([UserRole::Receptionist, UserRole::Admin] as $role) {
            $this->actingAs(User::factory()->for($facility)->withRole($role)->create())
                ->get(route('patients.register'))
                ->assertOk();
        }
    }

    public function test_guests_are_sent_to_login(): void
    {
        $visit = Visit::factory()->create();

        $this->get(route('patients.register'))->assertRedirect(route('login'));
        $this->post(route('patients.register.store'), ['phone' => '0712345678', 'name' => 'Anon'])->assertRedirect(route('login'));
        $this->get(route('visits.confirmation', $visit))->assertRedirect(route('login'));

        $this->assertDatabaseCount('patients', 1);
    }

    public function test_a_receptionist_at_a_facility_that_is_not_active_is_signed_out(): void
    {
        $receptionist = User::factory()->for(Facility::factory()->pendingReview())->receptionist()->create();

        $this->actingAs($receptionist)
            ->get(route('patients.register'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_the_pending_admin_is_sent_to_the_status_page_and_cannot_register_patients(): void
    {
        $admin = User::factory()->for(Facility::factory()->pendingReview())->create();

        $this->actingAs($admin)
            ->post(route('patients.register.store'), ['phone' => '0712345678', 'name' => 'Early Bird'])
            ->assertRedirect(route('facility.status'));

        $this->assertDatabaseCount('patients', 0);
    }

    public function test_the_sidebar_offers_registration_only_to_roles_who_can_use_it(): void
    {
        $facility = Facility::factory()->create();

        foreach ([UserRole::Receptionist, UserRole::Admin] as $role) {
            $this->actingAs(User::factory()->for($facility)->withRole($role)->create())
                ->get(route($role === UserRole::Admin ? 'admin.facility' : 'queue.index'))
                ->assertSee(route('patients.register'), false);
        }

        foreach ([UserRole::Doctor, UserRole::Nurse] as $role) {
            $this->actingAs(User::factory()->for($facility)->withRole($role)->create())
                ->get(route('queue.index'))
                ->assertDontSee(route('patients.register'), false);
        }
    }
}
