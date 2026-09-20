<?php

namespace Tests\Feature\Http\Controllers;

use App\Actions\SubmitRemoteRequest;
use App\Enums\RemoteRequestSource;
use App\Enums\RemoteRequestStatus;
use App\Enums\VisitEventType;
use App\Enums\VisitSource;
use App\Enums\VisitStatus;
use App\Models\Facility;
use App\Models\PatientNotification;
use App\Models\RemoteRequest;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\FakesSmsGateway;
use Tests\Concerns\SetsUpRemoteQueue;
use Tests\TestCase;

class SelfCheckinTest extends TestCase
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
     * @param  array<string, mixed>  $overrides
     */
    private function checkIn(array $overrides = [])
    {
        return $this->post(route('checkin.store', 'upendo'), [
            'name' => 'Susan Achieng',
            'phone' => '0722 111 222',
            'service_id' => $this->general->id,
            ...$overrides,
        ]);
    }

    public function test_the_form_is_on_the_facilitys_own_address_and_is_shorter_than_the_one_for_home(): void
    {
        $this->get('/upendo/checkin')
            ->assertOk()
            ->assertSeeText('Upendo Clinic')
            ->assertSeeText('Check yourself in')
            ->assertSee('name="name"', false)
            ->assertSee('name="phone"', false)
            ->assertSee('name="service_id"', false)
            // They are already here, so nobody asks when they can arrive.
            ->assertDontSee('name="requested_arrival"', false)
            ->assertDontSeeText('When can you arrive');

        $this->get(route('directory.show', 'upendo'))->assertSee('name="requested_arrival"', false);
    }

    public function test_the_service_choice_follows_the_facilitys_setting(): void
    {
        $this->facility->update(['remote_queue_allow_service_choice' => false]);

        $this->get('/upendo/checkin')->assertOk()->assertDontSee('name="service_id"', false);
    }

    public function test_a_facility_that_has_not_switched_it_on_sends_people_to_the_front_desk(): void
    {
        $this->facility->update(['self_checkin_enabled' => false]);

        $this->get('/upendo/checkin')->assertOk()->assertSeeText('Please go to the front desk')->assertDontSee('name="phone"', false);
        $this->checkIn()->assertSessionHasErrors(['request' => 'This facility is not taking queue requests online.']);

        $this->assertSame(0, RemoteRequest::count());
    }

    public function test_a_facility_that_is_not_live_has_no_check_in_page(): void
    {
        $this->facility->update(['status' => 'suspended']);

        $this->get('/upendo/checkin')->assertNotFound();
        $this->checkIn()->assertNotFound();
    }

    public function test_submitting_makes_a_pending_request_marked_as_a_self_check_in_for_right_now(): void
    {
        $response = $this->checkIn();

        $request = RemoteRequest::sole();
        $response->assertRedirect(route('remote.status', $request->public_code));

        $this->assertSame(RemoteRequestSource::SelfCheckin, $request->source);
        $this->assertSame(RemoteRequestStatus::Pending, $request->status);
        $this->assertSame('10:00:00', $request->requested_arrival, 'They are here now, not asking for a future time.');
        $this->assertSame('+254722111222', $request->phone);
        $this->assertSame($this->general->id, $request->service_id);
        $this->assertSame(0, Visit::count());

        $this->get(route('remote.status', $request->public_code))
            ->assertOk()
            ->assertSeeText('Check-in received')
            ->assertSeeText('The front desk has your details');
    }

    public function test_it_has_no_cutoff_and_does_not_use_up_the_places_for_requests_from_home(): void
    {
        $this->facility->update(['remote_queue_max_pending' => 1, 'remote_queue_accept_until' => '09:00', 'remote_queue_enabled' => true]);

        // Past the cutoff and with the remote limit reached, someone standing in the building can still check in.
        $this->checkIn()->assertSessionHasNoErrors();

        $this->assertSame(0, $this->facility->fresh()->pendingRemoteRequestsToday(), 'A self check-in is not a request from home.');
    }

    public function test_it_works_for_a_facility_that_takes_no_requests_from_home(): void
    {
        $this->facility->update(['remote_queue_enabled' => false]);

        $this->checkIn()->assertSessionHasNoErrors();

        $this->assertSame(1, RemoteRequest::count());
    }

    public function test_a_script_cannot_flood_the_review_list(): void
    {
        RemoteRequest::factory()->for($this->facility)->selfCheckin()->count(SubmitRemoteRequest::MAX_PENDING_SELF_CHECKINS)->create();

        $this->checkIn()->assertSessionHasErrors(['request' => 'Too many people are waiting to be checked in. Please go to the front desk.']);

        $this->assertSame(SubmitRemoteRequest::MAX_PENDING_SELF_CHECKINS, RemoteRequest::count());
    }

    public function test_the_same_phone_number_cannot_be_waiting_twice(): void
    {
        $this->checkIn()->assertSessionHasNoErrors();

        $this->checkIn(['name' => 'Someone Else'])->assertSessionHasErrors('request');

        $this->assertSame(1, RemoteRequest::count());
    }

    public function test_name_and_phone_are_needed_and_a_bot_is_ignored(): void
    {
        $this->checkIn(['name' => '', 'phone' => ''])->assertSessionHasErrors(['name', 'phone']);
        $this->checkIn(['website' => 'http://spam.example'])->assertRedirect(route('checkin.form', 'upendo'));

        $this->assertSame(0, RemoteRequest::count());
    }

    public function test_the_form_is_throttled_per_address(): void
    {
        RateLimiter::clear('remote-request');

        foreach (range(1, 20) as $attempt) {
            $this->checkIn(['phone' => '07'.sprintf('%08d', $attempt)]);
        }

        $this->checkIn(['phone' => '0799 999 999'])->assertStatus(429);
    }

    public function test_staff_see_it_marked_apart_from_a_request_from_home(): void
    {
        $this->checkIn();
        RemoteRequest::factory()->for($this->facility)->create(['name' => 'Kevin Otieno', 'phone' => '+254733000111']);

        $this->actingAs($this->receptionist)
            ->get(route('remote-requests.index'))
            ->assertSeeInOrder(['Susan Achieng', 'Here now', 'Checked in on their own phone and is in the building'])
            ->assertSeeInOrder(['Kevin Otieno', 'From home', 'Wants to arrive at 10:30 AM']);

        $this->actingAs($this->receptionist)
            ->get(route('remote-requests.show', RemoteRequest::where('name', 'Susan Achieng')->first()))
            ->assertSeeText('Checked in on their own phone: look up and you can see them.')
            ->assertSeeText('They go straight into the queue as waiting')
            // Nobody who is standing there needs an estimated time to arrive.
            ->assertDontSeeText('Est.');
    }

    public function test_accepting_one_puts_them_in_the_queue_as_waiting_at_once_never_awaiting_arrival(): void
    {
        $this->gatewayAccepts();
        $this->checkIn();
        $request = RemoteRequest::sole();

        $this->actingAs($this->receptionist)
            ->post(route('remote-requests.accept', $request), ['doctor_id' => $this->wanjiku->id])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Susan Achieng is checked in and waiting with Dr. Wanjiku Mwangi as C-1.');

        $visit = Visit::sole();
        $this->assertSame(VisitStatus::Waiting, $visit->status);
        $this->assertSame(VisitSource::SelfCheckin, $visit->source);
        $this->assertNotNull($visit->arrived_at);
        $this->assertTrue($visit->arrived_at->isSameMinute(now()));
        $this->assertSame($this->wanjiku->id, $visit->assigned_doctor_id);
        $this->assertSame(1, $visit->doctor_queue_number);

        $this->assertSame([VisitEventType::Registered, VisitEventType::DoctorAssigned, VisitEventType::CheckedIn], $visit->events->pluck('event')->all());

        $request->refresh();
        $this->assertSame(RemoteRequestStatus::Accepted, $request->status);
        $this->assertSame($visit->id, $request->visit_id);
        $this->assertNull($request->recommended_arrival_from, 'There is no arrival window for someone who is here.');
    }

    public function test_they_get_the_ordinary_registration_text_with_their_link_not_a_time_to_arrive(): void
    {
        $this->gatewayAccepts();
        $this->checkIn();

        $this->actingAs($this->receptionist)->post(route('remote-requests.accept', RemoteRequest::sole()), ['doctor_id' => $this->wanjiku->id]);

        $visit = Visit::sole();
        $this->assertSame(
            "Upendo Clinic: Hi Susan, your queue number is {$visit->queue_number}. Track your visit: ".$visit->trackingUrl(),
            PatientNotification::sole()->message,
        );
    }

    public function test_the_visit_behaves_like_a_walk_in_from_then_on(): void
    {
        $this->gatewayAccepts();
        $this->checkIn();
        $this->actingAs($this->receptionist)->post(route('remote-requests.accept', RemoteRequest::sole()), ['doctor_id' => $this->wanjiku->id]);
        $visit = Visit::sole();

        $this->actingAs($this->wanjiku)->post(route('queue.call', $visit))->assertSessionHasNoErrors();
        $this->actingAs($this->wanjiku)->post(route('queue.start', $visit))->assertSessionHasNoErrors();
        $this->actingAs($this->wanjiku)->post(route('queue.complete', $visit))->assertSessionHasNoErrors();

        $this->assertSame(VisitStatus::Completed, $visit->fresh()->status);
        $this->get(route('tracking.show', $visit->tracking_token))->assertOk()->assertDontSeeText('I\'ve arrived');
    }

    public function test_a_department_that_gives_each_patient_a_doctor_still_needs_one_chosen(): void
    {
        $this->checkIn();

        $this->actingAs($this->receptionist)
            ->post(route('remote-requests.accept', RemoteRequest::sole()))
            ->assertSessionHasErrors(['review' => 'This service gives each patient their own doctor: choose one.']);

        $this->assertSame(0, Visit::count());
    }

    public function test_declining_one_works_like_declining_any_request(): void
    {
        $this->gatewayAccepts();
        $this->checkIn();

        $this->actingAs($this->receptionist)->post(route('remote-requests.decline', RemoteRequest::sole()), ['declined_reason' => 'Please see the desk.'])->assertSessionHasNoErrors();

        $this->assertSame(RemoteRequestStatus::Declined, RemoteRequest::sole()->status);
        $this->assertSame(0, Visit::count());
        $this->assertStringContainsString('Please see the desk.', PatientNotification::sole()->message);
    }

    public function test_the_from_home_flow_is_untouched_and_the_two_paths_coexist(): void
    {
        $this->gatewayAccepts();
        $this->post(route('remote.request.store', 'upendo'), ['name' => 'Kevin Otieno', 'phone' => '0712 345 678', 'requested_arrival' => '10:30', 'service_id' => $this->general->id]);
        $this->checkIn();

        $home = RemoteRequest::where('source', RemoteRequestSource::Remote)->sole();
        $here = RemoteRequest::where('source', RemoteRequestSource::SelfCheckin)->sole();
        $this->actingAs($this->receptionist)->post(route('remote-requests.accept', $home), ['doctor_id' => $this->wanjiku->id]);
        $this->actingAs($this->receptionist)->post(route('remote-requests.accept', $here), ['doctor_id' => $this->wanjiku->id]);

        $fromHome = Visit::where('source', VisitSource::Remote)->sole();
        $fromDesk = Visit::where('source', VisitSource::SelfCheckin)->sole();

        $this->assertSame(VisitStatus::AwaitingArrival, $fromHome->status, 'From home still waits for the two-step arrival.');
        $this->assertSame(VisitStatus::Waiting, $fromDesk->status);
        $this->assertSame([1, 2], [$fromHome->doctor_queue_number, $fromDesk->doctor_queue_number], 'One shared line, one shared sequence.');
        $this->assertSame(2, Facility::find($this->facility->id)->visits()->count());
    }

    public function test_a_settings_page_for_the_admin_shows_a_qr_code_and_address_once_it_is_on(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.settings'))
            ->assertOk()
            ->assertSee('data:image/png;base64', false)
            // The address on the printed code is built on APP_URL, because it goes up on a wall, not on whatever host staff opened the page at.
            ->assertSee($this->facility->selfCheckinUrl(), false)
            ->assertSeeText('Print this and put it at the entrance.');

        $this->facility->update(['self_checkin_enabled' => false]);
        $this->admin->unsetRelation('facility'); // A real request loads the facility afresh; this test reuses the same user object.

        $this->actingAs($this->admin)->get(route('admin.settings'))->assertDontSee('data:image/png;base64', false);
    }
}
