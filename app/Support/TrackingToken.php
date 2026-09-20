<?php

namespace App\Support;

/**
 * The secret in a patient's tracking link. Lower case and without the
 * look-alike characters (0/o, 1/l/i) so it survives being read off a screen,
 * yet 14 characters of it is about 69 bits: not guessable.
 */
final class TrackingToken
{
    public const LENGTH = 14;

    public const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    /**
     * What a valid token looks like, for a route constraint.
     */
    public const PATTERN = '[a-hjkmnp-z2-9]{'.self::LENGTH.'}';

    public static function generate(): string
    {
        $token = '';
        $last = strlen(self::ALPHABET) - 1;

        for ($i = 0; $i < self::LENGTH; $i++) {
            $token .= self::ALPHABET[random_int(0, $last)];
        }

        return $token;
    }
}
