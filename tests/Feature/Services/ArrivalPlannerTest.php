<?php

namespace Tests\Feature\Services;

use App\Services\ArrivalPlanner;
use App\Support\WaitEstimate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ArrivalPlannerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 10:00 in the clinic's own time, whatever the server's timezone is.
        $this->travelTo(now(config('careflow.timezone'))->setTime(10, 0));
    }

    /**
     * @return array<string, array{int, int, string, string}>
     */
    public static function situations(): array
    {
        return [
            'nobody ahead: the time they said they could get there' => [0, 5, '10:30:00', '10:30–10:40 AM'],
            'a short line: shortly before they will be called' => [20, 30, '10:00:00', '10:00–10:10 AM'],
            'a long line: well after now' => [60, 80, '10:00:00', '10:40–10:50 AM'],
            'never before the time they said they could arrive' => [60, 80, '10:45:00', '10:45–10:50 AM'],
            'a window is never shorter than five minutes' => [60, 80, '10:58:00', '11:00–11:10 AM'],
            'rounded up to five minutes, never down to before they can arrive' => [60, 80, '10:57:00', '11:00–11:10 AM'],
            'rounded to five minutes' => [33, 43, '10:00:00', '10:15–10:25 AM'],
            'across noon each end says its own AM or PM' => [130, 150, '10:00:00', '11:50 AM–12:00 PM'],
            'in the afternoon' => [200, 230, '10:00:00', '1:00–1:10 PM'],
        ];
    }

    #[DataProvider('situations')]
    public function test_it_advises_arriving_shortly_before_the_estimated_call_and_never_before_they_can_get_there(int $low, int $high, string $requested, string $label): void
    {
        $window = app(ArrivalPlanner::class)->windowFor(new WaitEstimate($low, $high), $requested);

        $this->assertSame($label, $window->label());
        $this->assertTrue($window->until->greaterThan($window->from));
    }

    public function test_the_window_is_kept_in_the_applications_timezone_and_shown_in_the_clinics(): void
    {
        $window = app(ArrivalPlanner::class)->windowFor(new WaitEstimate(20, 30), '10:00:00');

        $this->assertSame(config('app.timezone'), $window->from->timezone->getName());
        $this->assertSame('10:00', $window->from->copy()->setTimezone(config('careflow.timezone'))->format('H:i'));
    }
}
