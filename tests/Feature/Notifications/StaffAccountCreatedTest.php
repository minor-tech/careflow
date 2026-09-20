<?php

namespace Tests\Feature\Notifications;

use App\Models\Facility;
use App\Models\User;
use App\Notifications\Channels\MessagingGatewayChannel;
use App\Notifications\StaffAccountCreated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StaffAccountCreatedTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{list<string>, list<string>}>
     */
    public static function channelChoices(): array
    {
        return [
            'email only' => [['email'], ['mail']],
            'sms only' => [['sms'], [MessagingGatewayChannel::class]],
            'whatsapp only' => [['whatsapp'], [MessagingGatewayChannel::class]],
            'sms and whatsapp share one gateway send' => [['sms', 'whatsapp'], [MessagingGatewayChannel::class]],
            'email and sms' => [['email', 'sms'], ['mail', MessagingGatewayChannel::class]],
            'no channel chosen falls back to email' => [[], ['mail']],
        ];
    }

    /**
     * @param  list<string>  $chosen
     * @param  list<string>  $expectedVia
     */
    #[DataProvider('channelChoices')]
    public function test_is_sent_over_the_channels_the_facility_chose(array $chosen, array $expectedVia): void
    {
        $facility = Facility::factory()->make(['notification_channels' => $chosen]);
        $member = User::factory()->receptionist()->make();

        $this->assertSame($expectedVia, (new StaffAccountCreated($facility, 'Tmp-Pass-123'))->via($member));
    }

    public function test_the_email_names_the_facility_role_login_and_temporary_password(): void
    {
        $facility = Facility::factory()->make(['name' => 'Upendo Health Centre']);
        $member = User::factory()->doctor()->make(['name' => 'Otieno', 'email' => 'otieno@upendo.test']);

        $mail = (new StaffAccountCreated($facility, 'Tmp-Pass-123'))->toMail($member);
        $body = implode("\n", $mail->introLines);

        $this->assertStringContainsString('Upendo Health Centre', $mail->subject);
        $this->assertStringContainsString('as Doctor', $body);
        $this->assertStringContainsString('Email: otieno@upendo.test', $body);
        $this->assertStringContainsString('Temporary password: Tmp-Pass-123', $body);
        $this->assertSame(route('login'), $mail->actionUrl);
    }

    public function test_the_placeholder_gateway_records_the_send_without_logging_the_password(): void
    {
        Log::spy();
        $facility = Facility::factory()->make(['notification_channels' => ['sms', 'whatsapp']]);
        $member = User::factory()->nurse()->create(['phone' => '0733222333']);

        (new MessagingGatewayChannel)->send($member, new StaffAccountCreated($facility, 'Tmp-Pass-123'));

        Log::shouldHaveReceived('info')->once()->withArgs(function (string $message, array $context) {
            return $context['channels'] === ['sms', 'whatsapp']
                && $context['phone'] === '0733222333'
                && ! str_contains(json_encode($context), 'Tmp-Pass-123');
        });
    }
}
