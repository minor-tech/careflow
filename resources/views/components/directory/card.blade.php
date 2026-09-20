@props(['card'])

{{-- Only what a public card may say: no patient, no doctor's name or workload, nothing clinical. --}}
<article class="card-soft flex flex-col p-6">
    <h2 class="text-xl font-semibold"><a href="{{ route('directory.show', $card->slug) }}" class="hover:underline">{{ $card->name }}</a></h2>

    @if ($card->area() !== '')
        <p class="mt-1 text-sm text-ink/70">{{ $card->area() }}</p>
    @endif

    @if ($card->services !== [])
        <p class="mt-4 font-medium">{{ implode(' · ', $card->services) }}</p>
    @endif

    <p class="mt-3">
        @if ($card->remoteQueue->isOpen())
            <span class="cf-status-pill cf-status-pill--ok">Remote queue available</span>
        @elseif ($card->remoteQueue->reason())
            <span class="text-sm text-ink/70">{{ $card->remoteQueue->reason() }}</span>
        @endif
    </p>

    <dl class="mt-4 space-y-1 text-sm">
        @if ($card->waitLabel)
            <div class="flex justify-between gap-4"><dt class="text-ink/70">Current estimated wait</dt><dd class="font-semibold tabular-nums">{{ $card->waitLabel }}</dd></div>
        @endif
        <div class="flex justify-between gap-4"><dt class="text-ink/70">Doctors on duty</dt><dd class="font-semibold tabular-nums">{{ $card->doctorsOnDuty }}</dd></div>
    </dl>

    <a href="{{ route('directory.show', $card->slug) }}" class="btn-outline btn-sm mt-5 self-start">View facility</a>
</article>
