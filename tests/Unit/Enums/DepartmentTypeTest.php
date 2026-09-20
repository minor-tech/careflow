<?php

namespace Tests\Unit\Enums;

use App\Enums\DepartmentType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DepartmentTypeTest extends TestCase
{
    /**
     * @return array<string, array{DepartmentType, string}>
     */
    public static function prefixes(): array
    {
        return [
            'reception' => [DepartmentType::Reception, 'R'],
            'consultation' => [DepartmentType::Consultation, 'C'],
            'laboratory' => [DepartmentType::Laboratory, 'L'],
            'pharmacy' => [DepartmentType::Pharmacy, 'P'],
            'billing' => [DepartmentType::Billing, 'B'],
            'dental' => [DepartmentType::Dental, 'D'],
            'other' => [DepartmentType::Other, 'G'],
        ];
    }

    #[DataProvider('prefixes')]
    public function test_each_department_type_has_its_queue_letter(DepartmentType $type, string $letter): void
    {
        $this->assertSame($letter, $type->prefix());
    }

    public function test_every_department_type_has_a_prefix(): void
    {
        $this->assertCount(count(DepartmentType::cases()), self::prefixes());
    }
}
