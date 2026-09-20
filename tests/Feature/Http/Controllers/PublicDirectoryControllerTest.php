<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\DepartmentType;
use App\Enums\VisitStatus;
use App\Models\Department;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\RemoteRequest;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpRemoteQueue;
use Tests\TestCase;

class PublicDirectoryControllerTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpRemoteQueue;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpRemoteQueue();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function otherFacility(string $name, array $attributes = []): Facility
    {
        return Facility::factory()->create(['name' => $name, 'county' => 'Nairobi', 'sub_county' => 'Westlands', ...$attributes]);
    }

    public function test_anyone_can_browse_the_directory_without_logging_in(): void
    {
        $this->get(route('directory.index'))
            ->assertOk()
            ->assertSeeText('Find a facility')
            ->assertSeeText('Upendo Clinic')
            ->assertSeeText('Kiharu, Muranga');
    }

    public function test_only_active_facilities_are_ever_listed(): void
    {
        $this->otherFacility('Pending Review Clinic', ['status' => 'pending_review']);
        $this->otherFacility('Suspended Clinic', ['status' => 'suspended']);
        $this->otherFacility('Rejected Clinic', ['status' => 'rejected']);

        $this->get(route('directory.index'))
            ->assertSeeText('Upendo Clinic')
            ->assertDontSeeText('Pending Review Clinic')
            ->assertDontSeeText('Suspended Clinic')
            ->assertDontSeeText('Rejected Clinic');
    }

    public function test_search_matches_the_name_county_or_sub_county(): void
    {
        $this->otherFacility('Westgate Medical Centre');

        $this->get(route('directory.index', ['q' => 'upendo']))->assertSeeText('Upendo Clinic')->assertDontSeeText('Westgate Medical Centre');
        $this->get(route('directory.index', ['q' => 'Muranga']))->assertSeeText('Upendo Clinic')->assertDontSeeText('Westgate Medical Centre');
        $this->get(route('directory.index', ['q' => 'kiharu']))->assertSeeText('Upendo Clinic')->assertDontSeeText('Westgate Medical Centre');
        $this->get(route('directory.index', ['q' => 'Westlands']))->assertSeeText('Westgate Medical Centre')->assertDontSeeText('Upendo Clinic');
        $this->get(route('directory.index', ['q' => 'nowhere at all']))->assertSeeText('No facility matches that search.');
    }

    public function test_search_words_are_matched_literally_not_as_wildcards(): void
    {
        $this->get(route('directory.index', ['q' => '%']))->assertDontSeeText('Upendo Clinic');
        $this->get(route('directory.index', ['q' => 'U_endo']))->assertDontSeeText('Upendo Clinic');
    }

    public function test_the_open_now_filter_keeps_only_facilities_taking_requests_this_minute(): void
    {
        $this->otherFacility('Switched Off Clinic');
        $this->otherFacility('Full Clinic', ['remote_queue_enabled' => true, 'remote_queue_max_pending' => 1]);
        RemoteRequest::factory()->for(Facility::where('name', 'Full Clinic')->first())->create();
        $this->otherFacility('Closed Clinic', ['remote_queue_enabled' => true, 'remote_queue_accept_until' => '09:00']);

        $this->get(route('directory.index', ['open_now' => 1]))
            ->assertOk()
            ->assertSeeText('Upendo Clinic')
            ->assertDontSeeText('Switched Off Clinic')
            ->assertDontSeeText('Full Clinic')
            ->assertDontSeeText('Closed Clinic')
            // A clean list of places worth requesting: nothing greyed out with a "closed" label.
            ->assertDontSeeText('closed for today')
            ->assertDontSeeText('is full');
    }

    public function test_the_filter_follows_the_live_count_and_clock_not_a_stored_flag(): void
    {
        $this->facility->update(['remote_queue_max_pending' => 1]);

        $this->get(route('directory.index', ['open_now' => 1]))->assertSeeText('Upendo Clinic');

        RemoteRequest::factory()->for($this->facility)->create();

        $this->get(route('directory.index', ['open_now' => 1]))->assertDontSeeText('Upendo Clinic')->assertSeeText('No facility is open for remote queue right now.');

        $this->facility->update(['remote_queue_max_pending' => 20, 'remote_queue_accept_until' => '10:30']);
        $this->get(route('directory.index', ['open_now' => 1]))->assertSeeText('Upendo Clinic');

        $this->travelTo(now(config('careflow.timezone'))->setTime(10, 31));
        $this->get(route('directory.index', ['open_now' => 1]))->assertDontSeeText('Upendo Clinic');
    }

    public function test_without_the_filter_every_facility_shows_and_says_why_it_is_not_taking_requests(): void
    {
        $this->otherFacility('Full Clinic', ['remote_queue_enabled' => true, 'remote_queue_max_pending' => 1]);
        RemoteRequest::factory()->for(Facility::where('name', 'Full Clinic')->first())->create();
        $this->otherFacility('Closed Clinic', ['remote_queue_enabled' => true, 'remote_queue_accept_until' => '09:00']);
        $this->otherFacility('Walk In Only Clinic');

        $html = $this->get(route('directory.index'))->assertOk();

        $html->assertSeeText('Remote queue available')
            ->assertSeeText('The remote queue is full right now.')
            ->assertSeeText('Remote queue requests have closed for today.')
            ->assertSeeText('Walk In Only Clinic');
        $this->assertSame(1, substr_count($html->getContent(), 'Remote queue available'), 'Only the facility that is open gets the badge.');
    }

    public function test_the_card_shows_live_wait_and_doctors_on_duty_from_real_data(): void
    {
        User::factory()->for($this->facility)->doctor()->create(['department_id' => $this->consultation->id]); // off duty
        Visit::factory()->inLineOf($this->kamau, 1)->count(4)->sequence(['doctor_queue_number' => 1], ['doctor_queue_number' => 2], ['doctor_queue_number' => 3], ['doctor_queue_number' => 4])->create();
        Visit::factory()->inLineOf($this->wanjiku, 1)->count(2)->sequence(['doctor_queue_number' => 1], ['doctor_queue_number' => 2])->create();

        $this->get(route('directory.index'))
            ->assertSeeTextInOrder(['Upendo Clinic', 'General Medicine', 'Current estimated wait', '15–20 minutes', 'Doctors on duty', '2']);
    }

    public function test_an_empty_line_says_no_wait_and_no_doctor_on_duty_says_nothing_about_a_wait(): void
    {
        $this->get(route('directory.index'))->assertSeeText('No wait');

        User::query()->where('role', 'doctor')->update(['is_on_duty' => false]);

        $this->get(route('directory.index'))->assertDontSeeText('Current estimated wait')->assertSeeText('Doctors on duty');
    }

    public function test_a_facility_with_one_shared_queue_is_measured_by_that_queue(): void
    {
        $facility = $this->otherFacility('Shared Queue Clinic');
        $room = Department::factory()->for($facility)->create(['name' => 'Consultation', 'type' => DepartmentType::Consultation]);
        User::factory()->for($facility)->doctor()->create(['department_id' => $room->id]);
        Visit::factory()->for($facility)->count(3)->create(['department_id' => $room->id, 'status' => VisitStatus::Waiting]);

        // Three waiting at the default 8 minutes each, one doctor: 24 minutes, give or take a fifth, in fives.
        $this->get(route('directory.show', $facility->slug))->assertSeeText('20–30 minutes');
    }

    public function test_the_facility_page_offers_the_request_form_only_while_it_is_open(): void
    {
        $this->get(route('directory.show', 'upendo'))
            ->assertOk()
            ->assertSeeText('Request to join today\'s queue')
            ->assertSeeText('Remote queue available')
            ->assertSee('name="requested_arrival"', false)
            ->assertSee('name="phone"', false)
            ->assertSeeText('General Medicine');

        $this->facility->update(['remote_queue_accept_until' => '09:00']);

        $this->get(route('directory.show', 'upendo'))
            ->assertSeeText('Remote queue requests have closed for today.')
            ->assertDontSee('name="requested_arrival"', false)
            ->assertDontSeeText('Remote queue available');

        $this->facility->update(['remote_queue_enabled' => false]);

        $this->get(route('directory.show', 'upendo'))
            ->assertSeeText('does not take queue requests online')
            ->assertDontSee('name="requested_arrival"', false);
    }

    public function test_a_facility_that_is_not_live_has_no_public_page(): void
    {
        foreach (['pending_review', 'suspended', 'rejected'] as $status) {
            $facility = $this->otherFacility("Not Live {$status}", ['status' => $status, 'slug' => "not-live-{$status}"]);

            $this->get(route('directory.show', $facility->slug))->assertNotFound();
        }

        $this->get('/no-such-facility')->assertNotFound();
    }

    public function test_service_and_doctor_choice_only_appear_when_the_facility_allows_them(): void
    {
        $this->get(route('directory.show', 'upendo'))
            ->assertSee('name="service_id"', false)
            ->assertDontSee('name="preferred_doctor_id"', false)
            ->assertDontSeeText('Wanjiku');

        $this->facility->update(['remote_queue_allow_doctor_choice' => true]);
        User::factory()->for($this->facility)->doctor()->create(['name' => 'Away Doctor', 'department_id' => $this->consultation->id]);

        $this->get(route('directory.show', 'upendo'))
            ->assertSee('name="preferred_doctor_id"', false)
            ->assertSeeText('Dr. Wanjiku Mwangi')
            ->assertSeeText('Dr. Kamau Njoroge')
            ->assertDontSeeText('Away Doctor');

        $this->facility->update(['remote_queue_allow_service_choice' => false, 'remote_queue_allow_doctor_choice' => false]);

        $this->get(route('directory.show', 'upendo'))->assertDontSee('name="service_id"', false)->assertDontSee('name="preferred_doctor_id"', false);
    }

    public function test_the_self_check_in_link_appears_only_where_it_is_switched_on(): void
    {
        $this->get(route('directory.show', 'upendo'))->assertSee(route('checkin.form', 'upendo'), false);

        $this->facility->update(['self_checkin_enabled' => false]);

        $this->get(route('directory.show', 'upendo'))->assertDontSee(route('checkin.form', 'upendo'), false);
    }

    public function test_no_public_page_shows_another_patients_name_a_doctors_workload_or_contact_details(): void
    {
        $patient = Patient::factory()->for($this->facility)->create(['name' => 'Confidential Patient', 'phone' => '+254799887766']);
        Visit::factory()->inLineOf($this->wanjiku, 1)->count(6)->create(['patient_id' => $patient->id]);
        $this->facility->update(['email' => 'private@upendo.test', 'phone' => '0700123456', 'address' => '12 Secret Lane']);

        foreach ([route('directory.index'), route('directory.show', 'upendo')] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertDontSeeText('Confidential Patient')
                ->assertDontSee('254799887766')
                ->assertDontSeeText('Wanjiku')
                ->assertDontSeeText('Kamau')
                ->assertDontSeeText('6 patients')
                ->assertDontSeeText('workload')
                ->assertDontSee('private@upendo.test')
                ->assertDontSee('0700123456')
                ->assertDontSee('12 Secret Lane')
                ->assertDontSee($this->admin->email)
                ->assertHeader('Cache-Control', 'no-store, private');
        }
    }

    public function test_the_directory_is_paged(): void
    {
        Facility::factory()->count(13)->create();

        $this->get(route('directory.index'))->assertOk()->assertSee('page=2', false);
        $this->assertCount(2, $this->get(route('directory.index', ['page' => 2]))->viewData('cards'));
    }

    public function test_the_site_navigation_links_to_the_directory(): void
    {
        $this->get(route('home'))->assertSee(route('directory.index'), false)->assertSeeText('Find a facility');
    }
}
