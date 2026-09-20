<?php

namespace App\Support;

/**
 * What the SMS gateway did with a message, as a value. The gateway client
 * returns this rather than throwing, so a gateway problem can never turn into
 * an error in the code that asked for the message to be sent.
 */
final readonly class SmsResult
{
    private function __construct(
        public bool $accepted,
        public string $response,
    ) {}

    /**
     * The gateway accepted the message. $response is its raw answer.
     */
    public static function accepted(string $response): self
    {
        return new self(true, $response);
    }

    /**
     * The message was not sent. $reason says why, in words a person can use to debug it.
     */
    public static function failed(string $reason): self
    {
        return new self(false, $reason);
    }
}
