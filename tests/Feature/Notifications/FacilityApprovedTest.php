<?php

namespace Tests\Feature\Notifications;

use App\Models\Facility;
use App\Models\User;
use App\Notifications\FacilityApproved;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FacilityApprovedTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_sent_by_email_only(): void
    {
        $facility = Facility::factory()->make(['notification_channels' => ['sms', 'whatsapp']]);

        $this->assertSame(['mail'], (new FacilityApproved($facility))->via(User::factory()->make()));
    }

    public function test_tells_the_admin_they_can_log_in_and_links_to_the_login_page(): void
    {
        $facility = Facility::factory()->make(['name' => 'Upendo Health Centre']);
        $admin = User::factory()->make(['name' => 'Wanjiru']);

        $mail = (new FacilityApproved($facility))->toMail($admin);
        $body = implode("\n", $mail->introLines);

        $this->assertStringContainsString('Upendo Health Centre', $mail->subject);
        $this->assertStringContainsString('has been approved', $body);
        $this->assertStringContainsString('You can now log in', $body);
        $this->assertSame(route('login'), $mail->actionUrl);
    }
}
