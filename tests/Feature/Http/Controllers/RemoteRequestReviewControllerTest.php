<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\RemoteRequestStatus;
use App\Enums\VisitEventType;
use App\Enums\VisitSource;
use App\Enums\VisitStatus;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\PatientNotification;
use App\Models\QueueCounter;
use App\Models\RemoteRequest;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Services\DoctorRecommender;
use App\Services\QueueNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesSmsGateway;
use Tests\Concerns\SetsUpRemoteQueue;
use Tests\TestCase;

class RemoteRequestReviewControllerTest extends TestCase
{
    use FakesSmsGateway;
    use RefreshDatabase;
    use SetsUpRemoteQueue;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureGateway();
        $this->setUpRemoteQueue();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function fromHome(array $attributes = []): RemoteRequest
    {
        return RemoteRequest::factory()->for($this->facility)->create([
            'name' => 'Kevin Otieno',
            'phone' => '+254712345678',
            'service_id' => $this->general->id,
            'requested_arrival' => '10:30:00',
            ...$attributes,
        ]);
    }

    private function accept(RemoteRequest $request, ?User $doctor = null, ?User $by = null)
    {
        return $this->actingAs($by ?? $this->receptionist)->post(route('remote-requests.accept', $request), $doctor === null ? [] : ['doctor_id' => $doctor->id]);
    }

    public function test_the_list_shows_pending_requests_with_the_two_kinds_told_apart(): void
    {
        $this->fromHome(['name' => 'Kevin Otieno']);
        RemoteRequest::factory()->for($this->facility)->selfCheckin()->create(['name' => 'Susan Achieng', 'service_id' => $this->general->id]);
        $this->fromHome(['name' => 'Already Accepted', 'status' => RemoteRequestStatus::Accepted, 'phone' => '+254700000001']);
        RemoteRequest::factory()->create(['name' => 'Other Facility Person']);

        $this->actingAs($this->receptionist)
            ->get(route('remote-requests.index'))
            ->assertOk()
            ->assertSeeInOrder(['Kevin Otieno', 'General Medicine', 'From home', 'Wants to arrive at 10:30 AM'])
            ->assertSeeInOrder(['Susan Achieng', 'Here now', 'Checked in on their own phone and is in the building'])
            ->assertDontSeeText('Already Accepted')
            ->assertDontSeeText('Other Facility Person');
    }

    public function test_the_list_says_when_nothing_is_waiting(): void
    {
        $this->actingAs($this->admin)->get(route('remote-requests.index'))->assertSeeText('No requests are waiting for review.');
    }

    public function test_the_review_screen_ranks_the_doctors_like_registration_does_and_recommends_the_shortest_line(): void
    {
        Visit::factory()->inLineOf($this->kamau, 1)->count(3)->create();
        $request = $this->fromHome(['requested_arrival' => '10:00:00']);

        $this->actingAs($this->receptionist)
            ->get(route('remote-requests.show', $request))
            ->assertOk()
            ->assertSeeInOrder(['Dr. Wanjiku Mwangi', 'General Medicine', '0 patients waiting', 'Est. no wait', 'Recommended'])
            ->assertSeeInOrder(['Dr. Kamau Njoroge', '3 patients waiting', 'Est. 10:20–10:30'])
            ->assertViewHas('preselected', $this->wanjiku->id);
    }

    public function test_a_doctor_the_requester_asked_for_is_preselected_when_they_are_on_duty(): void
    {
        $request = $this->fromHome(['preferred_doctor_id' => $this->kamau->id]);

        $this->actingAs($this->receptionist)->get(route('remote-requests.show', $request))->assertViewHas('preselected', $this->kamau->id);

        $this->kamau->update(['is_on_duty' => false]);

        $this->actingAs($this->receptionist)->get(route('remote-requests.show', $request))->assertViewHas('preselected', $this->wanjiku->id);
    }

