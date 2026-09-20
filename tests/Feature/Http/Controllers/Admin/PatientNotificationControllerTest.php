<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Enums\FacilityStatus;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\PatientNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PatientNotificationControllerTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facility = Facility::factory()->create();
        $this->admin = User::factory()->for($this->facility)->create();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function message(string $text, string $state = 'queued', array $attributes = []): PatientNotification
    {
        $factory = PatientNotification::factory()->for($this->facility);

        if ($state !== 'queued') {
            $factory = $factory->{$state}();
        }

        return $factory->create(['message' => $text, ...$attributes]);
    }

    public function test_it_lists_this_facilitys_messages_newest_first_with_patient_and_status(): void
    {
        $patient = Patient::factory()->for($this->facility)->create(['name' => 'Wanjiru Kamau', 'phone' => '+254712345678']);
        $this->message('Older message', 'sent', ['patient_id' => $patient->id, 'created_at' => now()->subHour(), 'sent_at' => now()->subHour()]);
        $this->message('Newer message', 'sent', ['patient_id' => $patient->id]);

        $this->actingAs($this->admin)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSeeInOrder(['Newer message', 'Older message'])
            ->assertSeeText('Wanjiru Kamau')
            ->assertSeeText('+254712345678')
            ->assertSeeText('Sent');
    }

    public function test_it_never_shows_another_facilitys_messages(): void
    {
        $this->message('Ours');
        PatientNotification::factory()->for(Facility::factory())->create(['message' => 'Theirs']);

        $this->actingAs($this->admin)
            ->get(route('notifications.index'))
            ->assertSeeText('Ours')
            ->assertDontSeeText('Theirs');
    }

    public function test_it_shows_when_a_message_was_sent_in_nairobi_time(): void
    {
        // 06:30 UTC is 09:30 in Nairobi.
        $this->message('Timed message', 'sent', ['sent_at' => '2026-09-18 06:30:00', 'created_at' => '2026-09-18 06:29:00']);

        $this->actingAs($this->admin)
            ->get(route('notifications.index'))
            ->assertSeeText('18 Sep, 9:30 AM');
    }

    public function test_a_failed_message_says_why(): void
    {
        $this->message('Never arrived', 'failed', ['provider_response' => 'The gateway answered HTTP 401: The supplied authentication is invalid']);

        $this->actingAs($this->admin)
            ->get(route('notifications.index'))
            ->assertSeeText('Failed')
            ->assertSeeText('Why it failed')
            ->assertSeeText('The supplied authentication is invalid');
    }

    public function test_the_tabs_count_each_status_for_this_facility_only(): void
    {
        $this->message('a', 'sent');
        $this->message('b', 'sent');
        $this->message('c', 'failed');
        $this->message('d');
        PatientNotification::factory()->for(Facility::factory())->failed()->count(5)->create();

        $response = $this->actingAs($this->admin)->get(route('notifications.index'))->assertOk();

        $response->assertSeeInOrder(['All', '4', 'Failed', '1', 'Queued', '1', 'Sent', '2']);
    }

    public function test_a_status_filter_narrows_the_list(): void
    {
        $this->message('Delivered one', 'sent');
        $this->message('Broken one', 'failed');
        $this->message('Waiting one');

        $this->actingAs($this->admin)
            ->get(route('notifications.index', ['status' => 'failed']))
            ->assertSeeText('Broken one')
            ->assertDontSeeText('Delivered one')
            ->assertDontSeeText('Waiting one');
    }

    public function test_an_unknown_status_filter_is_ignored(): void
    {
        $this->message('Delivered one', 'sent');
        $this->message('Broken one', 'failed');

        $this->actingAs($this->admin)
            ->get(route('notifications.index', ['status' => 'bogus']))
            ->assertOk()
            ->assertSeeText('Delivered one')
            ->assertSeeText('Broken one');
    }

    public function test_an_empty_filter_says_so(): void
    {
        $this->message('Delivered one', 'sent');

        $this->actingAs($this->admin)
            ->get(route('notifications.index', ['status' => 'failed']))
            ->assertSeeText('No failed messages.');
    }

    public function test_a_facility_with_no_messages_gets_an_explanation(): void
    {
        $this->actingAs($this->admin)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSeeText('No messages yet.');
    }

    public function test_messages_stuck_in_queued_point_at_the_queue_worker(): void
    {
        $this->message('Stuck', 'queued', ['created_at' => now()->subMinutes(10)]);

        $this->actingAs($this->admin)
            ->get(route('notifications.index'))
            ->assertSeeText('queue worker');
    }

    public function test_freshly_queued_messages_do_not_raise_the_alarm(): void
    {
        $this->message('Just queued', 'queued', ['created_at' => now()->subMinute()]);

        $this->actingAs($this->admin)
            ->get(route('notifications.index'))
            ->assertDontSeeText('queue worker');
    }

    public function test_another_facilitys_stuck_message_does_not_raise_the_alarm(): void
    {
        PatientNotification::factory()->for(Facility::factory())->create(['created_at' => now()->subHour()]);

        $this->actingAs($this->admin)
            ->get(route('notifications.index'))
            ->assertDontSeeText('queue worker');
    }

    public function test_it_pages_at_25_and_keeps_the_filter_on_the_next_page(): void
    {
        foreach (range(1, 27) as $n) {
            $this->message(sprintf('Failed message #%02d', $n), 'failed', ['created_at' => now()->subMinutes(100 - $n)]);
        }

        $first = $this->actingAs($this->admin)->get(route('notifications.index', ['status' => 'failed']))->assertOk();
        $first->assertSeeText('Failed message #27')->assertSeeText('Failed message #03')->assertDontSeeText('Failed message #02')->assertSee('status=failed', false);
        $this->assertSame(25, substr_count($first->getContent(), 'Why it failed'));

        $this->actingAs($this->admin)
            ->get(route('notifications.index', ['status' => 'failed', 'page' => 2]))
            ->assertSeeText('Failed message #02')
            ->assertSeeText('Failed message #01')
            ->assertDontSeeText('Failed message #03');
    }

    public function test_the_notifications_link_is_in_the_admin_navigation(): void
    {
        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertRedirect();

        $this->actingAs($this->admin)
            ->get(route('departments.index'))
            ->assertSee(route('notifications.index'), false);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonAdminRoles(): array
    {
        return [
            'receptionist' => ['receptionist'],
            'doctor' => ['doctor'],
            'nurse' => ['nurse'],
            'system admin' => ['systemAdmin'],
        ];
    }

    #[DataProvider('nonAdminRoles')]
    public function test_only_a_facility_admin_can_see_it(string $role): void
    {
        $user = User::factory()->for($this->facility)->{$role}()->create();

        $this->actingAs($user)->get(route('notifications.index'))->assertForbidden();
    }

    #[DataProvider('nonAdminRoles')]
    public function test_other_roles_do_not_get_the_navigation_link(string $role): void
    {
        $user = User::factory()->for($this->facility)->{$role}()->create();

        $this->actingAs($user)
            ->get(route('queue.index'))
            ->assertDontSee(route('notifications.index'), false);
    }

    public function test_a_guest_is_sent_to_log_in(): void
    {
        $this->get(route('notifications.index'))->assertRedirect(route('login'));
    }

    public function test_a_suspended_facilitys_admin_is_sent_to_the_status_page(): void
    {
        $this->facility->update(['status' => FacilityStatus::Suspended]);

        $this->actingAs($this->admin)->get(route('notifications.index'))->assertRedirect(route('facility.status'));
    }
}
