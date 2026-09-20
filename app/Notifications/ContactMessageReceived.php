<?php

namespace App\Notifications;

use App\Models\ContactMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Lets the CareFlow team know someone wrote through the contact form. The
 * message is already saved by the time this is sent, so a mail problem never
 * loses it.
 */
class ContactMessageReceived extends Notification
{
    public function __construct(private ContactMessage $message) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("CareFlow contact form: {$this->message->name}")
            ->line("From: {$this->message->name}")
            ->line("Reach them at: {$this->message->contact}");

        if ($this->message->facility_name !== null) {
            $mail->line("Facility: {$this->message->facility_name}");
        }

        return $mail->line('Message:')->line($this->message->message);
    }
}
