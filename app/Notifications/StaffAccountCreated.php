<?php

namespace App\Notifications;

use App\Enums\NotificationChannel;
use App\Models\Facility;
use App\Models\User;
use App\Notifications\Channels\MessagingGatewayChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sends a new staff member their temporary password over the channels the
 * facility chose on its registration (email, SMS, WhatsApp).
 */
class StaffAccountCreated extends Notification
{
    public function __construct(
        private Facility $facility,
        private string $temporaryPassword,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        $chosen = $this->facility->notification_channels ?? [];
        $via = [];

        if (in_array(NotificationChannel::Email->value, $chosen, true)) {
            $via[] = 'mail';
        }

        if (array_intersect([NotificationChannel::Sms->value, NotificationChannel::Whatsapp->value], $chosen) !== []) {
            $via[] = MessagingGatewayChannel::class;
        }

        // A staff member with no way to receive their password is locked out.
        return $via ?: ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Your CareFlow account at {$this->facility->name}")
            ->greeting("Hello {$notifiable->name},")
            ->line("{$this->facility->name} has created a CareFlow account for you as {$notifiable->role->label()}.")
            ->line("Email: {$notifiable->email}")
            ->line("Temporary password: {$this->temporaryPassword}")
            ->action('Sign in', route('login'))
            ->line("You'll be asked to choose your own password the first time you sign in.");
    }

    /**
     * @return array{channels: list<string>, body: string}
     */
    public function toMessagingGateway(User $notifiable): array
    {
        $chosen = $this->facility->notification_channels ?? [];

        return [
            'channels' => array_values(array_intersect(
                [NotificationChannel::Sms->value, NotificationChannel::Whatsapp->value],
                $chosen,
            )),
            'body' => "CareFlow: {$this->facility->name} created your account. "
                .'Sign in at '.route('login')." with {$notifiable->email} and temporary password {$this->temporaryPassword}",
        ];
    }
}
