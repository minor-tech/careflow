<?php

namespace App\Support;

/**
 * The 4-digit PIN that goes with a visit's queue code: the credential for
 * following the visit by typing instead of scanning. Only 10,000 possibilities,
 * so it is protected by rate limits and a short life (it stops working when the
 * visit ends), not by secrecy alone.
 */
final class AccessPin
{
    public const LENGTH = 4;

    public static function generate(): string
    {
        return str_pad((string) random_int(0, 10 ** self::LENGTH - 1), self::LENGTH, '0', STR_PAD_LEFT);
    }
}
