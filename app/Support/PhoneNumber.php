<?php

namespace App\Support;

/**
 * Puts phone numbers into one canonical form so the same person is always
 * recognised however the number was typed: "0712 345 678", "0712345678" and
 * "+254 712 345 678" are all "+254712345678".
 */
final class PhoneNumber
{
    /**
     * The canonical form: "+" then digits, 9 to 15 of them.
     */
    public const CANONICAL_PATTERN = '/^\+[0-9]{9,15}$/';

    /**
     * Canonical form of a phone number, or null if it isn't a usable one.
     * Kenyan mobiles (07xx and 01xx) are accepted with or without the 0 or
     * country code; other numbers must be written with their + country code.
     */
    public static function normalize(string $input): ?string
    {
        $compact = preg_replace('/[\s\-().]/', '', $input);

        if (preg_match('/^(?:\+?254|0)?([17][0-9]{8})$/', $compact, $matches) === 1) {
            return '+254'.$matches[1];
        }

        // A malformed Kenyan number must not slip through as "international".
        if (str_starts_with($compact, '+254')) {
            return null;
        }

        return preg_match(self::CANONICAL_PATTERN, $compact) === 1 ? $compact : null;
    }
}
