<?php

namespace App\Services;

use App\Models\QueueCounter;
use App\Support\ClinicDay;
use Illuminate\Support\Facades\DB;

class QueueNumberGenerator
{
    /**
     * The next queue number today: 1 for the first patient of the day, then
     * 2, 3 and so on, never repeated.
     *
     * Every department has its own sequence (Laboratory's number 8 is
     * unrelated to Reception's number 27), and passing no department gives the
     * facility-wide number a patient is handed at registration.
     *
     * It works on a locked counter row rather than counting visits, so two
     * receptionists registering at the same moment queue up on the row and
     * each get their own number. Call it inside the transaction that creates
     * the visit: the row stays locked until that commits, and a failed visit
     * rolls the number back instead of leaving a gap.
     */
    public function next(int $facilityId, ?int $departmentId = null): int
    {
        $today = $this->today();

        return DB::transaction(function () use ($facilityId, $departmentId, $today): int {
            $counter = QueueCounter::where('facility_id', $facilityId)
                ->where('date', $today)
                ->when(
                    $departmentId === null,
                    fn ($query) => $query->whereNull('department_id'),
                    fn ($query) => $query->where('department_id', $departmentId),
                );

            // Only the first registration of the day finds the row missing. It
            // is created with insertOrIgnore, so if two race to do it the
            // slower one carries on instead of failing on the unique key.
            //
            // The existence check is a plain read and the insert only happens
            // when the row is missing on purpose: an INSERT IGNORE over an
            // existing row takes a shared lock, and two transactions holding
            // one each while both try to lock the row for update deadlock.
            if (! $counter->exists()) {
                QueueCounter::insertOrIgnore([
                    'facility_id' => $facilityId,
                    'department_id' => $departmentId,
                    'date' => $today,
                    'last_number' => 0,
                ]);
            }

            $counter = $counter->lockForUpdate()->firstOrFail();

            $counter->increment('last_number');

            return $counter->last_number;
        }, attempts: 3);
    }

    /**
     * Today's date for the queue, in the clinic timezone (Nairobi), so the
     * numbers restart at local midnight rather than at 03:00.
     */
    public function today(): string
    {
        return ClinicDay::today();
    }
}
