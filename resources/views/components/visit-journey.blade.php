@props(['steps', 'open' => true])

{{-- A short vertical list, not a graphic: a check for where you've been, an amber dot for where you are, an empty circle for what isn't decided yet. --}}
<ol {{ $attributes->class('space-y-4') }} aria-label="Your visit so far">
    @foreach ($steps as $step)
        <li class="flex items-start gap-3">
            <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center" aria-hidden="true">
                @if ($step->state === \App\Enums\JourneyStepState::Done)
                    <svg viewBox="0 0 20 20" class="h-5 w-5 text-ok" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 10.5l4 4 8-9"/></svg>
                @elseif ($step->state === \App\Enums\JourneyStepState::Current)
                    <span class="h-3.5 w-3.5 rounded-full bg-wait"></span>
                @else
                    <svg viewBox="0 0 20 20" class="h-5 w-5 text-danger" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M5 5l10 10M15 5L5 15"/></svg>
                @endif
            </span>

            <div>
                <p class="font-medium">
                    <span class="sr-only">{{ match ($step->state) {
                        \App\Enums\JourneyStepState::Done => 'Done: ',
                        \App\Enums\JourneyStepState::Current => 'Now: ',
                        \App\Enums\JourneyStepState::Cancelled => 'Cancelled: ',
                    } }}</span>{{ $step->department }}
                </p>
                @if ($step->number)
                    <p class="text-sm text-ink/70">Your number: <span class="font-semibold tabular-nums text-ink">{{ $step->number }}</span></p>
                @endif
            </div>
        </li>
    @endforeach

    @if ($open)
        <li class="flex items-start gap-3 text-ink/60">
            <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center" aria-hidden="true">
                <span class="h-3.5 w-3.5 rounded-full border-2 border-ink/30"></span>
            </span>
            <p class="text-sm">Further steps appear once your doctor decides them.</p>
        </li>
    @endif
</ol>
