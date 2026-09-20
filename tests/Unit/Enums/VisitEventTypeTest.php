<?php

namespace Tests\Unit\Enums;

use App\Enums\VisitEventType;
use App\Enums\VisitStatus;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class VisitEventTypeTest extends TestCase
{
    public function test_a_status_change_is_never_logged_as_moving_department(): void
    {
        $this->expectException(InvalidArgumentException::class);

        VisitEventType::forTransition(VisitStatus::InService, VisitStatus::WaitingDepartment);
    }

    public function test_events_are_named_for_the_step_not_the_resulting_status(): void
    {
        $this->assertSame(VisitEventType::Started, VisitEventType::forTransition(VisitStatus::Called, VisitStatus::InService));
        $this->assertSame(VisitEventType::Recalled, VisitEventType::forTransition(VisitStatus::Called, VisitStatus::Waiting));
    }

    public function test_a_transfer_has_its_own_event_name(): void
    {
        $this->assertSame('transferred', VisitEventType::Transferred->value);
    }
}
