<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum NotificationChannel: string
{
    use HasOptions;

    case Sms = 'sms';
    case Whatsapp = 'whatsapp';
    case Email = 'email';

    public function label(): string
    {
        return match ($this) {
            self::Sms => 'SMS',
            self::Whatsapp => 'WhatsApp',
            self::Email => 'Email',
        };
    }
}
