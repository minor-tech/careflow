<?php

namespace App\Notifications;

use App\Models\Facility;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a facility's admin the registration was approved. Email only: the
 * facility's SMS sender isn't set up until it is active.
 */
class FacilityApproved extends Notification
{
    public function __construct(private Facility $facility) {}

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
            ->subject("{$this->facility->name} is approved on CareFlow")
            ->greeting("Hello {$notifiable->name},")
            ->line("Good news: the registration for {$this->facility->name} has been approved.")
            ->line('You can now log in. Your staff can log in too, and your dashboard is live.')
            ->action('Log in', route('login'));
    }
}
