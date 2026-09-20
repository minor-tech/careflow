@php
    $status = $facility->status;
    $isRejected = $status === \App\Enums\FacilityStatus::Rejected;
    $isSuspended = $status === \App\Enums\FacilityStatus::Suspended;
    $title = match (true) {
        $isRejected => 'Registration not approved',
        $isSuspended => 'Facility suspended',
        default => 'Registration under review',
    };
@endphp

<x-guest-layout :title="$title">
    <div class="space-y-4 border-l-4 pl-4 {{ $isRejected || $isSuspended ? 'border-danger' : 'border-wait' }}">
        <p class="text-sm text-ink/70">{{ $facility->name }}</p>
        <div><x-status-pill :label="$status->label()" :tone="$status->tone()" /></div>

        <h1 class="text-2xl font-semibold">{{ $title }}</h1>

        @if ($isRejected)
            <p>We could not approve this registration.</p>
            @if ($facility->rejection_reason)
                <p class="text-sm"><span class="text-ink/70">Reason:</span> {{ $facility->rejection_reason }}</p>
            @endif
            <p class="text-sm text-ink/70">If you think this is a mistake, please contact CareFlow support.</p>
        @elseif ($isSuspended)
            <p>This facility has been suspended, so staff can't use CareFlow right now. Contact CareFlow support to find out why and how to restore access.</p>
        @else
            <p>Your facility registration is under review. You'll receive a confirmation within 24 hours.</p>
            <p class="text-sm text-ink/70">Until it is approved, you and your staff can't use CareFlow. This page will change once the review is done.</p>
        @endif
    </div>

    <form method="POST" action="{{ route('logout') }}" class="mt-8">
        @csrf
        <x-outline-button type="submit">Log out</x-outline-button>
    </form>
</x-guest-layout>
