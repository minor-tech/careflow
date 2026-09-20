<?php

namespace App\Http\Controllers;

use App\Enums\VisitStatus;
use App\Exceptions\InvalidDepartmentTransfer;
use App\Exceptions\InvalidVisitTransition;
use App\Models\Department;
use App\Models\Visit;
use App\Services\VisitStatusTransitioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * The working queue for one department: call, start and complete patients.
 * Staff see their own department; an admin can switch between departments.
 */
class QueueController extends Controller
{
    /**
     * The statuses that belong on the working queue.
     *
     * @var list<VisitStatus>
     */
    private const OPEN = [VisitStatus::Waiting, VisitStatus::Called, VisitStatus::InService];

    /**
     * Key of the tab for visits that have no department at all.
     */
    private const NO_DEPARTMENT = 'none';

    public function index(Request $request): View
    {
        $user = $request->user();
        $facility = $this->facility($request);
        $departments = $facility->departments()->orderBy('id')->get();

        $tabs = $user->isAdmin() ? $this->tabsFor($facility->id, $departments) : [];

        $activeKey = $user->isAdmin()
            ? $this->activeTab($request, $tabs, $user->department_id)
            : $user->department_id;

        $visits = $activeKey === null ? collect() : $this->queueFor($facility->id, $activeKey);

        // Polling asks for just the part of the page that changes.
        return view($request->ajax() ? 'queue._board' : 'queue.index', [
            'department' => $departments->firstWhere('id', $activeKey),
            'isUnassignedTab' => $activeKey === self::NO_DEPARTMENT,
            'hasQueue' => $activeKey !== null,
            'tabs' => $tabs,
            'activeKey' => $activeKey === null ? null : (string) $activeKey,
            'visits' => $visits,
            'counts' => [
                'waiting' => $visits->where('status', VisitStatus::Waiting)->count(),
                'called' => $visits->where('status', VisitStatus::Called)->count(),
                'in_service' => $visits->where('status', VisitStatus::InService)->count(),
            ],
            // Where a patient being served can be sent on to (the row leaves out its own department).
            'transferTargets' => $departments->where('is_active', true)->map(fn (Department $department) => [
                'id' => $department->id,
                'name' => $department->name,
                'prefix' => $department->type->prefix(),
            ])->values()->all(),
            'canRegister' => $user->isAdmin() || $user->isReceptionist(),
        ]);
    }

    public function call(Request $request, Visit $visit, VisitStatusTransitioner $transitioner): RedirectResponse
    {
        return $this->move($request, $visit, VisitStatus::Called, $transitioner);
    }

    public function start(Request $request, Visit $visit, VisitStatusTransitioner $transitioner): RedirectResponse
    {
        return $this->move($request, $visit, VisitStatus::InService, $transitioner);
    }

    public function complete(Request $request, Visit $visit, VisitStatusTransitioner $transitioner): RedirectResponse
    {
        return $this->move($request, $visit, VisitStatus::Completed, $transitioner);
    }

    public function cancel(Request $request, Visit $visit, VisitStatusTransitioner $transitioner): RedirectResponse
    {
        return $this->move($request, $visit, VisitStatus::Cancelled, $transitioner);
    }

    /**
     * Send a patient who has been seen on to another department, where they
     * join the queue as waiting.
     */
    public function transfer(Request $request, Visit $visit, Department $department, VisitStatusTransitioner $transitioner): RedirectResponse
    {
        Gate::authorize('transfer', [$visit, $department]);

        $back = $this->backToQueue($request, $visit);

        try {
            $moved = $transitioner->transferToDepartment($visit, $department, $request->user());
        } catch (InvalidVisitTransition|InvalidDepartmentTransfer $exception) {
            return $back->withErrors(['queue' => $exception->getMessage()]);
        }

        $moved->load(['patient:id,name', 'department:id,type']);

        return $back->with('success', "{$moved->patient->name} is now waiting in {$department->name} as {$moved->queueLabel()}.");
    }

