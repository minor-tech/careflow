<?php

namespace App\Actions;

use App\Enums\RemoteRequestStatus;
use App\Events\RemoteRequestAccepted;
use App\Exceptions\RemoteRequestRefused;
use App\Models\RemoteRequest;
use App\Models\User;
use App\Models\Visit;
use App\Services\ArrivalPlanner;
use App\Services\DoctorRecommender;
use App\Services\RemoteRequestRouting;
use App\Services\WaitEstimator;
use App\Support\DoctorOption;
use App\Support\WaitEstimate;
use Illuminate\Support\Facades\DB;

class AcceptRemoteRequest
{
    public function __construct(
        private RegisterPatientVisit $register,
        private RemoteRequestRouting $routing,
        private DoctorRecommender $recommender,
        private WaitEstimator $waits,
        private ArrivalPlanner $planner,
    ) {}

    /**
     * Turn a request into a real visit, in the same queue and with the same
     * numbers as any other. A department that gives each patient their own
     * doctor needs the one staff chose (the recommender only ranks): the visit
     * takes a genuine place at the back of that doctor's line at once.
     *
     * From home, the visit is awaiting arrival: it holds that place, but the
     * patient is not in the building until staff check them in, and they are
     * texted the doctor, a time to arrive and their tracking link. A self
     * check-in is different: staff are accepting it with the person standing in
     * front of them, so the visit is waiting at once, exactly as if reception
     * had registered them, and they get the ordinary registration text.
     *
     * Works on the locked, re-read request, so two people accepting at once
     * can't both create a visit.
     *
     * @throws RemoteRequestRefused
     */
    public function handle(RemoteRequest $request, ?User $doctor, User $actor): Visit
    {
        return DB::transaction(function () use ($request, $doctor, $actor): Visit {
            $current = RemoteRequest::whereKey($request->getKey())->lockForUpdate()->with('facility')->firstOrFail();

            if ($current->status !== RemoteRequestStatus::Pending) {
                throw RemoteRequestRefused::alreadyReviewed();
            }

            $department = $this->routing->departmentFor($current) ?? throw RemoteRequestRefused::notOffered();
            $atHome = ! $current->isSelfCheckin();

            $option = null;

            if ($department->requires_doctor_assignment) {
                $option = $this->chosenOption($current, $department->id, $doctor);
            }

            // The wait for the place they are about to take: worked out before they take it.
            $untilCalled = $atHome ? $this->estimate($department->id, $option) : null;

            $visit = $this->register->handle(
                $actor,
                [
                    'phone' => $current->phone,
                    'name' => $current->name,
                    'department_id' => $department->id,
                    'service_id' => $current->service_id,
                    'doctor_id' => $option?->doctor->id,
                ],
                source: $current->source->visitSource(),
                awaitingArrival: $atHome,
                announce: ! $atHome,
            );

            $window = $untilCalled === null ? null : $this->planner->windowFor($untilCalled, $current->requested_arrival);

            $current->update([
                'status' => RemoteRequestStatus::Accepted,
                'visit_id' => $visit->id,
                'recommended_arrival_from' => $window?->from,
                'recommended_arrival_until' => $window?->until,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
            ]);

            if ($atHome) {
                RemoteRequestAccepted::dispatch($current);
            }

            return $visit;
        });
    }

    /**
     * The doctor staff picked, as long as they fit the service and are on duty.
     */
    private function chosenOption(RemoteRequest $request, int $departmentId, ?User $doctor): DoctorOption
    {
        $options = $this->recommender->rank($departmentId, $request->service_id, $request->facility_id);

        if ($options === []) {
            throw RemoteRequestRefused::noDoctorOnDuty();
        }

        if ($doctor === null) {
            throw RemoteRequestRefused::needsDoctor();
        }

        foreach ($options as $option) {
            if ($option->doctor->is($doctor)) {
                return $option;
            }
        }

        throw RemoteRequestRefused::doctorNotAvailable($doctor);
    }

    private function estimate(int $departmentId, ?DoctorOption $option): WaitEstimate
    {
        return $option?->estimate ?? $this->waits->estimateForDepartmentQueue($departmentId);
    }
}
