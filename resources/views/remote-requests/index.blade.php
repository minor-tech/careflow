<x-app-layout title="Remote requests">
    <x-slot name="header">
        <div class="flex items-baseline gap-3">
            <h1 class="text-2xl font-semibold">Remote requests</h1>
            <span class="numeral" aria-label="{{ $requests->count() }} waiting for review">{{ $requests->count() }}</span>
        </div>
        <p class="mt-1 text-sm text-ink/70">People asking for a place in the queue: from home, or checking themselves in from inside the building. Nothing is created until you accept.</p>
    </x-slot>

    @if ($errors->any())
        <div role="alert" class="mb-6 rounded-2xl border-l-4 border-danger bg-white px-4 py-3 text-sm shadow-card">{{ $errors->first() }}</div>
    @endif

    <div class="cf-list-card">
        @forelse ($requests as $request)
            <div class="cf-list-row">
                {{-- Two kinds, told apart at a glance: someone still at home, and someone standing right there. --}}
                <span class="cf-dot cf-dot--{{ $request->isSelfCheckin() ? 'ok' : 'info' }}" aria-hidden="true"></span>
                <div class="cf-list-row__main">
                    <p class="truncate font-medium">
                        {{ $request->name }}
                        <span class="font-normal text-ink/70">&mdash; {{ $request->service?->name ?? 'Any service' }}</span>
                    </p>
                    <p class="text-sm text-ink/70">
                        @if ($request->isSelfCheckin())
                            <x-status-pill label="Here now" tone="ok" size="sm" />
                            <span class="ml-1">Checked in on their own phone and is in the building.</span>
                        @else
                            <x-status-pill label="From home" tone="info" size="sm" />
                            <span class="ml-1">Wants to arrive at {{ $request->requestedArrivalLabel() }}.</span>
                        @endif
                    </p>
                </div>

                <div class="cf-list-row__side">
                    <a href="{{ route('remote-requests.show', $request) }}" class="btn-primary btn-sm">Review</a>
                </div>
            </div>
        @empty
            <p class="cf-list-card__empty">No requests are waiting for review.</p>
        @endforelse
    </div>
</x-app-layout>
