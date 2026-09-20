<?php

namespace Tests\Unit\Support;

use App\Support\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneNumberTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function sameKenyanMobile(): array
    {
        return [
            'local with zero' => ['0712345678', '+254712345678'],
            'local with spaces' => ['0712 345 678', '+254712345678'],
            'local with dashes' => ['0712-345-678', '+254712345678'],
            'without the leading zero' => ['712345678', '+254712345678'],
            'country code without plus' => ['254712345678', '+254712345678'],
            'international with plus' => ['+254712345678', '+254712345678'],
            'international with spaces' => ['+254 712 345 678', '+254712345678'],
            'brackets and dots' => ['(0712) 345.678', '+254712345678'],
            'the 01xx range' => ['0112345678', '+254112345678'],
        ];
    }

    #[DataProvider('sameKenyanMobile')]
    public function test_every_way_of_writing_a_kenyan_mobile_becomes_one_canonical_number(string $typed, string $expected): void
    {
        $this->assertSame($expected, PhoneNumber::normalize($typed));
    }

    public function test_other_countries_are_kept_when_written_with_their_plus_code(): void
    {
        $this->assertSame('+256772123456', PhoneNumber::normalize('+256 772 123 456'));
        $this->assertSame('+14155550100', PhoneNumber::normalize('+1 (415) 555-0100'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unusable(): array
    {
        return [
            'empty' => [''],
            'words' => ['call me'],
            'too short' => ['0712'],
            'too long for a Kenyan mobile' => ['07123456789'],
            'landline' => ['0201234567'],
            'malformed Kenyan number with plus code' => ['+254 712 345'],
            'plus code with the zero kept (a typo, not silently fixed)' => ['+2540712345678'],
            'foreign number without a plus' => ['0044 7911 123456'],
            'letters mixed in' => ['0712 345 67x'],
        ];
    }

    #[DataProvider('unusable')]
    public function test_rejects_what_is_not_a_usable_phone_number(string $typed): void
    {
        $this->assertNull(PhoneNumber::normalize($typed));
    }
}
