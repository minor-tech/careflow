<?php

namespace Tests\Unit\Support;

use App\Support\TrackingToken;
use PHPUnit\Framework\TestCase;

class TrackingTokenTest extends TestCase
{
    public function test_it_is_fourteen_characters_long(): void
    {
        $this->assertSame(14, strlen(TrackingToken::generate()));
    }

    public function test_every_token_matches_the_pattern_the_route_accepts(): void
    {
        foreach (range(1, 300) as $ignored) {
            $this->assertMatchesRegularExpression('/^'.TrackingToken::PATTERN.'$/', TrackingToken::generate());
        }
    }

    public function test_it_is_lower_case_and_leaves_out_look_alikes(): void
    {
        foreach (['0', 'o', '1', 'l', 'i'] as $ambiguous) {
            $this->assertStringNotContainsString($ambiguous, TrackingToken::ALPHABET);
        }

        $this->assertSame(strtolower(TrackingToken::ALPHABET), TrackingToken::ALPHABET);
    }

    public function test_the_pattern_rejects_anything_that_is_not_a_token(): void
    {
        foreach (['', 'short', 'ABCDEFGHJKMNPQ', 'abcdefghjkmnp!', 'abcdefghjkmnpqr', 'abcdefghjkmn0q', '../../etc/passwd'] as $notAToken) {
            $this->assertDoesNotMatchRegularExpression('/^'.TrackingToken::PATTERN.'$/', $notAToken);
        }
    }

    public function test_every_token_is_different(): void
    {
        $tokens = array_map(fn () => TrackingToken::generate(), range(1, 500));

        $this->assertCount(500, array_unique($tokens));
    }
}
