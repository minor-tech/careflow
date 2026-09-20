<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum OwnershipType: string
{
    use HasOptions;

    case Private = 'private';
    case Public = 'public';
    case FaithBased = 'faith_based';
    case Ngo = 'ngo';

    public function label(): string
    {
        return match ($this) {
            self::Private => 'Private',
            self::Public => 'Public',
            self::FaithBased => 'Faith-based',
            self::Ngo => 'NGO',
        };
    }
}
