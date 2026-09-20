@php
    use App\Enums\RemoteRequestStatus;

    $firstName = \Illuminate\Support\Str::before(trim($remoteRequest->name), ' ');
@endphp

<x-guest-layout title="Your request">
    <p class="text-sm text-ink/70">{{ $remoteRequest->facility->name }}</p>
    <h1 class="mt-1 text-2xl font-semibold">{{ $firstName }}</h1>

    @if ($remoteRequest->status === RemoteRequestStatus::Pending)
        @if ($remoteRequest->isSelfCheckin())
            <p class="mt-6 flex items-center gap-3 text-lg font-medium">
                <span class="h-3.5 w-3.5 shrink-0 rounded-full bg-wait" aria-hidden="true"></span>
                Check-in received
            </p>
            <p class="mt-2 text-ink/70">The front desk has your details and will confirm them in a moment. Please stay where you are.</p>
        @else
            <p class="mt-6 flex items-center gap-3 text-lg font-medium">
                <span class="h-3.5 w-3.5 shrink-0 rounded-full bg-wait" aria-hidden="true"></span>
                Request received
            </p>
            <p class="mt-2 text-ink/70">Waiting for the facility to review it. You asked to arrive at {{ $remoteRequest->requestedArrivalLabel() }}.</p>
            <p class="mt-2 text-sm text-ink/70">We'll text you when it is decided. You can also keep this page open: it moves on by itself.</p>

            <form method="POST" action="{{ route('remote.cancel', $remoteRequest->public_code) }}" class="mt-8"
                  onsubmit="return confirm('Cancel this request?')">
                @csrf
                <button type="submit" class="btn-outline btn-sm">Cancel my request</button>
            </form>
        @endif
    @elseif ($remoteRequest->status === RemoteRequestStatus::Declined)
        <p class="mt-6 flex items-center gap-3 text-lg font-medium">
            <span class="h-3.5 w-3.5 shrink-0 rounded-full bg-danger" aria-hidden="true"></span>
            This request wasn't accepted
        </p>
        @if (filled($remoteRequest->declined_reason))
            <p class="mt-2 text-ink/70">{{ $remoteRequest->declined_reason }}</p>
        @endif
        <p class="mt-4 text-sm text-ink/70">You are welcome to visit {{ $remoteRequest->facility->name }} in person, or <a href="{{ route('directory.show', $remoteRequest->facility->slug) }}" class="link">try again</a> later.</p>
    @elseif ($remoteRequest->status === RemoteRequestStatus::Cancelled)
        <p class="mt-6 text-lg font-medium">You cancelled this request.</p>
        <p class="mt-4 text-sm text-ink/70">Changed your mind? <a href="{{ route('directory.show', $remoteRequest->facility->slug) }}" class="link">Send a new request</a>.</p>
    @elseif ($remoteRequest->status === RemoteRequestStatus::Expired)
        <p class="mt-6 text-lg font-medium">This request has expired.</p>
        <p class="mt-2 text-ink/70">Requests only last for the day they are made.</p>
        <p class="mt-4 text-sm text-ink/70"><a href="{{ route('directory.show', $remoteRequest->facility->slug) }}" class="link">Send a new request</a> if you still need a place.</p>
    @else
        <p class="mt-6 text-lg font-medium">Your request was accepted.</p>
        <p class="mt-2 text-ink/70">That visit is over, so there is nothing more to follow.</p>
    @endif

    <p class="mt-8 text-xs text-ink/50">Keep this link to yourself: it opens your request.</p>
</x-guest-layout>
