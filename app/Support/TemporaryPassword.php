<?php

namespace App\Support;

/**
 * Generates the temporary passwords an admin passes on to staff. They are made
 * to be read out loud or typed from a message: no symbols, and none of the
 * characters that are easily mixed up (0 and O, 1 and l and I).
 */
final class TemporaryPassword
{
    public const LENGTH = 12;

    public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';

    public static function generate(): string
    {
        $password = '';
        $last = strlen(self::ALPHABET) - 1;

        for ($i = 0; $i < self::LENGTH; $i++) {
            $password .= self::ALPHABET[random_int(0, $last)];
        }

        return $password;
    }
}
