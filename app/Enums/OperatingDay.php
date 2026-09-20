<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum OperatingDay: string
{
    use HasOptions;

    case Mon = 'mon';
    case Tue = 'tue';
    case Wed = 'wed';
    case Thu = 'thu';
    case Fri = 'fri';
    case Sat = 'sat';
    case Sun = 'sun';

    public function label(): string
    {
        return match ($this) {
            self::Mon => 'Mon',
            self::Tue => 'Tue',
            self::Wed => 'Wed',
            self::Thu => 'Thu',
            self::Fri => 'Fri',
            self::Sat => 'Sat',
            self::Sun => 'Sun',
        };
    }
}
