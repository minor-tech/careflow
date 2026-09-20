@php
    $minutes = fn (?int $value) => $value === null ? '—' : $value.' min';

    $tiles = [
        ['label' => 'Patients registered', 'value' => $summary['patients_today'], 'icon' => 'staff', 'tone' => 'primary'],
        ['label' => 'Completed', 'value' => $summary['completed'], 'icon' => 'check', 'tone' => 'sage'],
        ['label' => 'Cancelled', 'value' => $summary['cancelled'], 'icon' => 'x', 'tone' => 'clay'],
        ['label' => 'Still in progress', 'value' => $summary['in_progress'], 'icon' => 'activity', 'tone' => 'slate'],
        ['label' => 'Average wait to be called', 'value' => $minutes($summary['avg_wait_minutes']), 'icon' => 'clock', 'tone' => 'gold'],
        ['label' => 'Longest wait to be called', 'value' => $minutes($summary['longest_wait_minutes']), 'icon' => 'hourglass', 'tone' => 'blue'],
    ];

    // With one department there is nothing to compare, so nothing is singled out.
    $flagSlowest = count($departments) > 1;
@endphp

<x-app-layout title="Analytics">
    <x-slot name="header">
        <h1 class="text-2xl font-semibold">Analytics</h1>
        <p class="mt-1 text-sm text-ink/70">Today, {{ $today->format('l j F') }}. Worked out from what staff recorded in the queue, not estimated.</p>
    </x-slot>

    <section aria-labelledby="summary-heading">
        <h2 id="summary-heading" class="text-lg font-semibold">Today so far</h2>

        <div class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($tiles as $tile)
                <x-stat-card :number="$tile['value']" :label="$tile['label']" :icon="$tile['icon']" :tone="$tile['tone']" />
            @endforeach
        </div>

        <p class="mt-3 text-sm text-ink/70">A wait is the time from registering to first being called. People still waiting for their first call aren't in these two figures yet.</p>
    </section>

    <section aria-labelledby="departments-heading" class="mt-10">
        <h2 id="departments-heading" class="text-lg font-semibold">Department performance &mdash; today</h2>
        <p class="mt-1 text-sm text-ink/70">Average time patients spent in each department, from arriving to being sent on or finished, waiting included. The slowest is first.</p>

        <div class="cf-list-card mt-3">
            @forelse ($departments as $department)
                @php
                    $isSlowest = $flagSlowest && $loop->first;
                @endphp
                <div class="cf-list-row">
                    <span class="cf-dot cf-dot--{{ $isSlowest ? 'gold' : 'primary' }}" aria-hidden="true"></span>
                    <div class="cf-list-row__main">
                        <div class="flex items-baseline justify-between gap-4">
                            <p class="min-w-0 break-words font-medium">
                                {{ $department['name'] }}
                                @if ($isSlowest)
                                    <x-status-pill label="Slowest today" tone="wait" size="sm" class="ml-2 align-middle" />
                                @endif
                            </p>
                            <p class="shrink-0"><span class="font-semibold tabular-nums">{{ $department['avg_minutes'] }} min</span> <span class="text-sm text-ink/70">avg</span></p>
                        </div>

                        {{-- Relative to the slowest department: a length to compare at a glance, not a chart. --}}
                        <div class="mt-2 h-2 rounded-full bg-line" aria-hidden="true">
                            <div class="h-2 rounded-full {{ $isSlowest ? 'bg-wait' : 'gloss-fill opacity-50' }}" style="width: {{ max(2, round($department['share_of_slowest'] * 100)) }}%"></div>
                        </div>

                        <p class="mt-1 text-sm text-ink/70">{{ $department['stays'] }} {{ $department['stays'] === 1 ? 'patient' : 'patients' }}</p>
                    </div>
                </div>
            @empty
                <p class="cf-list-card__empty">Nothing to show yet. A department appears here once a patient has been sent on from it or finished there today.</p>
            @endforelse
        </div>
    </section>

    {{-- Kept on this page, right under the department table: "long wait" complaints and the slowest department are two independent sources, and a manager can see whether they agree. --}}
    <section aria-labelledby="experience-heading" class="mt-10">
        <h2 id="experience-heading" class="text-lg font-semibold">Customer experience &mdash; last 30 days</h2>

        @if ($experience['responses'] === 0)
            <div class="cf-list-card mt-3">
                <p class="cf-list-card__empty">No feedback yet. Patients are asked to rate their visit on their tracking page once it is complete.</p>
            </div>
        @else
            <div class="mt-3 max-w-sm">
                <x-stat-card :number="number_format($experience['avg_rating'], 1)" label="Average rating" icon="star" tone="gold" />
                <p class="mt-2 text-sm text-ink/70">From {{ $experience['responses'] }} {{ $experience['responses'] === 1 ? 'response' : 'responses' }}.</p>
            </div>

            <h3 class="mt-6 font-medium">Issues raised</h3>
            <p class="mt-1 text-sm text-ink/70">From {{ $experience['low_rating_responses'] }} {{ $experience['low_rating_responses'] === 1 ? 'visit' : 'visits' }} rated 3 stars or fewer. A patient can mention more than one issue, so each figure is a share of all issues mentioned, not of patients.</p>

            <div class="cf-list-card mt-3">
                @forelse ($experience['issues'] as $row)
                    <div class="cf-list-row">
                        <span class="cf-dot cf-dot--clay" aria-hidden="true"></span>
                        <div class="cf-list-row__main">
                            <div class="flex items-baseline justify-between gap-4">
                                <p class="font-medium">{{ $row['label'] }}</p>
                                <p class="shrink-0"><span class="font-semibold tabular-nums">{{ $row['percent'] }}%</span> <span class="text-sm text-ink/70">({{ $row['mentions'] }})</span></p>
                            </div>
                            <div class="mt-2 h-2 rounded-full bg-line" aria-hidden="true">
                                <div class="h-2 rounded-full gloss-fill opacity-50" style="width: {{ max(2, $row['percent']) }}%"></div>
                            </div>
                        </div>
                    </div>
                @empty
                    <p class="cf-list-card__empty">No issues have been named.</p>
                @endforelse
            </div>
        @endif
    </section>
</x-app-layout>
