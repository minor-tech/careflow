@php
    $selfCheckin = $remoteRequest->isSelfCheckin();
    $canAccept = ! $assignsDoctors || $doctors !== [];
@endphp

<x-app-layout title="Review request">
    <x-slot name="header">
        <h1 class="text-2xl font-semibold">{{ $remoteRequest->name }}</h1>
        <p class="mt-1 text-sm text-ink/70">
            @if ($selfCheckin)
                <x-status-pill label="Here now" tone="ok" size="sm" />
                <span class="ml-1">Checked in on their own phone: look up and you can see them.</span>
            @else
                <x-status-pill label="From home" tone="info" size="sm" />
                <span class="ml-1">Wants to arrive at {{ $remoteRequest->requestedArrivalLabel() }}.</span>
            @endif
        </p>
    </x-slot>

    @if ($errors->any())
        <div role="alert" class="mb-6 rounded-2xl border-l-4 border-danger bg-white px-4 py-3 text-sm shadow-card">{{ $errors->first() }}</div>
    @endif

    <div class="max-w-xl space-y-6">
        <section class="cf-card">
            <dl class="grid gap-3 text-sm sm:grid-cols-2">
                <div><dt class="text-ink/70">Phone</dt><dd class="font-medium tabular-nums">{{ $remoteRequest->phone }}</dd></div>
                <div><dt class="text-ink/70">For</dt><dd class="font-medium">{{ $remoteRequest->service?->name ?? 'Any service' }}{{ $department ? ' · '.$department->name : '' }}</dd></div>
                @if ($remoteRequest->preferredDoctor)
                    <div><dt class="text-ink/70">Asked for</dt><dd class="font-medium">{{ $remoteRequest->preferredDoctor->doctorName() }}</dd></div>
                @endif
            </dl>
        </section>

        <form method="POST" action="{{ route('remote-requests.accept', $remoteRequest) }}" class="cf-card space-y-4"
              x-data="{ doctor: @js((string) old('doctor_id', $preselected)), submitting: false }" x-on:submit="submitting = true">
            @csrf

            @if ($assignsDoctors)
                <fieldset>
                    <legend class="text-base font-semibold">Recommended doctor</legend>
                    <p class="mt-1 text-sm text-ink/60">Doctors on duty for this service, shortest line first. The recommendation is only a suggestion: choose whoever is right.</p>

                    @if ($doctors === [])
                        <p class="mt-3 rounded-xl bg-tint-light px-4 py-3 text-sm">No doctor is on duty for this service right now, so the request can't be accepted yet. Ask a doctor to go on duty, or decline it.</p>
                    @else
                        <div class="cf-list-card mt-3">
                            @foreach ($doctors as $doctor)
                                <label class="cf-list-row cursor-pointer has-[:checked]:bg-tint-mint-soft">
                                    <input type="radio" name="doctor_id" value="{{ $doctor['id'] }}" x-model="doctor" class="h-4 w-4 border-line text-primary focus:ring-primary">
                                    <div class="cf-list-row__main">
                                        <p class="font-medium">{{ $doctor['name'] }}@if ($doctor['specialty']) <span class="font-normal text-ink/60">· {{ $doctor['specialty'] }}</span>@endif</p>
                                        <p class="text-sm text-ink/70">
                                            {{ $doctor['waiting'] === 1 ? '1 patient waiting' : $doctor['waiting'].' patients waiting' }}
                                            @unless ($selfCheckin)
                                                &middot; Est. {{ $doctor['waiting'] === 0 ? 'no wait' : $doctor['callWindow'] }}
                                            @endunless
                                        </p>
                                    </div>
                                    @if ($doctor['recommended'])
                                        <span class="cf-status-pill cf-status-pill--ok cf-status-pill--sm">Recommended</span>
                                    @endif
                                </label>
                            @endforeach
                        </div>
                    @endif
                </fieldset>
            @else
                <p class="text-sm text-ink/70">{{ $department ? $department->name.' has one shared queue: the patient joins the back of it.' : 'There is no department to put this patient in yet.' }}</p>
            @endif

            @if ($canAccept && $department)
                <div class="flex flex-wrap items-center gap-3">
                    <button type="submit" class="btn-primary" x-bind:disabled="submitting {{ $assignsDoctors ? '|| ! doctor' : '' }}">
                        @if ($assignsDoctors)
                            <span>Accept</span>
                            @foreach ($doctors as $doctor)
                                <span x-show="doctor === '{{ $doctor['id'] }}'" x-cloak>and assign {{ $doctor['name'] }}</span>
                            @endforeach
                        @else
                            Accept
                        @endif
                    </button>
                    <a href="{{ route('remote-requests.index') }}" class="btn-outline">Back</a>
                </div>
                @if ($selfCheckin)
                    <p class="text-sm text-ink/60">They go straight into the queue as waiting: you are confirming them with them standing in front of you.</p>
                @else
                    <p class="text-sm text-ink/60">They get a place in the queue now and a text with when to arrive. They join the waiting patients only when you check them in.</p>
                @endif
            @else
                <a href="{{ route('remote-requests.index') }}" class="btn-outline">Back</a>
            @endif
        </form>

        <form method="POST" action="{{ route('remote-requests.decline', $remoteRequest) }}" class="cf-card space-y-3"
              onsubmit="return confirm('Decline this request? {{ e(addslashes($remoteRequest->name)) }} will be told.')">
            @csrf
            <h2 class="text-base font-semibold">Decline instead</h2>
            <div>
                <label for="declined_reason" class="block text-sm font-medium">Reason <span class="font-normal text-ink/60">(optional, sent to them)</span></label>
                <input id="declined_reason" name="declined_reason" type="text" maxlength="255" value="{{ old('declined_reason') }}" autocomplete="off" class="field-input mt-1" placeholder="e.g. The clinic is closing early today">
                @error('declined_reason')<p class="mt-1 text-sm text-danger" role="alert">{{ $message }}</p>@enderror
            </div>
            <button type="submit" class="btn-danger btn-sm">Decline</button>
        </form>
    </div>
</x-app-layout>
