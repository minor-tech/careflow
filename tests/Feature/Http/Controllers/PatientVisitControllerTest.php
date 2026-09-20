<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\DepartmentType;
use App\Enums\Gender;
use App\Enums\VisitStatus;
use App\Models\Department;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\QueueCounter;
use App\Models\User;
use App\Models\Visit;
use App\Services\TrackingQrCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PatientVisitControllerTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    private User $receptionist;

    private Department $reception;

    private Department $consultation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facility = Facility::factory()->create(['name' => 'Upendo Health Centre']);
        $this->receptionist = User::factory()->for($this->facility)->receptionist()->create();
        $this->reception = Department::factory()->for($this->facility)->create(['name' => 'Reception', 'type' => DepartmentType::Reception]);
        $this->consultation = Department::factory()->for($this->facility)->create(['name' => 'Consultation', 'type' => DepartmentType::Consultation]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'phone' => '0712 345 678',
            'name' => 'Wanjiru Kamau',
            'dob' => '',
            'gender' => '',
            'department_id' => '',
            ...$overrides,
        ];
    }

    private function register(array $overrides = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->receptionist)->post(route('patients.register.store'), $this->payload($overrides));
    }

    public function test_the_form_lists_only_this_facilitys_active_departments_with_reception_preselected(): void
    {
        Department::factory()->for($this->facility)->inactive()->create(['name' => 'Closed Ward']);
        Department::factory()->for(Facility::factory())->create(['name' => 'Someone Elses Ward']);

        $this->actingAs($this->receptionist)
            ->get(route('patients.register'))
            ->assertOk()
            ->assertSeeText('Register patient')
            ->assertSeeText('Consultation')
            ->assertDontSeeText('Closed Ward')
            ->assertDontSeeText('Someone Elses Ward')
            ->assertSee('value="'.$this->reception->id.'" selected', false);
    }

    public function test_the_form_asks_only_for_what_a_visit_needs(): void
    {
        $this->actingAs($this->receptionist)
            ->get(route('patients.register'))
            ->assertSee('name="phone"', false)
            ->assertSee('name="name"', false)
            ->assertSee('name="dob"', false)
            ->assertSee('name="gender"', false)
            ->assertSee('name="department_id"', false)
            ->assertDontSee('name="national_id"', false)
            ->assertDontSee('name="address"', false);
    }

    public function test_registering_a_new_patient_creates_them_and_a_waiting_visit_numbered_1(): void
    {
        $response = $this->register(['dob' => '1990-05-17', 'gender' => 'female']);

        $visit = Visit::sole();
        $response->assertRedirect(route('visits.confirmation', $visit));

        $patient = Patient::sole();
        $this->assertSame($this->facility->id, $patient->facility_id);
        $this->assertSame('Wanjiru Kamau', $patient->name);
        $this->assertSame('+254712345678', $patient->phone);
        $this->assertSame('1990-05-17', $patient->dob->toDateString());
        $this->assertSame(Gender::Female, $patient->gender);

        $this->assertSame($patient->id, $visit->patient_id);
        $this->assertSame($this->facility->id, $visit->facility_id);
        $this->assertSame(1, $visit->queue_number);
        $this->assertSame(VisitStatus::Waiting, $visit->status);
        $this->assertSame($this->receptionist->id, $visit->created_by);
        $this->assertNull($visit->completed_at);
    }

    public function test_the_optional_details_can_be_left_out(): void
    {
        $this->register()->assertSessionHasNoErrors();

        $patient = Patient::sole();
        $this->assertNull($patient->dob);
        $this->assertNull($patient->gender);
    }

    public function test_a_visit_starts_in_reception_unless_another_department_is_chosen(): void
    {
        $this->register();
        $this->register(['phone' => '0722 000 111', 'name' => 'Otieno', 'department_id' => (string) $this->consultation->id]);

        $this->assertSame(
            [$this->reception->id, $this->consultation->id],
            Visit::orderBy('id')->pluck('department_id')->all(),
        );
    }

    public function test_a_facility_with_no_reception_starts_the_visit_with_no_department(): void
    {
        $this->reception->delete();

        $this->actingAs($this->receptionist)
            ->get(route('patients.register'))
            ->assertSeeText('No department yet');

        $this->register();

        $this->assertNull(Visit::sole()->department_id);
    }

    public function test_back_to_back_registrations_get_sequential_queue_numbers(): void
    {
        $this->register(['phone' => '0711 000 001', 'name' => 'First']);
        $this->register(['phone' => '0711 000 002', 'name' => 'Second']);
        $this->register(['phone' => '0711 000 003', 'name' => 'Third']);

        $this->assertSame([1, 2, 3], Visit::orderBy('id')->pluck('queue_number')->all());
    }

    public function test_each_facility_counts_its_own_queue(): void
    {
        $otherReceptionist = User::factory()->for(Facility::factory())->receptionist()->create();

        $this->register(['phone' => '0711 000 001']);
        $this->register(['phone' => '0711 000 002']);
        $this->register(['phone' => '0711 000 001'], $otherReceptionist);

        $this->assertSame(1, Visit::where('facility_id', $otherReceptionist->facility_id)->sole()->queue_number);
        $this->assertSame(2, Visit::where('facility_id', $this->facility->id)->max('queue_number'));
    }

    public function test_queue_numbers_restart_at_1_on_a_new_day(): void
    {
        $this->travelTo(now()->setDateTime(2026, 9, 18, 9, 0, 0)->utc());
        $this->register(['phone' => '0711 000 001']);
        $this->register(['phone' => '0711 000 002']);

        $this->travelTo(now()->setDateTime(2026, 9, 19, 9, 0, 0)->utc());
        $this->register(['phone' => '0711 000 003']);

        $this->assertSame([1, 2, 1], Visit::orderBy('id')->pluck('queue_number')->all());
        $this->assertDatabaseCount('queue_counters', 2);
    }

    public function test_the_same_phone_number_reuses_the_patient_however_it_is_typed(): void
    {
        $this->register(['phone' => '0712 345 678']);
        $this->register(['phone' => '+254712345678']);
        $this->register(['phone' => '712345678']);

        $this->assertDatabaseCount('patients', 1);
        $this->assertSame(3, Patient::sole()->visits()->count());
        $this->assertSame([1, 2, 3], Visit::orderBy('id')->pluck('queue_number')->all());
    }

    public function test_a_returning_patient_has_a_changed_name_updated(): void
    {
        $this->register(['name' => 'Wanjiru Kamau']);
        $this->register(['name' => 'Wanjiru Kamau-Otieno']);

        $this->assertSame('Wanjiru Kamau-Otieno', Patient::sole()->name);
    }

    public function test_a_returning_patient_keeps_their_date_of_birth_and_gender_when_they_are_left_blank(): void
    {
        $this->register(['dob' => '1990-05-17', 'gender' => 'female']);
        $this->register(['dob' => '', 'gender' => '']);

        $patient = Patient::sole();
        $this->assertSame('1990-05-17', $patient->dob->toDateString());
        $this->assertSame(Gender::Female, $patient->gender);
    }

    public function test_a_returning_patients_date_of_birth_and_gender_are_updated_when_given(): void
    {
        $this->register(['dob' => '1990-05-17', 'gender' => 'female']);
        $this->register(['dob' => '1991-06-18', 'gender' => 'other']);

        $patient = Patient::sole();
        $this->assertSame('1991-06-18', $patient->dob->toDateString());
        $this->assertSame(Gender::Other, $patient->gender);
    }

    public function test_another_facilitys_patient_with_the_same_phone_is_neither_reused_nor_changed(): void
    {
        $theirs = Patient::factory()->create(['phone' => '+254712345678', 'name' => 'Theirs']);

        $this->register(['name' => 'Ours']);

        $this->assertDatabaseCount('patients', 2);
        $this->assertSame('Theirs', $theirs->fresh()->name);
        $this->assertSame($this->facility->id, Visit::sole()->patient->facility_id);
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function invalidInputs(): array
    {
        return [
            'no phone' => [['phone' => ''], 'phone'],
            'words instead of a phone' => [['phone' => 'call me'], 'phone'],
            'landline' => [['phone' => '020 123 4567'], 'phone'],
            'no name' => [['name' => ''], 'name'],
            'name too long' => [['name' => str_repeat('x', 256)], 'name'],
            'date of birth in the future' => [['dob' => '2999-01-01'], 'dob'],
            'date of birth before 1900' => [['dob' => '1800-01-01'], 'dob'],
            'not a date' => [['dob' => 'yesterday-ish'], 'dob'],
            'unknown gender' => [['gender' => 'robot'], 'gender'],
            'department that does not exist' => [['department_id' => '999999'], 'department_id'],
        ];
    }

    /**
     * @param  array<string, string>  $overrides
     */
    #[DataProvider('invalidInputs')]
    public function test_rejects_invalid_input_and_creates_nothing(array $overrides, string $errorField): void
    {
        $this->register($overrides)->assertSessionHasErrors($errorField);

        $this->assertDatabaseCount('patients', 0);
        $this->assertDatabaseCount('visits', 0);
    }

    public function test_a_rejected_registration_does_not_use_up_a_queue_number(): void
    {
        $this->register(['name' => ''])->assertSessionHasErrors('name');
        $this->register();

        $this->assertSame(1, Visit::sole()->queue_number);
        $this->assertSame(1, QueueCounter::sole()->last_number);
    }

    public function test_rejects_a_department_from_another_facility_or_an_inactive_one(): void
    {
        $foreign = Department::factory()->for(Facility::factory())->create();
        $inactive = Department::factory()->for($this->facility)->inactive()->create();

        $this->register(['department_id' => (string) $foreign->id])->assertSessionHasErrors('department_id');
        $this->register(['department_id' => (string) $inactive->id])->assertSessionHasErrors('department_id');

        $this->assertDatabaseCount('visits', 0);
    }

    public function test_the_confirmation_screen_shows_the_queue_number_as_the_hero(): void
    {
        $this->register(['phone' => '0711 000 001']);
        $this->register(['phone' => '0711 000 002', 'name' => 'Otieno Odhiambo']);
        $visit = Visit::orderByDesc('id')->first();

        $this->actingAs($this->receptionist)
            ->get(route('visits.confirmation', $visit))
            ->assertOk()
            ->assertSeeText('Upendo Health Centre')
            ->assertSeeText('Otieno Odhiambo')
            ->assertSeeText('Queue number')
            ->assertSee('aria-label="Queue number 2"', false)
            ->assertSee('tabular-nums', false)
            ->assertSee('Register another patient')
            ->assertSee(route('patients.register'), false);
    }

    public function test_the_confirmation_screen_shows_a_qr_code_and_link_to_the_patients_tracking_page(): void
    {
        $this->register();
        $visit = Visit::sole();

        $this->actingAs($this->receptionist)
            ->get(route('visits.confirmation', $visit))
            ->assertOk()
            // The image is the QR code for exactly this visit's link.
            ->assertSee('src="'.app(TrackingQrCode::class)->dataUri($visit->trackingUrl()).'"', false)
            ->assertSee('value="'.$visit->trackingUrl().'"', false)
            ->assertSee('Scan with a phone camera to follow this visit')
            ->assertSee('Copy link');
    }

    public function test_each_visits_qr_code_is_its_own(): void
    {
        $this->register(['phone' => '0711 000 001']);
        $this->register(['phone' => '0711 000 002']);
        [$first, $second] = Visit::orderBy('id')->get();

        $qrCodes = collect([$first, $second])->map(function (Visit $visit) {
            preg_match('/src="(data:image\/png;base64,[^"]+)"/', $this->actingAs($this->receptionist)->get(route('visits.confirmation', $visit))->getContent(), $found);

            return $found[1] ?? null;
        });

        $this->assertNotNull($qrCodes[0]);
        $this->assertNotEquals($qrCodes[0], $qrCodes[1]);
    }

    public function test_the_confirmation_screen_can_be_reloaded_without_registering_again(): void
    {
        $this->register();
        $visit = Visit::sole();

        $this->actingAs($this->receptionist)->get(route('visits.confirmation', $visit))->assertOk();
        $this->actingAs($this->receptionist)->get(route('visits.confirmation', $visit))->assertOk();

        $this->assertDatabaseCount('visits', 1);
    }

    public function test_another_facilitys_confirmation_screen_does_not_exist_for_you(): void
    {
        $theirs = Visit::factory()->create();

        $this->actingAs($this->receptionist)->get(route('visits.confirmation', $theirs))->assertNotFound();
        $this->actingAs($this->receptionist)->get(route('visits.confirmation', 999999))->assertNotFound();
    }

    public function test_a_facility_admin_can_register_patients_too(): void
    {
        $admin = User::factory()->for($this->facility)->create();

        $this->register([], $admin)->assertRedirect(route('visits.confirmation', Visit::sole()));

        $this->assertSame($admin->id, Visit::sole()->created_by);
    }
}
