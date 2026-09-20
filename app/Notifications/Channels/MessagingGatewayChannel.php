<?php

namespace App\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Placeholder for SMS and WhatsApp delivery. No gateway is integrated yet, so
 * this records that a message was due without logging its body (which can
 * carry a temporary password). Replace the log call with the provider client
 * when a gateway is chosen.
 */
class MessagingGatewayChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        /** @var array{channels: list<string>, body: string} $message */
        $message = $notification->toMessagingGateway($notifiable);

        Log::info('Messaging gateway not configured; message was not delivered.', [
            'channels' => $message['channels'],
            'recipient_id' => $notifiable->getKey(),
            'phone' => $notifiable->phone,
        ]);
    }
}
