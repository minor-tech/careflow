<?php

namespace Tests\Unit\Enums;

use App\Enums\FacilityStatus;
use App\Enums\NotificationStatus;
use App\Enums\UserStatus;
use App\Enums\VisitStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnitEnum;

class StatusToneTest extends TestCase
{
    /**
     * Every case of every status enum, so a case added later cannot ship without a tone.
     *
     * @return array<string, array{UnitEnum}>
     */
    public static function statuses(): array
    {
        $cases = [];

        foreach ([VisitStatus::class, UserStatus::class, FacilityStatus::class, NotificationStatus::class] as $enum) {
            foreach ($enum::cases() as $case) {
                $cases[class_basename($enum).'::'.$case->name] = [$case];
            }
        }

        return $cases;
    }

    #[DataProvider('statuses')]
    public function test_every_status_has_a_tone_the_status_pill_can_draw(UnitEnum $status): void
    {
        $this->assertContains($status->tone(), ['ok', 'wait', 'info', 'danger', 'muted']);
    }

    public function test_things_that_went_wrong_are_danger_and_things_that_are_fine_are_ok(): void
    {
        $this->assertSame('danger', UserStatus::Suspended->tone());
        $this->assertSame('danger', FacilityStatus::Rejected->tone());
        $this->assertSame('danger', NotificationStatus::Failed->tone());
        $this->assertSame('ok', UserStatus::Active->tone());
        $this->assertSame('ok', FacilityStatus::Active->tone());
        $this->assertSame('ok', NotificationStatus::Sent->tone());
    }

    public function test_a_visit_is_gold_while_it_waits_blue_once_called_and_green_while_served(): void
    {
        $this->assertSame('wait', VisitStatus::Waiting->tone());
        $this->assertSame('info', VisitStatus::Called->tone());
        $this->assertSame('ok', VisitStatus::InService->tone());
    }
}
