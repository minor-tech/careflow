<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\DepartmentType;
use App\Enums\VisitEventType;
use App\Enums\VisitStatus;
use App\Models\Department;
use App\Models\Facility;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QueueResetPinControllerTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_PIN = '7394';

    private Facility $facility;

    private Department $reception;

    private Department $consultation;

    private User $receptionist;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facility = Facility::factory()->create(['slug' => 'upendo']);
        $this->reception = Department::factory()->for($this->facility)->create(['name' => 'Reception', 'type' => DepartmentType::Reception]);
        $this->consultation = Department::factory()->for($this->facility)->create(['name' => 'Consultation', 'type' => DepartmentType::Consultation]);
        $this->receptionist = User::factory()->for($this->facility)->receptionist()->create(['department_id' => $this->reception->id]);
        $this->admin = User::factory()->for($this->facility)->create();
    }

    private function visit(?Department $department, VisitStatus $status = VisitStatus::Waiting): Visit
    {
        return Visit::factory()->for($this->facility)->withAccessPin(self::OLD_PIN)->create([
            'department_id' => $department?->id,
            'queue_number' => 27,
            'status' => $status,
        ]);
    }

    /**
     * The PIN staff were just shown, read back from the flashed session.
     */
    private function flashedPin(): string
    {
        return Crypt::decryptString(session('reset_pin.pin'));
    }

    private function typeIn(string $pin)
    {
        return $this->post(route('tracking.entry.attempt', 'upendo'), ['queue_code' => 'V027', 'pin' => $pin]);
    }

    public function test_reception_resets_a_visit_in_their_own_department_and_the_new_pin_replaces_the_old(): void
    {
        $visit = $this->visit($this->reception);

        $this->actingAs($this->receptionist)
            ->post(route('queue.reset-pin', $visit))
            ->assertRedirect(route('queue.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('reset_pin.code', 'V027');

        $newPin = $this->flashedPin();

        $this->assertMatchesRegularExpression('/^\d{4}$/', $newPin);
        $this->assertTrue(Hash::check($newPin, $visit->fresh()->access_pin_hash));
    }

    public function test_an_admin_resets_a_visit_in_any_department_and_lands_back_on_that_departments_tab(): void
    {
        $visit = $this->visit($this->consultation);

        $this->actingAs($this->admin)
            ->post(route('queue.reset-pin', $visit))
            ->assertRedirect(route('queue.index', ['department' => $this->consultation->id]))
            ->assertSessionHas('reset_pin');

        $this->assertTrue(Hash::check($this->flashedPin(), $visit->fresh()->access_pin_hash));
    }

    public function test_the_old_pin_is_rejected_the_moment_the_reset_happens_and_the_new_one_opens_the_visit(): void
    {
        $visit = $this->visit($this->reception);

        $this->typeIn(self::OLD_PIN)->assertRedirect(route('tracking.show', $visit->tracking_token));

        $this->actingAs($this->receptionist)->post(route('queue.reset-pin', $visit));
        $newPin = $this->flashedPin();

        // One draw in ten thousand gives the same PIN again, which is a different (and legal) outcome.
        $this->assertNotSame(self::OLD_PIN, $newPin, 'The same PIN was drawn twice: run the test again.');

        $this->typeIn(self::OLD_PIN)
            ->assertRedirect(route('tracking.entry', 'upendo'))
            ->assertSessionHasErrors(['pin' => 'Queue code or PIN not recognized.']);
        $this->typeIn($newPin)->assertRedirect(route('tracking.show', $visit->tracking_token));
    }

    public function test_a_visit_locked_out_by_wrong_guesses_takes_the_new_pin_straight_away(): void
    {
        $visit = $this->visit($this->reception);

        foreach (range(1, 20) as $miss) {
            RateLimiter::hit($visit->accessPinMissesKey(), 3600);
        }

        $this->typeIn(self::OLD_PIN)->assertSessionHasErrors('pin');

        $this->actingAs($this->receptionist)->post(route('queue.reset-pin', $visit));

        $this->typeIn($this->flashedPin())->assertRedirect(route('tracking.show', $visit->tracking_token));
    }

    public function test_every_reset_is_logged_with_who_did_it_and_where_the_visit_was(): void
    {
        $visit = $this->visit($this->consultation);

        $this->actingAs($this->admin)->post(route('queue.reset-pin', $visit));
        $this->actingAs($this->receptionist)->post(route('queue.reset-pin', $visit))->assertForbidden();
        $this->actingAs($this->admin)->post(route('queue.reset-pin', $visit));

        $log = $visit->events;

        $this->assertCount(2, $log);
        $this->assertSame(VisitEventType::PinReset, $log->last()->event);
        $this->assertSame('pin_reset', $log->last()->getRawOriginal('event'));
        $this->assertSame($this->admin->id, $log->last()->user_id);
        $this->assertSame($this->consultation->id, $log->last()->department_id);
        $this->assertNotNull($log->last()->created_at);
    }

    public function test_a_reset_does_not_change_where_the_patient_is_in_the_queue(): void
    {
        $visit = $this->visit($this->reception, VisitStatus::Called);

        $this->actingAs($this->receptionist)->post(route('queue.reset-pin', $visit));

        $this->assertSame(VisitStatus::Called, $visit->fresh()->status);
    }

    public function test_the_new_pin_is_shown_once_on_the_queue_and_not_kept_by_the_browser(): void
    {
        $visit = $this->visit($this->reception);

        $this->actingAs($this->receptionist)->post(route('queue.reset-pin', $visit));
        $newPin = $this->flashedPin();

        $this->get(route('queue.index'))
            ->assertOk()
            ->assertSee('New PIN for V027', false)
            ->assertSeeText($newPin)
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->get(route('queue.index'))
            ->assertOk()
            ->assertDontSee('New PIN for V027', false)
            ->assertDontSee('id="new-access-pin"', false);
    }

    public function test_the_new_pin_is_never_stored_readable_in_the_database_or_the_session(): void
    {
        $visit = $this->visit($this->reception);

        $this->actingAs($this->receptionist)->post(route('queue.reset-pin', $visit));
        $newPin = $this->flashedPin();

        $this->assertNotSame($newPin, session('reset_pin.pin'), 'The session holds it encrypted.');
        $this->assertStringNotContainsString($newPin, json_encode($visit->fresh()->getAttributes()));
        $this->assertStringNotContainsString($newPin, json_encode($visit->events()->get()->toArray()));
    }

    /**
     * @return array<string, array{VisitStatus}>
     */
    public static function endedVisits(): array
    {
        return [
            'completed' => [VisitStatus::Completed],
            'cancelled' => [VisitStatus::Cancelled],
        ];
    }

    #[DataProvider('endedVisits')]
    public function test_a_visit_that_has_ended_is_refused_with_a_message_and_nothing_changes(VisitStatus $status): void
    {
        $visit = $this->visit($this->reception, $status);
        $hashBefore = $visit->access_pin_hash;

        $this->actingAs($this->receptionist)
            ->post(route('queue.reset-pin', $visit))
            ->assertRedirect(route('queue.index'))
            ->assertSessionHasErrors(['queue' => 'That visit has ended, so its PIN no longer opens anything.'])
            ->assertSessionMissing('reset_pin');

        $this->assertSame($hashBefore, $visit->fresh()->access_pin_hash);
        $this->assertCount(0, $visit->events);
    }

    public function test_a_doctor_cannot_reset_a_pin_even_in_their_own_department(): void
    {
        $visit = $this->visit($this->consultation);
        $doctor = User::factory()->for($this->facility)->doctor()->create(['department_id' => $this->consultation->id]);

        $this->actingAs($doctor)->post(route('queue.reset-pin', $visit))->assertForbidden();

        $this->assertTrue(Hash::check(self::OLD_PIN, $visit->fresh()->access_pin_hash));
        $this->assertCount(0, $visit->events);
    }

    public function test_another_facilitys_admin_gets_not_found_and_nothing_changes(): void
    {
        $visit = $this->visit($this->reception);
        $outsider = User::factory()->for(Facility::factory()->create())->create();

        $this->actingAs($outsider)->post(route('queue.reset-pin', $visit))->assertNotFound();

        $this->assertTrue(Hash::check(self::OLD_PIN, $visit->fresh()->access_pin_hash));
        $this->assertCount(0, $visit->events);
    }

    public function test_a_guest_is_sent_to_log_in(): void
    {
        $visit = $this->visit($this->reception);

        $this->post(route('queue.reset-pin', $visit))->assertRedirect(route('login'));

        $this->assertCount(0, $visit->events);
    }

    public function test_reset_pin_is_offered_to_reception_and_admins_but_not_to_doctors_or_nurses(): void
    {
        $this->visit($this->consultation);
        $doctor = User::factory()->for($this->facility)->doctor()->create(['department_id' => $this->consultation->id]);
        $nurse = User::factory()->for($this->facility)->nurse()->create(['department_id' => $this->consultation->id]);
        $consultationReceptionist = User::factory()->for($this->facility)->receptionist()->create(['department_id' => $this->consultation->id]);

        $this->actingAs($this->admin)->get(route('queue.index', ['department' => $this->consultation->id]))->assertSeeText('Reset PIN');
        $this->actingAs($consultationReceptionist)->get(route('queue.index'))->assertSeeText('Reset PIN');
        $this->actingAs($doctor)->get(route('queue.index'))->assertOk()->assertDontSeeText('Reset PIN');
        $this->actingAs($nurse)->get(route('queue.index'))->assertOk()->assertDontSeeText('Reset PIN');
    }
}
