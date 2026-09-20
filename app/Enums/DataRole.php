<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum DataRole: string
{
    use HasOptions;

    case Controller = 'controller';
    case Processor = 'processor';
    case Both = 'both';

    public function label(): string
    {
        return match ($this) {
            self::Controller => 'Data controller',
            self::Processor => 'Data processor',
            self::Both => 'Both controller and processor',
        };
    }
}
