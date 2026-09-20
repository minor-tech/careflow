<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Facility;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PatientLookupControllerTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    private User $receptionist;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facility = Facility::factory()->create();
        $this->receptionist = User::factory()->for($this->facility)->receptionist()->create();
    }

    public function test_returns_the_saved_details_of_a_known_patient(): void
    {
        Patient::factory()->for($this->facility)->create([
            'phone' => '+254712345678',
            'name' => 'Wanjiru Kamau',
            'dob' => '1990-05-17',
            'gender' => 'female',
        ]);

        $this->actingAs($this->receptionist)
            ->getJson(route('patients.lookup', ['phone' => '0712 345 678']))
            ->assertOk()
            ->assertExactJson([
                'found' => true,
                'patient' => ['name' => 'Wanjiru Kamau', 'dob' => '1990-05-17', 'gender' => 'female'],
            ]);
    }

    public function test_a_patient_with_no_date_of_birth_or_gender_comes_back_with_nulls(): void
    {
        Patient::factory()->for($this->facility)->create(['phone' => '+254712345678', 'name' => 'Wanjiru Kamau']);

        $this->actingAs($this->receptionist)
            ->getJson(route('patients.lookup', ['phone' => '+254712345678']))
            ->assertJsonPath('patient.dob', null)
            ->assertJsonPath('patient.gender', null);
    }

    public function test_finds_the_patient_however_the_number_is_typed(): void
    {
        Patient::factory()->for($this->facility)->create(['phone' => '+254712345678']);

        foreach (['0712345678', '712345678', '254712345678', '+254 712 345 678'] as $typed) {
            $this->actingAs($this->receptionist)
                ->getJson(route('patients.lookup', ['phone' => $typed]))
                ->assertJsonPath('found', true);
        }
    }

    public function test_reports_not_found_for_an_unknown_number(): void
    {
        $this->actingAs($this->receptionist)
            ->getJson(route('patients.lookup', ['phone' => '0700 000 000']))
            ->assertOk()
            ->assertExactJson(['found' => false]);
    }

    public function test_never_reveals_another_facilitys_patient(): void
    {
        Patient::factory()->create(['phone' => '+254712345678', 'name' => 'Theirs']);

        $this->actingAs($this->receptionist)
            ->getJson(route('patients.lookup', ['phone' => '0712345678']))
            ->assertExactJson(['found' => false]);
    }

    public function test_a_half_typed_or_missing_number_is_simply_not_found(): void
    {
        Patient::factory()->for($this->facility)->create(['phone' => '+254712345678']);

        $this->actingAs($this->receptionist)->getJson(route('patients.lookup', ['phone' => '0712']))->assertExactJson(['found' => false]);
        $this->actingAs($this->receptionist)->getJson(route('patients.lookup'))->assertExactJson(['found' => false]);
    }

    public function test_is_limited_to_60_lookups_a_minute(): void
    {
        $this->actingAs($this->receptionist);

        foreach (range(1, 60) as $ignored) {
            $this->getJson(route('patients.lookup', ['phone' => '0700 000 000']))->assertOk();
        }

        $this->getJson(route('patients.lookup', ['phone' => '0700 000 000']))->assertTooManyRequests();
    }

    public function test_guests_get_a_401_and_doctors_a_403(): void
    {
        $this->getJson(route('patients.lookup', ['phone' => '0712345678']))->assertUnauthorized();

        $this->actingAs(User::factory()->for($this->facility)->doctor()->create())
            ->getJson(route('patients.lookup', ['phone' => '0712345678']))
            ->assertForbidden();
    }
}
