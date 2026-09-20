<x-app-layout title="Queue">
    @if ($errors->has('queue'))
        <div role="alert" class="mb-6 rounded-2xl border-l-4 border-danger bg-white px-4 py-3 text-sm shadow-card">{{ $errors->first('queue') }}</div>
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
