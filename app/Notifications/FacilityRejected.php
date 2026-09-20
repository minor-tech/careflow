<?php

namespace App\Notifications;

use App\Models\Facility;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a facility's admin the registration was not approved, and why.
 */
class FacilityRejected extends Notification
{
    public function __construct(
        private Facility $facility,
        private string $reason,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Your CareFlow registration for {$this->facility->name}")
            ->greeting("Hello {$notifiable->name},")
            ->line("We could not approve the registration for {$this->facility->name}.")
            ->line("Reason: {$this->reason}")
            ->line('If you think this is a mistake, please contact CareFlow support.');
    }
}
