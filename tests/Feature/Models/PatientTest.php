<?php

namespace Tests\Feature\Models;

use App\Enums\Gender;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\Visit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PatientTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_patient_belongs_to_a_facility_and_has_visits(): void
    {
        $facility = Facility::factory()->create();
        $patient = Patient::factory()->for($facility)->create();
        $visit = Visit::factory()->for($facility)->for($patient)->create();

        $this->assertTrue($patient->facility->is($facility));
        $this->assertTrue($patient->visits->contains($visit));
        $this->assertTrue($facility->patients->contains($patient));
    }

    public function test_dob_and_gender_are_cast_and_both_are_optional(): void
    {
        $bare = Patient::factory()->create();
        $detailed = Patient::factory()->create(['dob' => '1990-05-17', 'gender' => 'female']);

        $this->assertNull($bare->fresh()->dob);
        $this->assertNull($bare->fresh()->gender);
        $this->assertSame('1990-05-17', $detailed->fresh()->dob->toDateString());
        $this->assertSame(Gender::Female, $detailed->fresh()->gender);
    }

    public function test_a_phone_number_can_only_belong_to_one_patient_per_facility(): void
    {
        $facility = Facility::factory()->create();
        Patient::factory()->for($facility)->create(['phone' => '+254712345678']);

        $this->expectException(UniqueConstraintViolationException::class);

        Patient::factory()->for($facility)->create(['phone' => '+254712345678']);
    }

    public function test_the_same_phone_number_can_be_a_patient_at_two_different_facilities(): void
    {
        Patient::factory()->create(['phone' => '+254712345678']);
        Patient::factory()->create(['phone' => '+254712345678']);

        $this->assertDatabaseCount('patients', 2);
    }
}
