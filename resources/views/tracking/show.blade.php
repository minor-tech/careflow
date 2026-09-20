<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('layouts.head', ['title' => $snapshot->visit->facility->name])
        <meta name="robots" content="noindex, nofollow">
        <meta name="referrer" content="no-referrer">
    </head>
    <body>
        <main class="mx-auto min-h-screen w-full max-w-md px-4 py-8"
              x-data="trackingPage({ url: @js(route('tracking.status', $token)), seconds: @js($pollSeconds), finished: @js(! $snapshot->open) })">
            {{-- The first paint is server-rendered; the script only swaps in newer copies of this same block. --}}
            <div x-ref="live" aria-live="polite">
                @include('tracking._live')
            </div>

            <p x-show="offline" x-cloak class="mt-6 text-center text-sm text-danger" role="status">Can't refresh right now. Trying again&hellip;</p>
            <p class="mt-8 text-center text-xs text-ink/50">This page updates by itself.</p>
        </main>
    </body>
</html>
