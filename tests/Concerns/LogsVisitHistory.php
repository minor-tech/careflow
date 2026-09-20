<?php

namespace Tests\Concerns;

use App\Enums\VisitEventType;
use App\Models\Department;
use App\Models\Facility;
use App\Models\Visit;
use App\Models\VisitEvent;
use Illuminate\Support\Carbon;

/**
 * Builds visits with an exact audit log, for tests of anything computed from
 * it. Needs a $facility property on the test.
 */
trait LogsVisitHistory
{
    /**
     * A visit registered at 10:00 on the given day (today by default), with
     * the given log: each step is [minutes after 10:00, event, department].
     *
     * @param  list<array{int|float, VisitEventType, Department|null}>  $steps
     * @param  array<string, mixed>  $attributes
     */
    protected function visitWith(array $steps, ?Carbon $day = null, ?Facility $facility = null, array $attributes = []): Visit
    {
        $start = ($day ?? now())->copy()->setTime(10, 0);

        $visit = Visit::factory()->for($facility ?? $this->facility)->create([
            'created_at' => $start,
            ...$attributes,
        ]);

        foreach ($steps as [$minutes, $event, $department]) {
            VisitEvent::factory()->create([
                'visit_id' => $visit->id,
                'department_id' => $department?->id,
                'event' => $event,
                'created_at' => $start->copy()->addSeconds((int) round($minutes * 60)),
            ]);
        }

        return $visit;
    }
}
