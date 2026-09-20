<?php

namespace Tests\Unit\Support;

use App\Support\QueueCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class QueueCodeTest extends TestCase
{
    public function test_formats_a_queue_number_as_v_and_at_least_three_digits(): void
    {
        $this->assertSame('V001', QueueCode::format(1));
        $this->assertSame('V027', QueueCode::format(27));
        $this->assertSame('V123', QueueCode::format(123));
        $this->assertSame('V1234', QueueCode::format(1234));
    }

    /**
     * @return array<string, array{string, int|null}>
     */
    public static function typedCodes(): array
    {
        return [
            'as printed' => ['V027', 27],
            'lower case' => ['v027', 27],
            'unpadded' => ['V27', 27],
            'bare number' => ['27', 27],
            'with a hash' => ['#27', 27],
            'stray spaces' => [' v 0 27 ', 27],
            'the letter O for a zero' => ['VO27', 27],
            'lower case o for a zero' => ['vo27', 27],
            'the letter I for a one' => ['V00I', 1],
            'a code with letters in the middle' => ['V2X7', null],
            'another letter' => ['L-8', null],
            'empty' => ['', null],
            'only a letter' => ['V', null],
            'too long to be a queue number' => ['V1234567', null],
        ];
    }

    #[DataProvider('typedCodes')]
    public function test_reads_what_a_patient_types(string $typed, ?int $expected): void
    {
        $this->assertSame($expected, QueueCode::parse($typed));
    }

    public function test_reads_back_what_it_formats(): void
    {
        foreach ([1, 9, 27, 100, 999] as $number) {
            $this->assertSame($number, QueueCode::parse(QueueCode::format($number)));
        }
    }
}
