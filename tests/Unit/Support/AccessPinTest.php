<?php

namespace Tests\Unit\Support;

use App\Support\AccessPin;
use PHPUnit\Framework\TestCase;

class AccessPinTest extends TestCase
{
    public function test_a_pin_is_always_a_string_of_exactly_four_digits(): void
    {
        foreach (range(1, 300) as $ignored) {
            $this->assertMatchesRegularExpression('/^\d{4}$/', AccessPin::generate());
        }
    }

    public function test_pins_are_not_all_the_same(): void
    {
        $pins = array_map(fn () => AccessPin::generate(), range(1, 50));

        $this->assertGreaterThan(1, count(array_unique($pins)));
    }
}
