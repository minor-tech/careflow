<x-site-layout title="Find a facility" description="Find a clinic or hospital near you, see how long the wait is right now, and ask for a place in the queue before you leave home.">

    <section class="bg-gradient-to-b from-tint-mid to-canvas px-4 pb-10 pt-8 text-center lg:pb-14 lg:pt-14">
        <div class="mx-auto max-w-3xl">
            <span class="badge-section bg-white">Find a facility</span>
            <h1 class="display-section mt-5">Find a facility</h1>
            <p class="mt-4 text-lg text-ink/80">See how long the wait is right now, and ask for a place in the queue before you leave home.</p>
        </div>

        <form method="GET" action="{{ route('directory.index') }}" class="mx-auto mt-8 max-w-xl space-y-4 text-left" role="search">
            <div class="flex flex-col gap-3 sm:flex-row">
                <label for="q" class="sr-only">Facility name, county or sub-county</label>
                <input type="search" id="q" name="q" value="{{ $words }}" maxlength="100" placeholder="Name, county or sub-county, e.g. Muranga" autocomplete="off" class="field-input min-w-0 flex-1 !rounded-xl">
                <button type="submit" class="btn-primary">Search</button>
            </div>

            <label class="flex min-h-11 cursor-pointer items-center gap-3 text-sm">
                <input type="checkbox" name="open_now" value="1" @checked($openNow) class="h-4 w-4 rounded border-line text-primary focus:ring-primary">
                Only show facilities open for remote queue right now
            </label>
        </form>
    </section>

    <section class="px-4 pb-14 pt-6 lg:pb-24">
        <div class="mx-auto max-w-6xl">
            @if ($cards->isEmpty())
                <div class="card-soft mx-auto max-w-xl p-8 text-center">
                    <p class="text-lg font-medium">
                        @if ($openNow)
                            No facility is open for remote queue right now{{ $words !== '' ? ' matching that search' : '' }}.
                        @else
                            No facility matches that search.
                        @endif
                    </p>
                    <p class="mt-2 text-ink/70">
                        @if ($openNow)
                            Try again a little later, or untick the box to see every facility.
                        @else
                            Check the spelling, or try a county name.
                        @endif
                    </p>
                </div>
            @else
                <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($cards as $card)
                        <x-directory.card :card="$card" />
                    @endforeach
                </div>

                <div class="mt-10">{{ $facilities->links() }}</div>
            @endif
        </div>
    </section>
</x-site-layout>
