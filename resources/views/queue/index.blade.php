<x-app-layout title="Queue">
    @if ($errors->any())
        <div role="alert" class="mb-6 rounded-2xl border-l-4 border-danger bg-white px-4 py-3 text-sm shadow-card">{{ $errors->first('queue') ?: $errors->first() }}</div>
    @endif

    @if ($revealedPin)
        {{-- The only time this PIN is ever shown: it isn't stored anywhere readable, and a reload won't bring it back. It sits outside the polled board so a refresh can't wipe it. --}}
        <section class="cf-card mb-6 border-l-4 border-ok" role="status" x-data="{ copied: false }">
            <p class="font-medium">New PIN for {{ $revealedPin['code'] }} ({{ $revealedPin['name'] }}).</p>

            <p class="mt-3 text-sm text-ink/70">Access PIN</p>
            <div class="mt-1 flex flex-wrap items-center gap-x-4 gap-y-2">
                <code id="new-access-pin" x-ref="pin" class="select-all rounded-md border border-line bg-line/40 px-3 py-2 font-mono text-3xl font-semibold tracking-[0.3em] tabular-nums" aria-label="New access PIN {{ implode(' ', str_split($revealedPin['pin'])) }}">{{ $revealedPin['pin'] }}</code>
                <button type="button" class="btn-outline btn-sm"
                        x-on:click="navigator.clipboard.writeText($refs.pin.textContent.trim()).then(() => copied = true).catch(() => {})">
                    <span x-text="copied ? 'Copied' : 'Copy'">Copy</span>
                </button>
            </div>

            <p class="mt-3 text-sm">Read it to the patient. <strong>It won't be shown again.</strong></p>
            <p class="text-sm text-ink/70">Their old PIN has already stopped working.</p>
        </section>
    @endif

    <div x-data="queueBoard()">
        {{-- Everything inside the board is re-fetched every few seconds, so a colleague's changes show up without a reload. --}}
        <div x-ref="board">
            @include('queue._board')
        </div>

        <p class="mt-6 text-sm text-ink/60">
            <span x-show="! offline">Updates by itself &middot; last checked <span class="tabular-nums" x-text="checkedAt"></span></span>
            <span x-show="offline" x-cloak class="text-danger">Can't refresh right now. Retrying&hellip;</span>
        </p>
    </div>
</x-app-layout>
