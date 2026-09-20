<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum DepartmentType: string
{
    use HasOptions;

    case Reception = 'reception';
    case Consultation = 'consultation';
    case Laboratory = 'laboratory';
    case Pharmacy = 'pharmacy';
    case Dental = 'dental';
    case Billing = 'billing';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Reception => 'Reception',
            self::Consultation => 'Consultation',
            self::Laboratory => 'Laboratory',
            self::Pharmacy => 'Pharmacy',
            self::Dental => 'Dental',
            self::Billing => 'Billing',
            self::Other => 'Other',
        };
    }

    /**
     * The letter shown in front of a department's own queue numbers, so
     * Laboratory's number 8 reads "L-8". Display only; never stored.
     */
    public function prefix(): string
    {
        return match ($this) {
            self::Reception => 'R',
            self::Consultation => 'C',
            self::Laboratory => 'L',
            self::Pharmacy => 'P',
            self::Billing => 'B',
            self::Dental => 'D',
            self::Other => 'G',
        };
    }
}
