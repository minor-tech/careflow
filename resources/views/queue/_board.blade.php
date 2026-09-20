@if (! $hasQueue)
    <h1 class="text-2xl font-semibold">Queue</h1>
    <p class="mt-4 max-w-prose text-ink/70">
        @if (auth()->user()->isAdmin())
            There are no departments with a queue yet. Add one under Departments to get started.
        @else
            You aren't assigned to a department yet. Ask your facility admin to assign you to one, and your queue will appear here.
        @endif
    </p>
@else
    @if (count($tabs) > 0)
        <nav aria-label="Department queues" class="-mx-1 mb-6 flex gap-1 overflow-x-auto pb-1">
            @foreach ($tabs as $tab)
                @php
                    $isActive = $tab['key'] === $activeKey;
                @endphp
                <a href="{{ route('queue.index', ['department' => $tab['key']]) }}"
                   @if ($isActive) aria-current="page" @endif
                   class="flex min-h-11 shrink-0 items-center gap-2 rounded-md px-3 text-sm font-medium {{ $isActive ? 'bg-tint-mint text-primary' : 'text-ink hover:bg-tint-mint-soft' }}">
                    {{ $tab['name'] }}
                    <span class="tabular-nums {{ $isActive ? '' : 'text-ink/60' }}" aria-label="{{ $tab['waiting'] }} waiting">{{ $tab['waiting'] }}</span>
                </a>
            @endforeach
        </nav>
    @endif

    <div>
        <h1 class="text-2xl font-semibold">{{ $department?->name ?? 'No department' }}</h1>
        @if ($ownLineOf)
            <p class="mt-1 text-sm text-ink/70">Your patients only. Each patient here was assigned to you at registration.</p>
        @endif
        <div class="mt-3 flex flex-wrap items-center gap-3">
            @if ($canRegister)
                <a href="{{ route('patients.register') }}" class="btn-outline btn-sm">Register patient</a>
            @endif

            {{-- Patients are only ever assigned to a doctor who has said they are working. --}}
            @if ($onDuty !== null && $assignsDoctors)
                <form method="POST" action="{{ route('staff.duty', auth()->user()) }}" class="flex flex-wrap items-center gap-3">
                    @csrf
                    <input type="hidden" name="on_duty" value="{{ $onDuty ? 0 : 1 }}">
                    <x-status-pill :label="$onDuty ? 'You are on duty' : 'You are off duty'" :tone="$onDuty ? 'ok' : 'muted'" size="sm" />
                    <x-outline-button type="submit" class="btn-sm">{{ $onDuty ? 'Go off duty' : 'Go on duty' }}</x-outline-button>
                </form>
                @unless ($onDuty)
                    <p class="text-sm text-ink/70">Reception can't assign you new patients while you are off duty.</p>
                @endunless
            @endif
        </div>
    </div>

    @if ($handlesArrivals && $pendingRequests > 0)
        <a href="{{ route('remote-requests.index') }}" class="mt-6 flex min-h-11 items-center justify-between gap-4 rounded-2xl border-l-4 border-info bg-white px-4 py-3 text-sm shadow-card hover:bg-tint-mint-soft">
            <span><span class="font-semibold tabular-nums">{{ $pendingRequests }}</span> {{ $pendingRequests === 1 ? 'queue request is' : 'queue requests are' }} waiting for review</span>
            <span class="font-medium text-primary">Review &rarr;</span>
        </a>
    @endif

    {{-- Everyone else accepted from home who isn't here yet, in whichever department, so the front desk can check them in. Those who say they've arrived come first. --}}
    @if ($handlesArrivals && $expected->isNotEmpty())
        <section class="mt-6" aria-labelledby="expected-heading">
            <h2 id="expected-heading" class="text-base font-semibold">On their way <span class="tabular-nums text-ink/60">({{ $expected->count() }})</span></h2>
            <div class="cf-list-card cf-list-card--open mt-2">
                @foreach ($expected as $coming)
                    <div class="cf-list-row">
                        <span class="cf-dot cf-dot--info" aria-hidden="true"></span>
                        <span class="w-20 shrink-0 text-xl font-semibold tabular-nums">{{ $coming->queueLabel() }}</span>
                        <div class="cf-list-row__main">
                            <p class="truncate font-medium">{{ $coming->patient->name }}
                                <span class="font-normal text-ink/70">&mdash; {{ $coming->assignedDoctor?->doctorName() ?? $coming->department?->name }}</span>
                            </p>
                            <p class="text-sm text-ink/70">
                                @if ($coming->arrival_signaled_at)
                                    <x-status-pill label="Says they've arrived" tone="info" size="sm" />
                                    <span class="ml-1">Awaiting check-in</span>
                                @else
                                    <x-status-pill label="Not here yet" tone="muted" size="sm" />
                                @endif
                            </p>
                        </div>
                        <div class="cf-list-row__side">
                            <form method="POST" action="{{ route('queue.check-in', $coming) }}" onsubmit="this.querySelector('button[type=submit]').disabled = true">
                                @csrf
                                @if ($coming->arrival_signaled_at)
                                    <x-primary-button class="btn-sm">Check In</x-primary-button>
                                @else
                                    <x-outline-button type="submit" class="btn-sm">Check In</x-outline-button>
                                @endif
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    <div class="mt-6 grid grid-cols-3 gap-2 sm:gap-4">
        <x-stat-card :number="$counts['waiting']" label="Waiting" class="cf-stat-card--stacked" icon="clock" tone="gold" />
        <x-stat-card :number="$counts['called']" label="Called" class="cf-stat-card--stacked" icon="megaphone" tone="blue" />
        <x-stat-card :number="$counts['in_service']" label="In service" class="cf-stat-card--stacked" icon="activity" tone="sage" />
    </div>

    {{-- The "open" list card does not clip: the "Send to…" menu has to be able to hang below the last row. --}}
    <div class="cf-list-card cf-list-card--open mt-6">
        @forelse ($visits as $visit)
            @php
                $inService = $visit->status === \App\Enums\VisitStatus::InService;
                $label = $visit->queueLabel();
                $waited = (int) $visit->joinedQueueAt()->diffInMinutes(now());
                $sendTo = collect($transferTargets)->where('id', '!=', $visit->department_id)->values();
                // Who else in this department could take the patient (the one they have now is left out).
                $otherDoctors = collect($doctorsByDepartment[$visit->department_id] ?? [])->where('id', '!=', $visit->assigned_doctor_id)->values();
                $isAwaiting = $visit->isAwaitingArrival();
                $canHandOver = $assignsDoctors && ! $inService && ! $isAwaiting && auth()->user()->can('reassignDoctor', $visit);
                $isNext = in_array($visit->id, $frontIds, true);
                // Minutes of the no-show grace period left, counted from when staff were first shown them as next.
                $graceLeft = $isAwaiting && $visit->arrival_grace_started_at
                    ? max(0, $graceMinutes - (int) $visit->arrival_grace_started_at->diffInMinutes(now()))
                    : $graceMinutes;
            @endphp
            <div class="cf-list-row">
                <span class="cf-dot cf-dot--{{ $visit->status->tone() }}" aria-hidden="true"></span>
                <span class="w-20 shrink-0 text-xl font-semibold tabular-nums">{{ $label }}</span>
                <div class="cf-list-row__main">
                    <p class="truncate font-medium">{{ $visit->patient->name }}</p>
                    <p class="text-sm text-ink/70">
                        <x-status-pill :label="$visit->status->label()" :tone="$visit->status->tone()" size="sm" />
                        @unless ($inService || $isAwaiting)
                            {{-- Waiting since they came in, not since they were given a place: a patient accepted from home has been at home. --}}
                            <span class="ml-1 tabular-nums">{{ $visit->arrived_at ? (int) $visit->arrived_at->diffInMinutes(now()) : $waited }} min</span>
                        @endunless
                        @if ($assignsDoctors && ! $ownLineOf)
                            <span class="ml-1">&middot; {{ $visit->assignedDoctor?->doctorName() ?? 'No doctor yet' }}</span>
                        @endif
                    </p>
                </div>

                @if ($isAwaiting)
                    <div class="cf-list-row__side">
                        @if ($visit->arrival_signaled_at)
                            <p class="w-full text-sm"><x-status-pill label="Says they've arrived" tone="info" size="sm" /> Awaiting check-in</p>
                        @elseif ($isNext)
                            {{-- Next to be called and not here: staff decide, the queue is never silently held up. --}}
                            <p class="w-full rounded-xl bg-tint-light px-3 py-2 text-sm" role="status">
                                <span class="font-medium">Not yet checked in</span>
                                &mdash;
                                @if ($graceLeft > 0)
                                    grace period: <span class="tabular-nums">{{ $graceLeft }} min</span> remaining
                                @else
                                    grace period is over
                                @endif
                            </p>
                        @endif

                        <form method="POST" action="{{ route('queue.check-in', $visit) }}" onsubmit="this.querySelector('button[type=submit]').disabled = true">
                            @csrf
                            @if ($visit->arrival_signaled_at || ($isNext && $graceLeft === 0))
                                <x-primary-button class="btn-sm">Check In</x-primary-button>
                            @else
                                <x-outline-button type="submit" class="btn-sm">Check In</x-outline-button>
                            @endif
                        </form>

                        @if ($isNext && ! $visit->arrival_signaled_at)
                            <form method="POST" action="{{ route('queue.wait', $visit) }}">
                                @csrf
                                <x-outline-button type="submit" class="btn-sm">Wait</x-outline-button>
                            </form>
                            <form method="POST" action="{{ route('queue.skip', $visit) }}"
                                  onsubmit="return confirm('Let the next patient go first? {{ e(addslashes($visit->patient->name)) }} moves behind them and can still check in.')">
                                @csrf
                                <x-outline-button type="submit" class="btn-sm">Skip</x-outline-button>
                            </form>
                        @endif

                        <form method="POST" action="{{ route('queue.cancel', $visit) }}"
                              onsubmit="return confirm('Cancel the request for {{ $label }} ({{ e(addslashes($visit->patient->name)) }})? Use this for patients who did not come.')">
                            @csrf
                            <button type="submit" class="btn-danger btn-sm">Cancel request</button>
                        </form>
                    </div>
                @else
                <div class="cf-list-row__side">
                    @if ($canHandOver)
                        <details class="relative">
                            <summary class="btn-outline btn-sm list-none [&::-webkit-details-marker]:hidden">{{ $visit->assigned_doctor_id === null ? 'Assign doctor' : 'Change doctor' }}</summary>
                            <form method="POST" action="{{ route('queue.reassign-doctor', $visit) }}"
                                  class="absolute right-0 z-10 mt-2 w-72 space-y-3 rounded-2xl border border-line bg-white p-4 shadow-card-hover">
                                @csrf
                                @if ($otherDoctors->isEmpty())
                                    <p class="text-sm text-ink/70">No other doctor is on duty in {{ $department?->name }} right now.</p>
                                @else
                                    <div>
                                        <label for="handover-doctor-{{ $visit->id }}" class="block text-sm font-medium">Hand over to</label>
                                        <select id="handover-doctor-{{ $visit->id }}" name="doctor_id" class="field-input mt-1" required>
                                            <option value="">Choose a doctor</option>
                                            @foreach ($otherDoctors as $doctor)
                                                <option value="{{ $doctor['id'] }}">{{ $doctor['name'] }} &middot; {{ $doctor['waiting'] }} waiting</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label for="handover-reason-{{ $visit->id }}" class="block text-sm font-medium">Why?</label>
                                        <input id="handover-reason-{{ $visit->id }}" name="reason" type="text" maxlength="255" required autocomplete="off" placeholder="e.g. Called to an emergency" class="field-input mt-1">
                                        <p class="mt-1 text-sm text-ink/60">Kept on the record. The patient joins the back of the new doctor's line and is told by SMS.</p>
                                    </div>
                                    <button type="submit" class="btn-primary btn-sm">Hand over</button>
                                @endif
                            </form>
                        </details>
                    @endif

                    @if ($canResetPin)
                        {{-- The old PIN stops working the moment this is confirmed, so it asks first. --}}
                        <form method="POST" action="{{ route('queue.reset-pin', $visit) }}"
                              onsubmit="return confirm('Reset the access PIN for {{ $visit->queueCode() }} ({{ e(addslashes($visit->patient->name)) }})? Their old PIN will stop working straight away.')">
                            @csrf
                            <x-outline-button type="submit" class="btn-sm">Reset PIN</x-outline-button>
                        </form>
                    @endif

                    @if ($inService)
                        {{-- Neither next step is the obvious default (a doctor usually sends on, a pharmacist usually completes), so neither is filled. --}}
                        @if ($sendTo->isNotEmpty())
                            <details class="relative">
                                <summary class="btn-outline btn-sm list-none [&::-webkit-details-marker]:hidden">Send to&hellip;</summary>
                                <div class="absolute right-0 z-10 mt-2 w-64 rounded-2xl border border-line bg-white p-1 shadow-card-hover">
                                    @foreach ($sendTo as $target)
                                        @if ($target['requires_doctor'])
                                            {{-- Their own doctor, so the choice of doctor is part of the choice of department. --}}
                                            <p class="px-3 pb-1 pt-2 text-xs font-medium uppercase tracking-wide text-ink/60">{{ $target['name'] }}</p>
                                            @forelse ($target['doctors'] as $doctor)
                                                <form method="POST" action="{{ route('queue.transfer', [$visit, $target['id']]) }}"
                                                      onsubmit="return confirm('Send {{ e(addslashes($visit->patient->name)) }} to {{ e(addslashes($doctor['name'])) }} in {{ e(addslashes($target['name'])) }}? They will join that doctor\'s queue as waiting.')">
                                                    @csrf
                                                    <input type="hidden" name="doctor_id" value="{{ $doctor['id'] }}">
                                                    <button type="submit" class="flex min-h-11 w-full items-center justify-between gap-4 rounded px-3 text-left text-sm hover:bg-tint-mint-soft">
                                                        <span>{{ $doctor['name'] }}@if ($doctor['recommended']) <span class="text-ink/60">&middot; shortest line</span>@endif</span>
                                                        <span class="text-ink/60">{{ $doctor['waiting'] }} waiting</span>
                                                    </button>
                                                </form>
                                            @empty
                                                <p class="px-3 pb-2 text-sm text-ink/60">No doctor is on duty.</p>
                                            @endforelse
                                        @else
                                            <form method="POST" action="{{ route('queue.transfer', [$visit, $target['id']]) }}"
                                                  onsubmit="return confirm('Send {{ e(addslashes($visit->patient->name)) }} to {{ e(addslashes($target['name'])) }}? They will join its queue as waiting.')">
                                                @csrf
                                                <button type="submit" class="flex min-h-11 w-full items-center justify-between gap-4 rounded px-3 text-left text-sm hover:bg-tint-mint-soft">
                                                    <span>{{ $target['name'] }}</span>
                                                    <span class="text-ink/60">{{ $target['prefix'] }}</span>
                                                </button>
                                            </form>
                                        @endif
                                    @endforeach
                                </div>
                            </details>
                        @endif

                        <form method="POST" action="{{ route('queue.complete', $visit) }}"
                              onsubmit="this.querySelector('button[type=submit]').disabled = true">
                            @csrf
                            <x-outline-button type="submit" class="btn-sm">Complete visit</x-outline-button>
                        </form>
                    @else
                        @php
                            // The one thing to do next for this patient.
                            [$route, $actionLabel] = $visit->status === \App\Enums\VisitStatus::Waiting
                                ? ['queue.call', 'Call']
                                : ['queue.start', 'Start'];
                        @endphp

                        <form method="POST" action="{{ route('queue.cancel', $visit) }}"
                              onsubmit="return confirm('Cancel {{ $label }} ({{ e(addslashes($visit->patient->name)) }})? Use this for patients who did not come.')">
                            @csrf
                            <button type="submit" class="btn-danger btn-sm">Cancel</button>
                        </form>

                        <form method="POST" action="{{ route($route, $visit) }}"
                              onsubmit="this.querySelector('button[type=submit]').disabled = true">
                            @csrf
                            <x-primary-button class="btn-sm min-w-24">{{ $actionLabel }}</x-primary-button>
                        </form>
                    @endif
                </div>
                @endif
            </div>
        @empty
            <p class="cf-list-card__empty">Nobody is waiting right now.</p>
        @endforelse
    </div>
@endif
