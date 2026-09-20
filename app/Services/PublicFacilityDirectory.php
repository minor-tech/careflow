<?php

namespace App\Services;

use App\Enums\DepartmentType;
use App\Enums\FacilityStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Department;
use App\Models\Facility;
use App\Models\Service;
use App\Models\User;
use App\Support\PublicFacilityCard;
use App\Support\WaitEstimate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The public's view of facilities: only active ones, only the fields a card
 * shows, all of it worked out live from the same data staff see.
 */
class PublicFacilityDirectory
{
    public const PER_PAGE = 12;

    public function __construct(private DoctorRecommender $recommender, private WaitEstimator $waits) {}

    /**
     * Active facilities matching the words typed (in the name, county or
     * sub-county), optionally only those open for remote requests right now.
     * Nothing else is ever listed: a facility still awaiting review has no
     * business appearing publicly.
     *
     * @return LengthAwarePaginator<int, Facility>
     */
    public function search(?string $words, bool $openNow): LengthAwarePaginator
    {
        return Facility::query()
            ->where('status', FacilityStatus::Active)
            ->when(filled($words), function (Builder $query) use ($words): void {
                $like = '%'.addcslashes(trim((string) $words), '%_\\').'%';

                $query->where(fn (Builder $match) => $match
                    ->where('name', 'like', $like)
                    ->orWhere('county', 'like', $like)
                    ->orWhere('sub_county', 'like', $like));
            })
            ->when($openNow, fn (Builder $query) => $query->openForRemoteQueue())
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    public function card(Facility $facility): PublicFacilityCard
    {
        $departments = $facility->departments()->where('is_active', true)->orderBy('id')->get();

        return new PublicFacilityCard(
            name: $facility->name,
            slug: $facility->slug,
            county: $facility->county,
            subCounty: $facility->sub_county,
            services: $this->serviceNames($departments),
            remoteQueue: $facility->remoteQueueAvailability(),
            waitLabel: $this->waitLabel($facility, $departments),
            doctorsOnDuty: User::query()
                ->where('facility_id', $facility->id)
                ->where('role', UserRole::Doctor)
                ->where('status', UserStatus::Active)
                ->where('is_on_duty', true)
                ->count(),
            selfCheckin: $facility->self_checkin_enabled,
        );
    }

    /**
     * What a patient can be seen for: the services of the departments that give
     * each patient a doctor, else the consultation department's own name.
     *
     * @param  Collection<int, Department>  $departments
     * @return list<string>
     */
    private function serviceNames($departments): array
    {
        $assigning = $departments->where('requires_doctor_assignment', true);

        $services = Service::query()->whereIn('department_id', $assigning->pluck('id'))->orderBy('name')->pluck('name')->unique()->take(5)->values()->all();

        if ($services !== []) {
            return $services;
        }

        $consultation = $assigning->first() ?? $departments->firstWhere('type', DepartmentType::Consultation);

        return $consultation === null ? [] : [$consultation->name];
    }

    /**
     * The shortest wait a patient joining today could expect, from the
     * same numbers a receptionist is shown: the shortest doctor's line where
     * patients are assigned to doctors, else the consultation queue's. Null when
     * there is nothing to measure (no doctor on duty where patients are assigned
     * to doctors, or no consultation department).
     *
     * @param  Collection<int, Department>  $departments
     */
    private function waitLabel(Facility $facility, $departments): ?string
    {
        $assigning = $departments->where('requires_doctor_assignment', true);

        if ($assigning->isNotEmpty()) {
            $best = null;

            foreach ($assigning as $department) {
                $first = $this->recommender->rank($department->id, null, $facility->id)[0] ?? null;

                if ($first !== null && ($best === null || $first->estimate->lowMinutes < $best->lowMinutes)) {
                    $best = $first->estimate;
                }
            }

            return $best === null ? null : $this->label($best);
        }

        $consultation = $departments->firstWhere('type', DepartmentType::Consultation);

        return $consultation === null ? null : $this->label($this->waits->estimateForDepartmentQueue($consultation->id));
    }

    /**
     * A queue this short is no wait worth quoting.
     */
    private function label(WaitEstimate $estimate): string
    {
        return $estimate->highMinutes <= 5 ? 'No wait' : $estimate->label();
    }
}
