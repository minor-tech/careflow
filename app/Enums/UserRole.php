<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum UserRole: string
{
    use HasOptions;

    case Admin = 'admin';
    case Receptionist = 'receptionist';
    case Doctor = 'doctor';
    case Nurse = 'nurse';
    case SystemAdmin = 'system_admin';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Receptionist => 'Receptionist',
            self::Doctor => 'Doctor',
            self::Nurse => 'Nurse',
            self::SystemAdmin => 'System admin',
        };
    }

    /**
     * The accent colour that marks this role in lists (a dot beside each person).
     */
    public function accent(): string
    {
        return match ($this) {
            self::Admin, self::SystemAdmin => 'primary',
            self::Doctor => 'blue',
            self::Nurse => 'sage',
            self::Receptionist => 'gold',
        };
    }

    /**
     * Roles a facility admin can give to staff. An explicit list, not "every
     * role but admin", so a role added later (like system_admin) can never be
     * handed out by a facility admin by accident.
     *
     * @return array<string, string>
     */
    public static function staffOptions(): array
    {
        $staffRoles = [self::Receptionist, self::Doctor, self::Nurse];

        return array_combine(
            array_map(fn (self $role): string => $role->value, $staffRoles),
            array_map(fn (self $role): string => $role->label(), $staffRoles),
        );
    }
}
