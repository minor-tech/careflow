<?php

namespace App\Support;

/**
 * A visit's queue number as printed on the ticket and typed into the tracking
 * form: "V027". Display only; the stored number stays a plain integer. Not a
 * secret, so it is fine to say aloud.
 */
final class QueueCode
{
    public static function format(int $queueNumber): string
    {
        return 'V'.str_pad((string) $queueNumber, 3, '0', STR_PAD_LEFT);
    }

    /**
     * The queue number behind whatever a patient typed: "V027", "v27", " v 027 ",
     * "#27" and "27" all mean 27. Null when it isn't a queue code at all. The
     * letters O and I read as the digits 0 and 1: in most typefaces they look
     * the same, and a patient copying "V001" off a ticket will mix them up.
     */
    public static function parse(string $input): ?int
    {
        $cleaned = strtr(strtoupper(preg_replace('/[\s#]+/', '', $input) ?? ''), ['O' => '0', 'I' => '1']);

        if (preg_match('/^V?(\d{1,6})$/', $cleaned, $found) !== 1) {
            return null;
        }

        return (int) $found[1];
    }
}