    /**
     * Today's open visits in one department (or with none), the ones needing
     * attention first: in service, then called, then waiting. Within each,
     * whoever joined this department's queue first goes first (a patient sent
     * on from another department joins at the back, whatever their
     * registration number), and the registration number breaks a tie.
     *
     * @return Collection<int, Visit>
     */
    private function queueFor(int $facilityId, string|int $departmentKey): Collection
    {
        $priority = fn (Visit $visit): int => match ($visit->status) {
            VisitStatus::InService => 0,
            VisitStatus::Called => 1,
            default => 2,
        };

        $order = fn (Visit $visit): array => [$priority($visit), $visit->joinedQueueAt()->getTimestamp(), $visit->queue_number, $visit->id];

        return Visit::query()
            ->where('facility_id', $facilityId)
            ->registeredToday()
            ->whereIn('status', self::OPEN)
            ->when(
                $departmentKey === self::NO_DEPARTMENT,
                fn ($query) => $query->whereNull('department_id'),
                fn ($query) => $query->where('department_id', $departmentKey),
            )
            ->with(['patient:id,name', 'department:id,type'])
            ->get()
            ->sort(fn (Visit $a, Visit $b) => $order($a) <=> $order($b))
            ->values();
    }

    /**
     * The department tabs an admin can switch between, each with how many are
     * waiting. Inactive departments only show while they still have people in
     * the queue, and visits with no department get a tab of their own so
     * nobody is ever invisible.
     *
     * @param  Collection<int, Department>  $departments
     * @return list<array{key: string, name: string, waiting: int}>
     */
    private function tabsFor(int $facilityId, Collection $departments): array
    {
        $openToday = Visit::query()
            ->where('facility_id', $facilityId)
            ->registeredToday()
            ->whereIn('status', self::OPEN)
            ->selectRaw('department_id, status, count(*) as total')
            ->groupBy('department_id', 'status')
            ->get()
            ->groupBy(fn (Visit $row) => $row->department_id ?? self::NO_DEPARTMENT);

        $waiting = fn (string|int $key): int => (int) $openToday->get($key, collect())->where('status', VisitStatus::Waiting)->sum('total');

        $tabs = [];

        foreach ($departments as $department) {
            if ($department->is_active || $openToday->has($department->id)) {
                $tabs[] = ['key' => (string) $department->id, 'name' => $department->name, 'waiting' => $waiting($department->id)];
            }
        }

        if ($openToday->has(self::NO_DEPARTMENT)) {
            $tabs[] = ['key' => self::NO_DEPARTMENT, 'name' => 'No department', 'waiting' => $waiting(self::NO_DEPARTMENT)];
        }

        return $tabs;
    }

    /**
     * The tab asked for, else the admin's own department, else the first.
     *
     * @param  list<array{key: string, name: string, waiting: int}>  $tabs
     */
    private function activeTab(Request $request, array $tabs, ?int $ownDepartmentId): ?string
    {
        $keys = array_column($tabs, 'key');

        foreach ([(string) $request->query('department'), (string) $ownDepartmentId] as $candidate) {
            if (in_array($candidate, $keys, true)) {
                return $candidate;
            }
        }

        return $keys[0] ?? null;
    }

    private function move(Request $request, Visit $visit, VisitStatus $to, VisitStatusTransitioner $transitioner): RedirectResponse
    {
        Gate::authorize('updateQueue', $visit);

        $back = $this->backToQueue($request, $visit);

        try {
            $transitioner->transition($visit, $to, $request->user());
        } catch (InvalidVisitTransition $exception) {
            return $back->withErrors(['queue' => $exception->getMessage()]);
        }

        return $back;
    }

    /**
     * Back to the queue, and for an admin to the department tab they were
     * working in (which, after a transfer, is the one the patient just left).
     * Call it before the visit is changed.
     */
    private function backToQueue(Request $request, Visit $visit): RedirectResponse
    {
        return redirect()->route('queue.index', $request->user()->isAdmin()
            ? ['department' => $visit->department_id ?? self::NO_DEPARTMENT]
            : []);
    }
}
