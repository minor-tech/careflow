<?php

namespace Tests\Feature\Services;

use App\Enums\DepartmentType;
use App\Models\Department;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\Visit;
use App\Services\MessageTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessageTemplatesTest extends TestCase
{
    use RefreshDatabase;

    private function visit(array $visit = [], string $patientName = 'Wanjiru Kamau', string $facilityName = 'Upendo Clinic', string $department = 'Consultation', DepartmentType $type = DepartmentType::Consultation): Visit
    {
        $facility = Facility::factory()->create(['name' => $facilityName]);
        $department = Department::factory()->for($facility)->create(['name' => $department, 'type' => $type]);

        return Visit::factory()->for($facility)->create([
            'patient_id' => Patient::factory()->for($facility)->create(['name' => $patientName])->id,
            'department_id' => $department->id,
            'queue_number' => 27,
            ...$visit,
        ])->load(['patient', 'facility', 'department']);
    }

    public function test_registration_gives_the_queue_number_and_the_visits_own_tracking_link(): void
    {
        $visit = $this->visit();

        $this->assertSame(
            "Upendo Clinic: Hi Wanjiru, your queue number is 27. Track your visit: {$visit->trackingUrl()}",
            app(MessageTemplates::class)->registered($visit),
        );
        $this->assertStringContainsString("/t/{$visit->tracking_token}", app(MessageTemplates::class)->registered($visit));
    }

    public function test_a_visit_with_no_tracking_token_is_told_its_number_without_a_dead_link(): void
    {
        $visit = $this->visit();
        $visit->tracking_token = null;

        $message = app(MessageTemplates::class)->registered($visit);

        $this->assertSame('Upendo Clinic: Hi Wanjiru, your queue number is 27.', $message);
        $this->assertStringNotContainsString('http', $message);
    }

    public function test_it_greets_by_first_name_only(): void
    {
        $this->assertStringContainsString('Hi Wanjiru,', app(MessageTemplates::class)->registered($this->visit(patientName: 'Wanjiru Njeri Kamau')));
        $this->assertStringContainsString('Hi Otieno,', app(MessageTemplates::class)->registered($this->visit(patientName: '  Otieno  ')));
        $this->assertStringContainsString('Hi Amina,', app(MessageTemplates::class)->registered($this->visit(patientName: 'Amina')));
    }

    public function test_called_sends_them_to_their_current_department(): void
    {
        $this->assertSame(
            "Upendo Clinic: It's your turn - please proceed to Consultation.",
            app(MessageTemplates::class)->called($this->visit()),
        );
    }

    public function test_almost_turn_names_the_department(): void
    {
        $this->assertSame(
            "Upendo Clinic: You're almost up in Consultation. Please be ready.",
            app(MessageTemplates::class)->almostTurn($this->visit()),
        );
    }

    public function test_transferred_gives_the_new_department_and_the_number_there(): void
    {
        $visit = $this->visit(['department_queue_number' => 8], department: 'Laboratory', type: DepartmentType::Laboratory);

        $this->assertSame(
            "Upendo Clinic: You've been moved to Laboratory. Your number: L-8.",
            app(MessageTemplates::class)->transferred($visit),
        );
    }

    public function test_completed_thanks_them_in_the_facilitys_name(): void
    {
        $this->assertSame(
            'Upendo Clinic: Your visit is complete. Thank you for choosing Upendo Clinic.',
            app(MessageTemplates::class)->completed($this->visit()),
        );
    }

    public function test_a_visit_with_no_department_still_reads_sensibly(): void
    {
        $visit = $this->visit();
        $visit->department_id = null;
        $visit->unsetRelation('department');

        $this->assertStringContainsString('proceed to the service desk', app(MessageTemplates::class)->called($visit));
    }

    public function test_no_message_is_left_with_an_unfilled_placeholder(): void
    {
        $templates = app(MessageTemplates::class);
        $visit = $this->visit(['department_queue_number' => 3]);

        foreach (['registered', 'called', 'almostTurn', 'transferred', 'completed'] as $message) {
            $this->assertDoesNotMatchRegularExpression('/[{}]/', $templates->{$message}($visit), "The {$message} message has a placeholder left in it.");
        }
    }

    public function test_the_wording_fits_in_one_sms_segment_for_ordinary_names(): void
    {
        $templates = app(MessageTemplates::class);
        $visit = $this->visit(patientName: 'Wanjiru Kamau', facilityName: 'Upendo Health Centre', department: 'Laboratory', type: DepartmentType::Laboratory);
        $visit->department_queue_number = 12;

        foreach (['registered', 'called', 'almostTurn', 'transferred', 'completed'] as $message) {
            $this->assertLessThanOrEqual(160, strlen($templates->{$message}($visit)), "The {$message} message is longer than one SMS segment.");
        }
    }

    public function test_the_registration_text_with_a_tunnelled_link_still_fits_in_one_sms_segment(): void
    {
        config(['app.url' => 'https://a1b2-102-215-77-14.ngrok-free.app']);
        $visit = $this->visit(patientName: 'Wanjiru Kamau', facilityName: 'Upendo Health Centre');

        $this->assertLessThanOrEqual(160, strlen(app(MessageTemplates::class)->registered($visit)));
    }

    public function test_the_wording_lives_in_config_so_it_can_be_changed_in_one_place(): void
    {
        config(['notification_templates.called' => '{facility} says go to {department} now']);

        $this->assertSame('Upendo Clinic says go to Consultation now', app(MessageTemplates::class)->called($this->visit()));
    }
}
