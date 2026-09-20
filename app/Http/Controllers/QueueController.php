<?php

namespace App\Http\Controllers;

use App\Actions\ReassignVisitDoctor;
use App\Actions\ResetVisitAccessPin;
use App\Enums\VisitStatus;
use App\Exceptions\InvalidArrivalAction;
use App\Exceptions\InvalidDepartmentTransfer;
use App\Exceptions\InvalidDoctorReassignment;
use App\Exceptions\InvalidVisitTransition;
use App\Models\Department;
use App\Models\User;
use App\Models\Visit;
use App\Services\DoctorRecommender;
use App\Services\RemoteArrival;
use App\Services\VisitStatusTransitioner;
use App\Support\DoctorOption;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;

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
    private const OPEN = [VisitStatus::AwaitingArrival, VisitStatus::Waiting, VisitStatus::Called, VisitStatus::InService];

    /**
     * Key of the tab for visits that have no department at all.
     */
    private const NO_DEPARTMENT = 'none';

    public function index(Request $request, DoctorRecommender $recommender): Response
    {
        $user = $request->user();
        $facility = $this->facility($request);
        $departments = $facility->departments()->orderBy('id')->get();

        $tabs = $user->isAdmin() ? $this->tabsFor($facility->id, $departments) : [];

        $activeKey = $user->isAdmin()
            ? $this->activeTab($request, $tabs, $user->department_id)
            : $user->department_id;

        $department = $departments->firstWhere('id', $activeKey);

        // Where patients are assigned to doctors, a doctor's board is their own line, not the department's.
        $ownLineOf = $user->isDoctor() && $department?->requires_doctor_assignment ? $user->id : null;

        $visits = $activeKey === null ? collect() : $this->queueFor($facility->id, $activeKey, $ownLineOf);

        // Whoever is next in each line and hasn't come yet needs a decision, and the clock on it starts when staff are first shown it.
        $frontIds = $this->markNextInLine($visits);

        // The front desk (and the admin) check people in whichever department they were accepted into, so they see everyone expected.
        $handlesArrivals = $user->isAdmin() || $user->isReceptionist();

        // Doctors on duty in each department that assigns patients, for choosing who to send someone to or hand them to.
        $doctorsByDepartment = $departments
            ->where('is_active', true)
            ->where('requires_doctor_assignment', true)
            ->mapWithKeys(fn (Department $assigning) => [
                $assigning->id => array_map(fn (DoctorOption $option) => $option->toArray(), $recommender->rank($assigning->id, null, $facility->id)),
            ]);

        $revealedPin = $this->revealedPin($request);

        // Polling asks for just the part of the page that changes.
        $response = response()->view($request->ajax() ? 'queue._board' : 'queue.index', [
            'department' => $department,
            'assignsDoctors' => $department?->requires_doctor_assignment === true,
            'ownLineOf' => $ownLineOf,
            'doctorsByDepartment' => $doctorsByDepartment->all(),
            'onDuty' => $user->isDoctor() ? $user->isOnDuty() : null,
            'isUnassignedTab' => $activeKey === self::NO_DEPARTMENT,
            'hasQueue' => $activeKey !== null,
            'tabs' => $tabs,
            'activeKey' => $activeKey === null ? null : (string) $activeKey,
            'visits' => $visits,
            'counts' => [
                'waiting' => $visits->where('status', VisitStatus::Waiting)->count(),
                'awaiting' => $visits->where('status', VisitStatus::AwaitingArrival)->count(),
                'called' => $visits->where('status', VisitStatus::Called)->count(),
                'in_service' => $visits->where('status', VisitStatus::InService)->count(),
            ],
            // Where a patient being served can be sent on to (the row leaves out its own department).
            'transferTargets' => $departments->where('is_active', true)->map(fn (Department $target) => [
                'id' => $target->id,
                'name' => $target->name,
                'prefix' => $target->type->prefix(),
                'requires_doctor' => $target->requires_doctor_assignment,
                'doctors' => $doctorsByDepartment->get($target->id, []),
            ])->values()->all(),
            'canRegister' => $user->isAdmin() || $user->isReceptionist(),
            // A receptionist only ever sees their own department's rows, so the role is all the board needs to know.
            'canResetPin' => $user->isAdmin() || $user->isReceptionist(),
            'frontIds' => $frontIds,
            'graceMinutes' => (int) $facility->remote_queue_grace_minutes,
            'handlesArrivals' => $handlesArrivals,
            // Those already in the list below are left out of this panel: it is for finding the ones who aren't on this screen.
            'expected' => $handlesArrivals ? $this->expectedArrivals($facility->id)->reject(fn (Visit $coming) => $visits->contains('id', $coming->id))->values() : collect(),
            'pendingRequests' => $handlesArrivals ? $facility->remoteRequests()->pending()->count() : 0,
            'revealedPin' => $revealedPin,
        ]);

        // A page showing a PIN must not be kept by the browser or a proxy.
        return $revealedPin === null ? $response : $response->header('Cache-Control', 'no-store, private');
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
     * join the queue as waiting. A department that gives each patient their
     * own doctor also needs the doctor to send them to.
     */
    public function transfer(Request $request, Visit $visit, Department $department, VisitStatusTransitioner $transitioner): RedirectResponse
    {
        Gate::authorize('transfer', [$visit, $department]);

        $back = $this->backToQueue($request, $visit);

        $request->validate(['doctor_id' => ['nullable', 'integer']]);

        // Only a doctor of this facility can be chosen; whether they are on duty in the destination is the transfer's to check.
        $doctor = $request->filled('doctor_id')
            ? User::where('facility_id', $request->user()->facility_id)->find($request->integer('doctor_id'))
            : null;

        try {
            $moved = $transitioner->transferToDepartment($visit, $department, $request->user(), $doctor);
        } catch (InvalidVisitTransition|InvalidDepartmentTransfer $exception) {
            return $back->withErrors(['queue' => $exception->getMessage()]);
        }

        $moved->load(['patient:id,name', 'department:id,type']);

        $with = $moved->isInDoctorQueue() ? " with {$doctor->doctorName()}" : " in {$department->name}";

        return $back->with('success', "{$moved->patient->name} is now waiting{$with} as {$moved->queueLabel()}.");
    }

    /**
     * Hand a patient who has not been seen yet to another doctor of the same
     * department. Says why, because that goes on the record.
     */
    public function reassignDoctor(Request $request, Visit $visit, ReassignVisitDoctor $reassign): RedirectResponse
    {
        Gate::authorize('reassignDoctor', $visit);

        $back = $this->backToQueue($request, $visit);

        $validated = $request->validate([
            'doctor_id' => ['required', 'integer', 'exists:users,id'],
            'reason' => ['required', 'string', 'max:255'],
        ], [
            'doctor_id.required' => 'Choose the doctor to hand this patient to.',
            'reason.required' => 'Say why the patient is being reassigned. It is kept on the record.',
        ]);

        $doctor = User::where('facility_id', $request->user()->facility_id)->find($validated['doctor_id']);

        if ($doctor === null) {
            return $back->withErrors(['queue' => 'That doctor is not at this facility.']);
        }

        try {
            $moved = $reassign->handle($visit, $doctor, $validated['reason'], $request->user());
        } catch (InvalidDoctorReassignment $exception) {
            return $back->withErrors(['queue' => $exception->getMessage()]);
        }

        $moved->load(['patient:id,name', 'department:id,type']);

        return $back->with('success', "{$moved->patient->name} is now with {$doctor->doctorName()} as {$moved->queueLabel()}.");
    }

    /**
     * Staff confirm a patient accepted from home is physically here. This, and
     * only this, puts them in the waiting queue: their saying so is a claim.
     */
    public function checkIn(Request $request, Visit $visit, RemoteArrival $arrival): RedirectResponse
    {
        Gate::authorize('checkIn', $visit);

        $back = $this->backToQueue($request, $visit);

        try {
            $arrival->checkIn($visit, $request->user());
        } catch (InvalidArrivalAction $exception) {
            return $back->withErrors(['queue' => $exception->getMessage()]);
        }

        $visit->load('patient:id,name');

        return $back->with('success', "{$visit->patient->name} is checked in and waiting.");
    }

    /**
     * Give a patient who is next but not here more time.
     */
    public function wait(Request $request, Visit $visit, RemoteArrival $arrival): RedirectResponse
    {
        Gate::authorize('updateQueue', $visit);

        $back = $this->backToQueue($request, $visit);

        try {
            $arrival->extendGrace($visit);
        } catch (InvalidArrivalAction $exception) {
            return $back->withErrors(['queue' => $exception->getMessage()]);
        }

        return $back->with('success', 'Waiting a little longer for them.');
    }

    /**
     * Let the next person who is here go first, moving the one who isn't behind them.
     */
    public function skip(Request $request, Visit $visit, RemoteArrival $arrival): RedirectResponse
    {
        Gate::authorize('updateQueue', $visit);

        $back = $this->backToQueue($request, $visit);

        try {
            $arrival->skip($visit, $request->user());
        } catch (InvalidArrivalAction $exception) {
            return $back->withErrors(['queue' => $exception->getMessage()]);
        }

        $visit->load('patient:id,name');

        return $back->with('success', "{$visit->patient->name} was moved behind the next patient. If they arrive they can still be checked in.");
    }

    /**
     * Give a patient who has lost their access PIN a new one. It is shown to
     * staff once, on the queue they are back at: it goes in the session for the
     * next request only, and encrypted, so it is never sitting readable in the
     * session store either. The old PIN stops working straight away.
     */
    public function resetPin(Request $request, Visit $visit, ResetVisitAccessPin $resetAccessPin): RedirectResponse
    {
        Gate::authorize('resetPin', $visit);

        $back = $this->backToQueue($request, $visit);

        $pin = $resetAccessPin->handle($visit, $request->user());

        if ($pin === null) {
            return $back->withErrors(['queue' => 'That visit has ended, so its PIN no longer opens anything.']);
        }

        $visit->load('patient:id,name');

        return $back->with('reset_pin', [
            'code' => $visit->queueCode(),
            'name' => $visit->patient->name,
            'pin' => Crypt::encryptString($pin),
        ]);
    }

    /**
     * @return array{code: string, name: string, pin: string}|null
     */
    private function revealedPin(Request $request): ?array
    {
        $flashed = $request->session()->get('reset_pin');

        if (! is_array($flashed)) {
            return null;
        }

        try {
            return [...$flashed, 'pin' => Crypt::decryptString($flashed['pin'])];
        } catch (DecryptException) {
            return null;
        }
    }

    /**
     * Today's open visits in one department (or with none), the ones needing
     * attention first: in service, then called, then waiting. Within each,
     * whoever joined this department's queue first goes first (a patient sent
     * on from another department joins at the back, whatever their
     * registration number), and the registration number breaks a tie. A doctor
     * in a department that assigns patients to doctors sees only their own.
     *
     * @return Collection<int, Visit>
     */
    private function queueFor(int $facilityId, string|int $departmentKey, ?int $onlyDoctorId = null): Collection
    {
        $priority = fn (Visit $visit): int => match ($visit->status) {
            VisitStatus::InService => 0,
            VisitStatus::Called => 1,
            default => 2,
        };

        $order = fn (Visit $visit): array => [$priority($visit), $visit->joinedQueueAt()->getTimestamp(), $visit->doctor_queue_number ?? $visit->queue_number, $visit->id];

        return Visit::query()
            ->where('facility_id', $facilityId)
            ->registeredToday()
            ->whereIn('status', self::OPEN)
            ->when(
                $departmentKey === self::NO_DEPARTMENT,
                fn ($query) => $query->whereNull('department_id'),
                fn ($query) => $query->where('department_id', $departmentKey),
            )
            ->when($onlyDoctorId !== null, fn ($query) => $query->where('assigned_doctor_id', $onlyDoctorId))
            ->with(['patient:id,name', 'department:id,type', 'assignedDoctor:id,name'])
            ->get()
            ->sort(fn (Visit $a, Visit $b) => $order($a) <=> $order($b))
            ->values();
    }

    /**
     * Note, for each line (a doctor's own, or the department's shared one), the
     * first patient in it who holds a place, and where that is someone who has
     * not arrived, start their grace period if it hasn't started. Returns the
     * ids of those first patients.
     *
     * Reading the board stamps the time it first showed them, which is the only
     * moment "next in line" can be known to matter to anyone. The stamp is set
     * once and only where it is empty, so polling never moves it.
     *
     * @param  Collection<int, Visit>  $visits  In the order the queue is worked.
     * @return list<int>
     */
    private function markNextInLine(Collection $visits): array
    {
        $fronts = [];

        foreach ($visits as $visit) {
            if (! in_array($visit->status, [VisitStatus::Waiting, VisitStatus::AwaitingArrival], true)) {
                continue;
            }

            $fronts[$visit->isInDoctorQueue() ? 'doctor-'.$visit->assigned_doctor_id : 'shared'] ??= $visit;
        }

        $unstamped = collect($fronts)->filter(fn (Visit $visit) => $visit->isAwaitingArrival() && $visit->arrival_grace_started_at === null);

        if ($unstamped->isNotEmpty()) {
            $now = now();

            Visit::whereKey($unstamped->pluck('id'))->whereNull('arrival_grace_started_at')->update(['arrival_grace_started_at' => $now]);
            $unstamped->each(fn (Visit $visit) => $visit->arrival_grace_started_at = $now);
        }

        return collect($fronts)->map(fn (Visit $visit) => $visit->id)->values()->all();
    }

    /**
     * Everyone accepted from home who hasn't been checked in yet, those who say
     * they have arrived first, then in the order they hold places.
     *
     * @return Collection<int, Visit>
     */
    private function expectedArrivals(int $facilityId): Collection
    {
        return Visit::query()
            ->where('facility_id', $facilityId)
            ->registeredToday()
            ->where('status', VisitStatus::AwaitingArrival)
            ->with(['patient:id,name', 'department:id,type,name', 'assignedDoctor:id,name'])
            ->get()
            ->sort(function (Visit $a, Visit $b): int {
                $order = fn (Visit $visit): array => [$visit->arrival_signaled_at === null ? 1 : 0, $visit->arrival_signaled_at?->getTimestamp() ?? $visit->joinedQueueAt()->getTimestamp(), $visit->id];

                return $order($a) <=> $order($b);
            })
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
