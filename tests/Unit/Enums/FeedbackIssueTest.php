<?php

namespace Tests\Unit\Enums;

use App\Enums\FeedbackIssue;
use PHPUnit\Framework\TestCase;

class FeedbackIssueTest extends TestCase
{
    public function test_the_categories_are_the_ones_patients_are_offered(): void
    {
        $this->assertSame(
            ['long_wait', 'staff', 'billing', 'doctor', 'laboratory', 'pharmacy', 'other'],
            FeedbackIssue::values(),
        );
    }

    public function test_every_category_has_a_readable_label(): void
    {
        $this->assertSame([
            'long_wait' => 'Long wait',
            'staff' => 'Staff',
            'billing' => 'Billing',
            'doctor' => 'Doctor',
            'laboratory' => 'Laboratory',
            'pharmacy' => 'Pharmacy',
            'other' => 'Other',
        ], FeedbackIssue::options());
    }
}
