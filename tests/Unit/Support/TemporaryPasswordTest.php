<?php

namespace Tests\Unit\Support;

use App\Support\TemporaryPassword;
use PHPUnit\Framework\TestCase;

class TemporaryPasswordTest extends TestCase
{
    public function test_is_twelve_characters_long(): void
    {
        $this->assertSame(12, strlen(TemporaryPassword::generate()));
    }

    public function test_uses_no_symbols_and_none_of_the_characters_that_are_easily_mixed_up(): void
    {
        foreach (range(1, 200) as $ignored) {
            $this->assertMatchesRegularExpression('/^[A-HJ-NP-Za-km-z2-9]{12}$/', TemporaryPassword::generate());
        }
    }

    public function test_the_alphabet_leaves_out_look_alikes(): void
    {
        foreach (['0', 'O', '1', 'l', 'I'] as $ambiguous) {
            $this->assertStringNotContainsString($ambiguous, TemporaryPassword::ALPHABET);
        }
    }

    public function test_every_password_is_different(): void
    {
        $passwords = array_map(fn () => TemporaryPassword::generate(), range(1, 500));

        $this->assertCount(500, array_unique($passwords));
    }
}
