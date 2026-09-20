<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\RemoteRequestSource;
use App\Enums\RemoteRequestStatus;
use App\Enums\VisitStatus;
use App\Models\Department;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\RemoteRequest;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Support\TrackingToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\SetsUpRemoteQueue;
use Tests\TestCase;

class RemoteRequestControllerTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpRemoteQueue;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpRemoteQueue();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function requestAPlace(array $overrides = [], string $slug = 'upendo')
    {
        return $this->post(route('remote.request.store', $slug), [
            'name' => 'Kevin Otieno',
            'phone' => '0712 345 678',
            'requested_arrival' => '10:30',
            'service_id' => $this->general->id,
            ...$overrides,
        ]);
    }

    public function test_a_valid_request_is_recorded_pending_and_the_requester_lands_on_their_own_status_page(): void
    {
        $response = $this->requestAPlace();

        $request = RemoteRequest::sole();
        $response->assertRedirect(route('remote.status', $request->public_code));

        $this->assertSame($this->facility->id, $request->facility_id);
        $this->assertSame('Kevin Otieno', $request->name);
        $this->assertSame('+254712345678', $request->phone, 'The number is stored the one canonical way.');
        $this->assertSame($this->general->id, $request->service_id);
        $this->assertSame('10:30:00', $request->requested_arrival);
        $this->assertSame(RemoteRequestStatus::Pending, $request->status);
        $this->assertSame(RemoteRequestSource::Remote, $request->source);

        // Nothing but a request: no patient and no visit until staff accept it.
        $this->assertSame(0, Patient::count());
        $this->assertSame(0, Visit::count());

        $this->get(route('remote.status', $request->public_code))
            ->assertOk()
            ->assertSeeText('Request received')
            ->assertSeeText('Waiting for the facility to review it')
            ->assertSeeText('10:30 AM')
            ->assertHeader('Refresh', '15')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_the_secret_in_the_link_is_random_like_a_tracking_token_and_not_derived_from_anything(): void
    {
        $this->requestAPlace();
        $this->requestAPlace(['phone' => '0722 000 111']);

        $codes = RemoteRequest::orderBy('id')->pluck('public_code', 'id');

        $this->assertCount(2, $codes->unique());

        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^'.TrackingToken::PATTERN.'$/', $code);
            $this->assertStringNotContainsString('712345678', $code);
        }
    }

    public function test_it_is_refused_once_the_limit_of_waiting_requests_is_reached_with_a_clear_message(): void
    {
        $this->facility->update(['remote_queue_max_pending' => 2]);
        RemoteRequest::factory()->for($this->facility)->count(2)->create();

        $this->requestAPlace(['phone' => '0733 111 222'])
            ->assertSessionHasErrors(['request' => 'The remote queue is full right now.']);

        $this->assertSame(2, RemoteRequest::count(), 'Not queued past the limit, not even quietly.');
    }

    public function test_deciding_a_request_frees_a_place_for_the_next_one(): void
    {
        $this->facility->update(['remote_queue_max_pending' => 1]);
        $first = RemoteRequest::factory()->for($this->facility)->create();

        $this->requestAPlace(['phone' => '0733 111 222'])->assertSessionHasErrors('request');

        $first->update(['status' => RemoteRequestStatus::Declined]);

        $this->requestAPlace(['phone' => '0733 111 222'])->assertSessionHasNoErrors();
    }

    public function test_it_is_refused_after_the_daily_cutoff_with_a_clear_message(): void
    {
        $this->facility->update(['remote_queue_accept_until' => '10:00']);
        $this->travelTo(now(config('careflow.timezone'))->setTime(10, 1));

        $this->requestAPlace(['requested_arrival' => '11:00'])
            ->assertSessionHasErrors(['request' => 'Remote queue requests have closed for today.']);

        $this->assertSame(0, RemoteRequest::count());
    }

    public function test_a_facility_that_has_not_switched_it_on_takes_no_requests(): void
    {
        $this->facility->update(['remote_queue_enabled' => false]);

        $this->requestAPlace()->assertSessionHasErrors(['request' => 'This facility is not taking queue requests online.']);

        $this->assertSame(0, RemoteRequest::count());
    }

    public function test_a_facility_that_is_not_live_takes_none_either(): void
    {
        $this->facility->update(['status' => 'suspended']);

        $this->requestAPlace()->assertNotFound();
    }

    public function test_a_phone_number_with_a_request_already_waiting_is_refused_without_revealing_it(): void
    {
        $this->requestAPlace()->assertSessionHasNoErrors();

        $second = $this->requestAPlace(['name' => 'Someone Else']);

        $second->assertSessionHasErrors('request');
        $this->assertStringNotContainsString('Kevin', session('errors')->first('request'));
        $this->assertSame(1, RemoteRequest::count());
    }

    public function test_a_phone_number_that_already_has_a_place_in_todays_queue_is_refused(): void
    {
        $patient = Patient::factory()->for($this->facility)->create(['phone' => '+254712345678']);
        Visit::factory()->for($this->facility)->create(['patient_id' => $patient->id, 'status' => VisitStatus::Waiting]);

        $this->requestAPlace()->assertSessionHasErrors(['request' => 'This phone number already has a place in today\'s queue at this facility.']);

        // Once that visit is over, they can ask again.
        Visit::query()->update(['status' => VisitStatus::Completed]);
        $this->requestAPlace()->assertSessionHasNoErrors();
    }

    public function test_the_arrival_time_must_be_given_and_not_already_past(): void
    {
        $this->requestAPlace(['requested_arrival' => ''])->assertSessionHasErrors(['requested_arrival' => 'Tell us when you can arrive.']);
        $this->requestAPlace(['requested_arrival' => 'later'])->assertSessionHasErrors('requested_arrival');
        $this->requestAPlace(['requested_arrival' => '09:59'])->assertSessionHasErrors(['requested_arrival' => 'Choose a time from now on: that time has already passed today.']);
        $this->requestAPlace(['requested_arrival' => '10:00'])->assertSessionHasNoErrors();
    }

    public function test_name_and_phone_are_required_and_the_phone_must_be_real(): void
    {
        $this->requestAPlace(['name' => '', 'phone' => ''])->assertSessionHasErrors(['name' => 'Enter your name.', 'phone' => 'Enter your phone number.']);
        $this->requestAPlace(['phone' => '12345'])->assertSessionHasErrors(['phone' => 'Enter a valid phone number, for example 0712 345 678.']);
        $this->requestAPlace(['name' => str_repeat('x', 121)])->assertSessionHasErrors('name');

        $this->assertSame(0, RemoteRequest::count());
    }

    public function test_a_service_must_belong_to_this_facility(): void
    {
        $theirs = Service::factory()->for(Department::factory()->for(Facility::factory()->create())->create())->create();

        $this->requestAPlace(['service_id' => $theirs->id])->assertSessionHasErrors(['service_id' => 'Choose one of the services listed.']);

        $this->assertSame(0, RemoteRequest::count());
    }

    public function test_what_the_facility_did_not_offer_is_ignored(): void
    {
        $this->facility->update(['remote_queue_allow_service_choice' => false, 'remote_queue_allow_doctor_choice' => false]);

        $this->requestAPlace(['service_id' => $this->general->id, 'preferred_doctor_id' => $this->wanjiku->id])->assertSessionHasNoErrors();

        $request = RemoteRequest::sole();
        $this->assertNull($request->service_id);
        $this->assertNull($request->preferred_doctor_id);
    }

    public function test_a_doctor_can_be_asked_for_where_the_facility_allows_it_but_only_one_on_duty(): void
    {
        $this->facility->update(['remote_queue_allow_doctor_choice' => true]);
        $away = User::factory()->for($this->facility)->doctor()->create(['department_id' => $this->consultation->id]);

        $this->requestAPlace(['preferred_doctor_id' => $away->id])->assertSessionHasErrors('preferred_doctor_id');
        $this->requestAPlace(['preferred_doctor_id' => $this->kamau->id])->assertSessionHasNoErrors();

        $this->assertSame($this->kamau->id, RemoteRequest::sole()->preferred_doctor_id);
    }

    public function test_a_bot_that_fills_the_hidden_field_is_thanked_and_nothing_is_kept(): void
    {
        $this->requestAPlace(['website' => 'http://spam.example'])->assertRedirect(route('directory.show', 'upendo'));

        $this->assertSame(0, RemoteRequest::count());
    }

    public function test_the_form_is_throttled_per_address(): void
    {
        RateLimiter::clear('remote-request');

        foreach (range(1, 20) as $attempt) {
            $this->requestAPlace(['phone' => '07'.sprintf('%08d', $attempt)]);
        }

        $this->requestAPlace(['phone' => '0799 999 999'])->assertStatus(429);
    }

    public function test_the_requester_can_withdraw_a_request_nobody_has_looked_at(): void
    {
        $this->requestAPlace();
        $request = RemoteRequest::sole();

        $this->post(route('remote.cancel', $request->public_code))->assertRedirect(route('remote.status', $request->public_code));

        $this->assertSame(RemoteRequestStatus::Cancelled, $request->fresh()->status);
        $this->get(route('remote.status', $request->public_code))->assertSeeText('You cancelled this request.');
    }

    public function test_a_decided_request_cannot_be_withdrawn(): void
    {
        $request = RemoteRequest::factory()->for($this->facility)->declined()->create();

        $this->post(route('remote.cancel', $request->public_code));

        $this->assertSame(RemoteRequestStatus::Declined, $request->fresh()->status);
    }

    public function test_the_status_page_tells_each_outcome_in_plain_words(): void
    {
        $declined = RemoteRequest::factory()->for($this->facility)->declined('The clinic is closing early today.')->create();
        $expired = RemoteRequest::factory()->for($this->facility)->create(['status' => RemoteRequestStatus::Expired]);

        $this->get(route('remote.status', $declined->public_code))
            ->assertSeeText('This request wasn\'t accepted')
            ->assertSeeText('The clinic is closing early today.')
            ->assertDontSee('Refresh');
        $this->get(route('remote.status', $expired->public_code))->assertSeeText('This request has expired.');
    }

    public function test_a_declined_request_without_a_reason_just_says_it_was_not_accepted(): void
    {
        $declined = RemoteRequest::factory()->for($this->facility)->declined()->create();

        $this->get(route('remote.status', $declined->public_code))->assertSeeText('This request wasn\'t accepted');
    }

    public function test_an_accepted_request_goes_straight_to_the_ordinary_tracking_page(): void
    {
        $visit = Visit::factory()->for($this->facility)->create(['status' => VisitStatus::AwaitingArrival]);
        $request = RemoteRequest::factory()->for($this->facility)->accepted()->create(['visit_id' => $visit->id]);

        $this->get(route('remote.status', $request->public_code))->assertRedirect(route('tracking.show', $visit->tracking_token));
    }

    public function test_an_unknown_code_is_not_found(): void
    {
        $this->get(route('remote.status', TrackingToken::generate()))->assertNotFound();
        $this->get('/r/not-a-token')->assertNotFound();
    }

    public function test_the_status_page_shows_only_the_requesters_own_first_name_and_facility(): void
    {
        $other = RemoteRequest::factory()->for($this->facility)->create(['name' => 'Hidden Other', 'phone' => '+254799000111']);
        $mine = RemoteRequest::factory()->for($this->facility)->create(['name' => 'Kevin Otieno']);

        $this->get(route('remote.status', $mine->public_code))
            ->assertSeeText('Kevin')
            ->assertSeeText('Upendo Clinic')
            ->assertDontSeeText('Hidden Other')
            ->assertDontSee('254799000111')
            ->assertDontSeeText($other->public_code);
    }

    public function test_it_does_not_disturb_the_ordinary_ways_in(): void
    {
        User::factory()->for($this->facility)->receptionist()->create();

        // Registering a walk-in still works exactly as before, and the site's own pages are not shadowed by facility addresses.
        $this->actingAs($this->receptionist)
            ->post(route('patients.register.store'), ['phone' => '0700 100 200', 'name' => 'Walk In', 'department_id' => $this->reception->id])
            ->assertSessionHasNoErrors();

        $this->assertSame('walk_in', Visit::sole()->source->value);
        $this->get('/about')->assertOk()->assertSeeText('About');
    }
}