    public function test_accepting_from_home_makes_a_patient_and_a_visit_holding_a_real_place_in_the_doctors_line(): void
    {
        $this->gatewayAccepts();
        $generator = app(QueueNumberGenerator::class);
        $generator->next($this->facility->id, $this->consultation->id, $this->wanjiku->id);
        $generator->next($this->facility->id, $this->consultation->id, $this->wanjiku->id);
        $request = $this->fromHome();

        $this->accept($request, $this->wanjiku)
            ->assertRedirect(route('remote-requests.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Kevin Otieno was accepted with Dr. Wanjiku Mwangi as C-3. They have been texted, and are held a place until they arrive.');

        $patient = Patient::sole();
        $this->assertSame('+254712345678', $patient->phone);
        $this->assertSame('Kevin Otieno', $patient->name);

        $visit = Visit::sole();
        $this->assertSame($patient->id, $visit->patient_id);
        $this->assertSame(VisitSource::Remote, $visit->source);
        $this->assertSame(VisitStatus::AwaitingArrival, $visit->status);
        $this->assertSame($this->consultation->id, $visit->department_id);
        $this->assertSame($this->wanjiku->id, $visit->assigned_doctor_id);
        $this->assertSame(3, $visit->doctor_queue_number, 'The next number in Dr. Wanjiku\'s own sequence, from the same generator walk-ins use.');
        $this->assertSame($this->general->id, $visit->service_id);
        $this->assertSame($this->receptionist->id, $visit->created_by);
        $this->assertNull($visit->arrived_at);
        $this->assertNotNull($visit->tracking_token);
        $this->assertNotNull($visit->access_pin_hash);

        $this->assertSame([VisitEventType::Registered, VisitEventType::DoctorAssigned], $visit->events->pluck('event')->all());
        $this->assertSame(['doctor_id' => $this->wanjiku->id], $visit->events->last()->meta);
        $this->assertSame($this->receptionist->id, $visit->events->last()->user_id);

        $request->refresh();
        $this->assertSame(RemoteRequestStatus::Accepted, $request->status);
        $this->assertSame($visit->id, $request->visit_id);
        $this->assertSame($this->receptionist->id, $request->reviewed_by);
        $this->assertNotNull($request->reviewed_at);
        $this->assertNotNull($request->recommended_arrival_from);
        $this->assertNotNull($request->recommended_arrival_until);
    }

    public function test_the_place_it_takes_counts_in_the_line_the_recommender_shows_the_next_receptionist(): void
    {
        $this->gatewayAccepts();
        $this->accept($this->fromHome(), $this->wanjiku);

        $ranked = app(DoctorRecommender::class)->rank($this->consultation->id, $this->general->id, $this->facility->id);

        $this->assertSame($this->kamau->id, $ranked[0]->doctor->id, 'Dr. Wanjiku now has someone in their line.');
        $this->assertSame(1, collect($ranked)->firstWhere(fn ($option) => $option->doctor->is($this->wanjiku))->activeCount);
    }

    public function test_the_requester_is_texted_the_doctor_the_arrival_window_and_their_link(): void
    {
        $this->gatewayAccepts();
        $request = $this->fromHome(['requested_arrival' => '10:30:00']);

        $this->accept($request, $this->wanjiku);

        $visit = Visit::sole();
        $notification = PatientNotification::sole();

        // An empty line, so they are called about now: they are told to come at the time they said, not before.
        $this->assertSame("Upendo Clinic: You're in Dr. Wanjiku Mwangi's queue (C-1). Please arrive 10:30–10:40 AM. Track: ".$visit->trackingUrl(), $notification->message);
        $this->assertSame($visit->patient_id, $notification->patient_id);
        $this->assertSame($visit->id, $notification->visit_id);
        $this->assertLessThanOrEqual(160, mb_strlen($notification->message));
        Http::assertSent(fn ($sent) => $sent['to'] === '+254712345678');
    }

    public function test_an_existing_patient_is_reused_by_phone_and_not_duplicated(): void
    {
        $this->gatewayAccepts();
        $existing = Patient::factory()->for($this->facility)->create(['phone' => '+254712345678', 'name' => 'K. Otieno', 'dob' => '1990-05-05']);

        $this->accept($this->fromHome(), $this->wanjiku);

        $this->assertSame(1, Patient::count());
        $this->assertSame($existing->id, Visit::sole()->patient_id);
        $this->assertSame('Kevin Otieno', $existing->fresh()->name);
        $this->assertSame('1990-05-05', $existing->fresh()->dob->toDateString(), 'What is on record is never erased.');
    }

    public function test_a_department_that_gives_each_patient_a_doctor_will_not_accept_without_one(): void
    {
        $request = $this->fromHome();

        $this->accept($request)->assertSessionHasErrors(['review' => 'This service gives each patient their own doctor: choose one.']);

        $this->assertNothingWasCreated($request);
    }

    public function test_a_doctor_who_is_off_duty_or_does_not_fit_the_service_is_refused(): void
    {
        $away = User::factory()->for($this->facility)->doctor()->create(['name' => 'Away Doctor', 'department_id' => $this->consultation->id, 'service_id' => $this->general->id]);
        $paediatrics = User::factory()->for($this->facility)->doctor()->onDuty()->create(['name' => 'Child Doctor', 'department_id' => $this->consultation->id]);
        $paediatrics->update(['service_id' => Service::factory()->for($this->consultation)->create(['name' => 'Pediatrics'])->id]);
        $request = $this->fromHome();

        $this->accept($request, $away)->assertSessionHasErrors(['review' => 'Dr. Away Doctor isn\'t on duty for this service right now. Choose another doctor.']);
        $this->accept($request, $paediatrics)->assertSessionHasErrors('review');

        $this->assertNothingWasCreated($request);
    }

    public function test_it_cannot_be_accepted_while_no_doctor_is_on_duty_for_the_service(): void
    {
        User::query()->where('role', 'doctor')->update(['is_on_duty' => false]);
        $request = $this->fromHome();

        $this->accept($request, $this->wanjiku)->assertSessionHasErrors(['review' => 'No doctor is on duty for this service right now, so the request can\'t be accepted yet.']);

        $this->actingAs($this->receptionist)->get(route('remote-requests.show', $request))->assertSeeText('No doctor is on duty for this service right now');
        $this->assertNothingWasCreated($request);
    }

    public function test_a_department_with_one_shared_queue_accepts_without_a_doctor(): void
    {
        $this->gatewayAccepts();
        $this->consultation->update(['requires_doctor_assignment' => false]);
        $request = $this->fromHome();

        $this->accept($request)->assertSessionHasNoErrors();

        $visit = Visit::sole();
        $this->assertNull($visit->assigned_doctor_id);
        $this->assertNull($visit->doctor_queue_number);
        $this->assertSame(VisitStatus::AwaitingArrival, $visit->status);
        $this->assertStringStartsWith("Upendo Clinic: You're in the queue (#{$visit->queue_number}).", PatientNotification::sole()->message);
    }

    public function test_a_request_with_no_service_goes_to_the_first_department_that_assigns_doctors(): void
    {
        $this->gatewayAccepts();
        $request = $this->fromHome(['service_id' => null]);

        $this->accept($request, $this->kamau)->assertSessionHasNoErrors();

        $this->assertSame($this->consultation->id, Visit::sole()->department_id);
    }

    public function test_an_accepted_request_cannot_be_accepted_or_declined_again(): void
    {
        $this->gatewayAccepts();
        $request = $this->fromHome();
        $this->accept($request, $this->wanjiku);

        $this->accept($request, $this->kamau)->assertSessionHasErrors(['review' => 'This request has already been dealt with.']);
        $this->actingAs($this->receptionist)->post(route('remote-requests.decline', $request), ['declined_reason' => 'Too late'])->assertSessionHasErrors('review');
        $this->actingAs($this->receptionist)->get(route('remote-requests.show', $request))->assertRedirect(route('remote-requests.index'));

        $this->assertSame(1, Visit::count());
        $this->assertSame(1, Patient::count());
        $this->assertSame(RemoteRequestStatus::Accepted, $request->fresh()->status);
    }

    public function test_declining_records_the_reason_makes_no_patient_or_visit_and_texts_the_number_given(): void
    {
        $this->gatewayAccepts();
        $request = $this->fromHome();

        $this->actingAs($this->receptionist)
            ->post(route('remote-requests.decline', $request), ['declined_reason' => 'The clinic is closing early today.'])
            ->assertRedirect(route('remote-requests.index'))
            ->assertSessionHas('success', 'Kevin Otieno\'s request was declined.');

        $request->refresh();
        $this->assertSame(RemoteRequestStatus::Declined, $request->status);
        $this->assertSame('The clinic is closing early today.', $request->declined_reason);
        $this->assertSame($this->receptionist->id, $request->reviewed_by);
        $this->assertNull($request->visit_id);
        $this->assertSame(0, Patient::count());
        $this->assertSame(0, Visit::count());

        $notification = PatientNotification::sole();
        $this->assertNull($notification->patient_id);
        $this->assertSame('+254712345678', $notification->phone);
        $this->assertSame("Upendo Clinic: Sorry, we couldn't accept your queue request. The clinic is closing early today. Please visit us or try again later.", $notification->message);
        Http::assertSent(fn ($sent) => $sent['to'] === '+254712345678');
    }

    public function test_a_decline_without_a_reason_still_tells_them_and_says_nothing_about_why(): void
    {
        $this->gatewayAccepts();

        $this->actingAs($this->receptionist)->post(route('remote-requests.decline', $this->fromHome()))->assertSessionHasNoErrors();

        $this->assertSame("Upendo Clinic: Sorry, we couldn't accept your queue request. Please visit us or try again later.", PatientNotification::sole()->message);
    }

    public function test_a_reason_is_kept_short_and_a_long_one_is_trimmed_in_the_text(): void
    {
        $this->gatewayAccepts();
        $request = $this->fromHome();

        $this->actingAs($this->receptionist)->post(route('remote-requests.decline', $request), ['declined_reason' => str_repeat('x', 256)])->assertSessionHasErrors('declined_reason');
        $this->actingAs($this->receptionist)->post(route('remote-requests.decline', $request), ['declined_reason' => str_repeat('word ', 40)])->assertSessionHasNoErrors();

        $this->assertLessThanOrEqual(160, mb_strlen(PatientNotification::sole()->message));
    }

    public function test_a_facility_without_sms_makes_no_message_but_still_decides(): void
    {
        $this->facility->update(['notification_channels' => ['email']]);
        $request = $this->fromHome();

        $this->accept($request, $this->wanjiku)->assertSessionHasNoErrors();

        $this->assertSame(0, PatientNotification::count());
        $this->assertSame(RemoteRequestStatus::Accepted, $request->fresh()->status);
    }

    public function test_a_broken_gateway_never_stops_a_request_being_accepted(): void
    {
        $this->gatewayRejects();
        $request = $this->fromHome();

        $this->accept($request, $this->wanjiku)->assertSessionHasNoErrors();

        $this->assertSame(RemoteRequestStatus::Accepted, $request->fresh()->status);
        $this->assertSame(VisitStatus::AwaitingArrival, Visit::sole()->status);
    }

    public function test_only_reception_and_admins_can_review(): void
    {
        $request = $this->fromHome();

        foreach ([User::factory()->for($this->facility)->doctor()->create(), User::factory()->for($this->facility)->nurse()->create()] as $staff) {
            $this->actingAs($staff)->get(route('remote-requests.index'))->assertForbidden();
            $this->actingAs($staff)->get(route('remote-requests.show', $request))->assertForbidden();
            $this->actingAs($staff)->post(route('remote-requests.accept', $request))->assertForbidden();
            $this->actingAs($staff)->post(route('remote-requests.decline', $request))->assertForbidden();
        }

        $this->assertNothingWasCreated($request);
    }

    public function test_another_facilitys_staff_get_not_found(): void
    {
        $request = $this->fromHome();
        $outsider = User::factory()->for(Facility::factory()->create())->receptionist()->create();

        $this->actingAs($outsider)->get(route('remote-requests.show', $request))->assertNotFound();
        $this->actingAs($outsider)->post(route('remote-requests.accept', $request))->assertNotFound();
        $this->actingAs($outsider)->post(route('remote-requests.decline', $request))->assertNotFound();

        $this->assertNothingWasCreated($request);
    }

    public function test_a_guest_is_sent_to_log_in(): void
    {
        $this->get(route('remote-requests.index'))->assertRedirect(route('login'));
    }

    public function test_the_front_desk_and_admin_have_the_screen_in_their_menu_and_a_queue_banner_counts_what_is_waiting(): void
    {
        $this->fromHome();
        $this->fromHome(['phone' => '+254700000002']);

        foreach ([$this->receptionist, $this->admin] as $staff) {
            $this->actingAs($staff)->get(route('queue.index'))->assertSeeText('Remote requests')->assertSeeText('queue requests are waiting for review');
        }
    }

    private function assertNothingWasCreated(RemoteRequest $request): void
    {
        $this->assertSame(RemoteRequestStatus::Pending, $request->fresh()->status);
        $this->assertSame(0, Patient::count());
        $this->assertSame(0, Visit::count());
        $this->assertSame(0, QueueCounter::count(), 'Not even a queue number is used up by a refusal.');
    }
}
