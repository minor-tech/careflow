<?php

namespace Tests\Feature\Jobs;

use App\Enums\RemoteRequestStatus;
use App\Jobs\ExpireRemoteRequests;
use App\Models\Facility;
use App\Models\RemoteRequest;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpireRemoteRequestsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now(config('careflow.timezone'))->setTime(10, 0));
    }

    public function test_a_request_still_waiting_from_before_today_expires(): void
    {
        $yesterday = RemoteRequest::factory()->create(['created_at' => now()->subDay()]);
        $lastWeek = RemoteRequest::factory()->selfCheckin()->create(['created_at' => now()->subDays(7)]);

        ExpireRemoteRequests::dispatchSync();

        $this->assertSame(RemoteRequestStatus::Expired, $yesterday->fresh()->status);
        $this->assertSame(RemoteRequestStatus::Expired, $lastWeek->fresh()->status);
    }

    public function test_todays_requests_are_left_alone_including_one_made_a_minute_after_midnight(): void
    {
        $justAfterMidnight = RemoteRequest::factory()->create(['created_at' => now(config('careflow.timezone'))->startOfDay()->addMinute()->utc()]);
        $morning = RemoteRequest::factory()->create();

        ExpireRemoteRequests::dispatchSync();

        $this->assertSame(RemoteRequestStatus::Pending, $justAfterMidnight->fresh()->status);
        $this->assertSame(RemoteRequestStatus::Pending, $morning->fresh()->status);
    }

    public function test_only_requests_nobody_decided_are_touched(): void
    {
        $accepted = RemoteRequest::factory()->accepted()->create(['created_at' => now()->subDays(2)]);
        $declined = RemoteRequest::factory()->declined('No room')->create(['created_at' => now()->subDays(2)]);
        $cancelled = RemoteRequest::factory()->create(['status' => RemoteRequestStatus::Cancelled, 'created_at' => now()->subDays(2)]);

        ExpireRemoteRequests::dispatchSync();

        $this->assertSame(RemoteRequestStatus::Accepted, $accepted->fresh()->status);
        $this->assertSame(RemoteRequestStatus::Declined, $declined->fresh()->status);
        $this->assertSame('No room', $declined->fresh()->declined_reason);
        $this->assertSame(RemoteRequestStatus::Cancelled, $cancelled->fresh()->status);
    }

    public function test_it_covers_every_facility(): void
    {
        $other = Facility::factory()->create();
        $stale = RemoteRequest::factory()->for($other)->create(['created_at' => now()->subDay()]);

        ExpireRemoteRequests::dispatchSync();

        $this->assertSame(RemoteRequestStatus::Expired, $stale->fresh()->status);
    }

    public function test_an_expired_request_says_so_on_its_own_page(): void
    {
        $request = RemoteRequest::factory()->create(['created_at' => now()->subDay()]);
        ExpireRemoteRequests::dispatchSync();

        $this->get(route('remote.status', $request->public_code))->assertSeeText('This request has expired.');
    }

    public function test_it_is_scheduled_a_few_minutes_after_midnight_clinic_time(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => $event->description === 'expire-remote-requests');

        $this->assertNotNull($event, 'The nightly expiry is not on the schedule.');
        $this->assertSame('5 0 * * *', $event->expression);
        $this->assertSame('Africa/Nairobi', (string) $event->timezone);
    }
}
