<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum FacilityType: string
{
    use HasOptions;

    case Clinic = 'clinic';
    case Hospital = 'hospital';
    case Pharmacy = 'pharmacy';
    case Dental = 'dental';
    case DiagnosticLab = 'diagnostic_lab';

    public function label(): string
    {
        return match ($this) {
            self::Clinic => 'Clinic',
            self::Hospital => 'Hospital',
            self::Pharmacy => 'Pharmacy',
            self::Dental => 'Dental',
            self::DiagnosticLab => 'Diagnostic lab',
        };
    }
}
