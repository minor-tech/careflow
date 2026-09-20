<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('layouts.head', ['title' => 'Link not active'])
        <meta name="robots" content="noindex, nofollow">
        <meta name="referrer" content="no-referrer">
    </head>
    <body>
        <main class="mx-auto flex min-h-screen w-full max-w-md flex-col justify-center px-4 py-8 text-center">
            <h1 class="text-xl font-semibold">This link isn't active</h1>
            <p class="mt-3 text-ink/70">Visit links only work on the day of the visit. If you're at the clinic and need help, ask at the front desk.</p>
        </main>
    </body>
</html>
