<?php

namespace Tests\Feature\Models;

use App\Enums\RemoteQueueAvailability;
use App\Enums\RemoteRequestStatus;
use App\Models\Facility;
use App\Models\RemoteRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FacilityRemoteQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now(config('careflow.timezone'))->setTime(10, 0));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function facility(array $attributes = []): Facility
    {
        return Facility::factory()->create(['remote_queue_enabled' => true, ...$attributes]);
    }

    private function pending(Facility $facility, int $count = 1, array $attributes = []): void
    {
        RemoteRequest::factory()->for($facility)->count($count)->create($attributes);
    }

    public function test_a_facility_that_has_not_switched_it_on_is_off(): void
    {
        $this->assertSame(RemoteQueueAvailability::Off, $this->facility(['remote_queue_enabled' => false])->remoteQueueAvailability());
    }

    public function test_it_is_open_when_switched_on_within_the_cutoff_and_under_the_limit(): void
    {
        $facility = $this->facility(['remote_queue_accept_until' => '16:00', 'remote_queue_max_pending' => 3]);
        $this->pending($facility, 2);

        $this->assertSame(RemoteQueueAvailability::Open, $facility->remoteQueueAvailability());
        $this->assertTrue($facility->remoteQueueAvailability()->isOpen());
    }

    public function test_it_is_full_once_the_limit_of_waiting_requests_is_reached(): void
    {
        $facility = $this->facility(['remote_queue_max_pending' => 3]);
        $this->pending($facility, 3);

        $this->assertSame(RemoteQueueAvailability::Full, $facility->remoteQueueAvailability());
        $this->assertSame('The remote queue is full right now.', $facility->remoteQueueAvailability()->reason());
    }

    public function test_only_requests_from_home_waiting_for_review_today_count_towards_the_limit(): void
    {
        $facility = $this->facility(['remote_queue_max_pending' => 2]);
        $this->pending($facility, 1);
        $this->pending($facility, 1, ['status' => RemoteRequestStatus::Accepted]);
        $this->pending($facility, 1, ['status' => RemoteRequestStatus::Declined]);
        $this->pending($facility, 1, ['created_at' => now()->subDays(2)]);
        RemoteRequest::factory()->for($facility)->selfCheckin()->create();
        RemoteRequest::factory()->create(); // another facility's

        $this->assertSame(1, $facility->pendingRemoteRequestsToday());
        $this->assertSame(RemoteQueueAvailability::Open, $facility->remoteQueueAvailability());
    }

    public function test_it_closes_after_the_cutoff_to_the_minute(): void
    {
        $facility = $this->facility(['remote_queue_accept_until' => '16:00']);

        $this->travelTo(now(config('careflow.timezone'))->setTime(16, 0, 45));
        $this->assertSame(RemoteQueueAvailability::Open, $facility->fresh()->remoteQueueAvailability(), 'Still 16:00.');

        $this->travelTo(now(config('careflow.timezone'))->setTime(16, 1));
        $this->assertSame(RemoteQueueAvailability::Closed, $facility->fresh()->remoteQueueAvailability());
        $this->assertSame('Remote queue requests have closed for today.', $facility->fresh()->remoteQueueAvailability()->reason());
    }

    public function test_no_cutoff_means_all_day(): void
    {
        $facility = $this->facility(['remote_queue_accept_until' => null]);

        $this->travelTo(now(config('careflow.timezone'))->setTime(23, 30));

        $this->assertSame(RemoteQueueAvailability::Open, $facility->fresh()->remoteQueueAvailability());
    }

    /**
     * Every combination the live check has to get right, so the directory's one-query filter
     * can be held to exactly the same answer.
     *
     * @return array<string, array{array<string, mixed>, int, bool}>
     */
    public static function situations(): array
    {
        return [
            'off' => [['remote_queue_enabled' => false], 0, false],
            'open with room' => [['remote_queue_max_pending' => 5], 2, true],
            'full' => [['remote_queue_max_pending' => 2], 2, false],
            'one place left' => [['remote_queue_max_pending' => 2], 1, true],
            'past the cutoff' => [['remote_queue_accept_until' => '09:30'], 0, false],
            'before the cutoff' => [['remote_queue_accept_until' => '10:30'], 0, true],
            'cutoff this minute' => [['remote_queue_accept_until' => '10:00'], 0, true],
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    #[DataProvider('situations')]
    public function test_the_directory_filter_agrees_with_the_live_check(array $attributes, int $waiting, bool $open): void
    {
        $facility = $this->facility($attributes);
        $this->pending($facility, $waiting);

        $this->assertSame($open, $facility->remoteQueueAvailability()->isOpen());
        $this->assertSame($open, Facility::openForRemoteQueue()->whereKey($facility->id)->exists());
    }

    public function test_a_facility_address_can_never_be_a_word_the_site_already_uses(): void
    {
        foreach (['About', 'Login', 'Dashboard', 'Facilities', 'Queue', 'R', 'T'] as $name) {
            $slug = Facility::uniqueSlugFor($name);

            $this->assertNotContains($slug, Facility::RESERVED_SLUGS, "{$name} became {$slug}");
        }

        $this->assertSame('about-2', Facility::uniqueSlugFor('About'));
        $this->assertSame('upendo-clinic', Facility::uniqueSlugFor('Upendo Clinic'));
    }
}
