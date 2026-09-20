<?php

namespace App\Services;

use App\Enums\VisitStatus;
use App\Models\User;
use App\Models\Visit;
use App\Support\DoctorOption;
use Illuminate\Support\Collection;

/**
 * Suggests which doctor a patient should see, by rules anyone can check: only
 * doctors who are on duty and fit the service asked for are considered, and
 * the recommended one is simply the one with the shortest line right now.
 *
 * It only ranks. Nothing here assigns anyone: the receptionist always makes
 * the final choice, and can pick any doctor in the list, not just the first.
 */
class DoctorRecommender
{
    public function __construct(private WaitEstimator $waits) {}

    /**
     * The doctors a patient could be sent to, shortest line first (ties by
     * name, so the order never shuffles between two screens), the first marked
     * as recommended. Empty when no fitting doctor is on duty.
     *
     * A doctor's line is the patients assigned to them today who are still
     * waiting (including those on their way, who hold a place), called or being
     * seen, and who haven't been sent on elsewhere.
     *
     * @return list<DoctorOption>
     */
    public function rank(int $departmentId, ?int $serviceId, int $facilityId): array
    {
        $doctors = User::query()
            ->availableDoctors($facilityId, $departmentId)
            ->when($serviceId !== null, fn ($query) => $query->where('service_id', $serviceId))
            ->with('service:id,name')
            ->get();

        if ($doctors->isEmpty()) {
            return [];
        }

        $lineLengths = $this->lineLengths($doctors->modelKeys());

        return $doctors
            ->map(fn (User $doctor): array => [$doctor, (int) $lineLengths->get($doctor->id, 0)])
            ->sort(fn (array $a, array $b): int => [$a[1], $a[0]->name, $a[0]->id] <=> [$b[1], $b[0]->name, $b[0]->id])
            ->values()
            ->map(fn (array $entry, int $index): DoctorOption => new DoctorOption(
                doctor: $entry[0],
                activeCount: $entry[1],
                estimate: $this->waits->estimateFor($departmentId, $entry[1]),
                recommended: $index === 0,
            ))
            ->all();
    }

    /**
     * How many patients are in each of these doctors' lines today.
     *
     * @param  list<int|string>  $doctorIds
     * @return Collection<int, int>
     */
    private function lineLengths(array $doctorIds): Collection
    {
        return Visit::query()
            ->whereIn('assigned_doctor_id', $doctorIds)
            ->whereNotNull('doctor_queue_number')
            ->whereIn('status', [VisitStatus::AwaitingArrival, VisitStatus::Waiting, VisitStatus::Called, VisitStatus::InService])
            ->registeredToday()
            ->toBase()
            ->selectRaw('assigned_doctor_id, count(*) as total')
            ->groupBy('assigned_doctor_id')
            ->pluck('total', 'assigned_doctor_id');
    }
}
