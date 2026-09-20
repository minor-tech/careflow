@php
    $ahead = $snapshot->patientsAhead;
@endphp

{{--
    The order of this page is deliberate. "Patients ahead of you" comes first
    because it is a fact the database knows right now; the estimated wait is a
    prediction that one long consultation can make wrong, so it sits below in
    small type as a range. Leading with the certain number is more honest, and
    more reassuring, than a falsely precise time.
--}}

<p class="text-sm font-medium text-ink/70">{{ $snapshot->visit->facility->name }}</p>

<div class="mt-3 flex items-baseline justify-between gap-4">
    <h1 class="min-w-0 break-words text-xl font-semibold">{{ $snapshot->visit->patient->name }}</h1>
    <p class="shrink-0 text-ink/70">Queue <span class="numeral text-ink">{{ $snapshot->queueLabel }}</span></p>
</div>

{{-- Whose line this is. Only ever the patient's own doctor, and never how many other patients that doctor has. --}}
@if ($snapshot->doctorName)
    <p class="mt-2 text-sm text-ink/70">Your doctor: <span class="font-semibold text-ink">{{ $snapshot->doctorName }}</span></p>
    @if ($snapshot->doctorChanged)
        <p class="mt-2 rounded-2xl border-l-4 border-wait bg-white px-4 py-3 text-sm shadow-card" role="status">Your assigned doctor has changed. You are now with {{ $snapshot->doctorName }}, and your queue number is {{ $snapshot->queueLabel }}.</p>
    @endif
@endif

@if ($snapshot->serving)
    <p class="mt-2 text-sm text-ink/70">{{ $snapshot->doctorName ? 'Currently seeing' : 'Currently serving' }}: <span class="font-semibold tabular-nums text-ink">{{ $snapshot->serving }}</span></p>
@endif

{{-- Accepted from home: when to set off, above everything else. Gone the moment staff check them in. --}}
@if ($snapshot->awaitingArrival && $snapshot->arrivalWindow)
    <p class="mt-6 rounded-2xl border-l-4 border-wait bg-white px-4 py-3 shadow-card">
        <span class="block text-sm text-ink/70">Recommended arrival</span>
        <span class="block text-xl font-semibold tabular-nums">{{ $snapshot->arrivalWindow }}</span>
    </p>
@endif

@if ($ahead !== null)
    <div class="mt-10 text-center">
        @if ($ahead === 0)
            <p class="text-5xl font-bold leading-tight text-primary sm:text-6xl">You're next</p>
            <p class="mt-2 text-lg text-ink/70">No one is ahead of you</p>
        @else
            <p class="text-[7rem] font-bold leading-none text-primary tabular-nums sm:text-[9rem]">{{ $ahead }}</p>
            <p class="mt-2 text-lg text-ink/70">{{ $ahead === 1 ? 'patient' : 'patients' }} ahead of you</p>
        @endif

        @if ($snapshot->reassurance)
            <p class="mx-auto mt-4 max-w-xs text-sm text-ink/70">{{ $snapshot->reassurance }}</p>
        @endif
    </div>
@endif

<p class="{{ $ahead !== null ? 'mt-10 text-lg' : 'mt-10 text-2xl' }} flex items-center gap-3 font-medium">
    <span class="h-3.5 w-3.5 shrink-0 rounded-full {{ $snapshot->tone->dotClass() }}" aria-hidden="true"></span>
    <span>{{ $snapshot->stage }}</span>
</p>

@if ($snapshot->estimate)
    <p class="mt-2 pl-[1.625rem] text-sm text-ink/60">Estimated wait: {{ $snapshot->estimate->label() }}</p>
@endif

@if ($snapshot->awaitingArrival)
    {{-- Saying so is only a claim: it tells the front desk to look up and check them in, and changes nothing about the queue until they do. --}}
    <div class="mt-8">
        @if ($snapshot->arrivalSignaled)
            <p class="rounded-2xl border-l-4 border-info bg-white px-4 py-3 text-sm shadow-card" role="status">Thanks. Please tell the front desk you are here: staff will check you in and you'll be waiting in the queue.</p>
        @else
            <form method="POST" action="{{ route('tracking.arrived', $snapshot->visit->tracking_token) }}">
                <button type="submit" class="btn-primary w-full">I've arrived</button>
            </form>
            <p class="mt-2 text-center text-sm text-ink/60">Tap this when you reach the facility. Staff will then check you in.</p>
        @endif
    </div>
@endif

@if ($snapshot->askForFeedback)
    @include('tracking._feedback')
@elseif ($snapshot->feedbackGiven)
    <div class="mt-8 border-t border-line pt-6">
        <p class="text-lg font-medium">Thanks for your feedback.</p>
        @if (session('feedback_notice'))
            <p class="mt-1 text-sm text-ink/70">{{ session('feedback_notice') }}</p>
        @endif
    </div>
@else
    <div class="mt-8 border-t border-line pt-6">
        <x-visit-journey :steps="$snapshot->steps" :open="$snapshot->open" />
    </div>
@endif
