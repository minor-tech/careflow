<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('layouts.head', ['title' => $attributes->get('title')])
    </head>
    <body>
        <div class="flex min-h-screen flex-col items-center px-4 py-10 sm:justify-center">
            <a href="/" class="flex items-center gap-3 text-primary">
                <x-application-logo class="h-10 w-10" />
                <span class="text-xl font-semibold">CareFlow</span>
            </a>

            <main class="mt-8 w-full max-w-md rounded-lg border border-line bg-white p-6 sm:p-8">
                {{ $slot }}
            </main>
        </div>
    </body>
</html>
