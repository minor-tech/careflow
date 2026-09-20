<?php

namespace Tests\Feature\Notifications;

use App\Models\Facility;
use App\Models\User;
use App\Notifications\FacilityRejected;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FacilityRejectedTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_sent_by_email_only(): void
    {
        $facility = Facility::factory()->make(['notification_channels' => ['sms']]);

        $this->assertSame(['mail'], (new FacilityRejected($facility, 'Reason'))->via(User::factory()->make()));
    }

    public function test_gives_the_reason_and_does_not_claim_the_facility_is_approved(): void
    {
        $facility = Facility::factory()->make(['name' => 'Upendo Health Centre']);
        $admin = User::factory()->make(['name' => 'Wanjiru']);

        $mail = (new FacilityRejected($facility, 'The license number does not match the register.'))->toMail($admin);
        $body = implode("\n", $mail->introLines).implode("\n", $mail->outroLines);

        $this->assertStringContainsString('Upendo Health Centre', $mail->subject);
        $this->assertStringContainsString('could not approve', $body);
        $this->assertStringContainsString('Reason: The license number does not match the register.', $body);
        $this->assertStringNotContainsString('You can now log in', $body);
        $this->assertNull($mail->actionUrl);
    }
}
