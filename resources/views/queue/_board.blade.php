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
        @if ($canRegister)
            <a href="{{ route('patients.register') }}" class="btn-outline btn-sm mt-3">Register patient</a>
        @endif
    </div>

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
            @endphp
            <div class="cf-list-row">
                <span class="cf-dot cf-dot--{{ $visit->status->tone() }}" aria-hidden="true"></span>
                <span class="w-20 shrink-0 text-xl font-semibold tabular-nums">{{ $label }}</span>
                <div class="cf-list-row__main">
                    <p class="truncate font-medium">{{ $visit->patient->name }}</p>
                    <p class="text-sm text-ink/70">
                        <x-status-pill :label="$visit->status->label()" :tone="$visit->status->tone()" size="sm" />
                        @unless ($inService)
                            <span class="ml-1 tabular-nums">{{ $waited }} min</span>
                        @endunless
                    </p>
                </div>

                <div class="cf-list-row__side">
                    @if ($inService)
                        {{-- Neither next step is the obvious default (a doctor usually sends on, a pharmacist usually completes), so neither is filled. --}}
                        @if ($sendTo->isNotEmpty())
                            <details class="relative">
                                <summary class="btn-outline btn-sm list-none [&::-webkit-details-marker]:hidden">Send to&hellip;</summary>
                                <div class="absolute right-0 z-10 mt-2 w-64 rounded-2xl border border-line bg-white p-1 shadow-card-hover">
                                    @foreach ($sendTo as $target)
                                        <form method="POST" action="{{ route('queue.transfer', [$visit, $target['id']]) }}"
                                              onsubmit="return confirm('Send {{ e(addslashes($visit->patient->name)) }} to {{ e(addslashes($target['name'])) }}? They will join its queue as waiting.')">
                                            @csrf
                                            <button type="submit" class="flex min-h-11 w-full items-center justify-between gap-4 rounded px-3 text-left text-sm hover:bg-tint-mint-soft">
                                                <span>{{ $target['name'] }}</span>
                                                <span class="text-ink/60">{{ $target['prefix'] }}</span>
                                            </button>
                                        </form>
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
            </div>
        @empty
            <p class="cf-list-card__empty">Nobody is waiting right now.</p>
        @endforelse
    </div>
@endif
