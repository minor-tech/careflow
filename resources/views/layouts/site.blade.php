@php
    $description = $attributes->get('description', 'CareFlow gives Kenyan clinics and hospitals a live view of every patient\'s visit, and gives patients their own link to see where they stand.');
    $links = [
        ['label' => 'Home', 'route' => 'home'],
        ['label' => 'About', 'route' => 'about'],
        ['label' => 'Contact', 'route' => 'contact'],
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('layouts.head', ['title' => $attributes->get('title')])
        <meta name="description" content="{{ $description }}">
        <meta property="og:type" content="website">
        <meta property="og:site_name" content="CareFlow">
        <meta property="og:title" content="{{ filled($attributes->get('title')) ? $attributes->get('title').' · CareFlow' : 'CareFlow' }}">
        <meta property="og:description" content="{{ $description }}">
    </head>
    <body>
        <a href="#main" class="btn-primary btn-sm sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50">Skip to content</a>

        <header x-data="{ open: false }" class="relative z-30">
            <div class="mx-auto flex max-w-6xl items-center justify-between gap-6 px-4 py-4 lg:py-5">
                <a href="{{ route('home') }}" class="flex items-center gap-2.5 text-primary">
                    <x-application-logo class="h-9 w-9" />
                    <span class="text-xl font-semibold">CareFlow</span>
                </a>

                <nav aria-label="Main" class="hidden items-center gap-8 md:flex">
                    @foreach ($links as $link)
                        <a href="{{ route($link['route']) }}"
                           @if (request()->routeIs($link['route'])) aria-current="page" @endif
                           class="site-nav-link {{ request()->routeIs($link['route']) ? 'text-primary underline decoration-2 underline-offset-8' : '' }}">{{ $link['label'] }}</a>
                    @endforeach
                </nav>

                <div class="hidden items-center gap-6 md:flex">
                    @auth
                        <a href="{{ route('dashboard') }}" class="site-nav-link">Open dashboard</a>
                    @else
                        <a href="{{ route('facility.register') }}" class="btn-primary btn-sm">Register Your Facility</a>
                        <a href="{{ route('login') }}" class="site-nav-link">Staff Login</a>
                    @endauth
                </div>

                <button type="button"
                        class="btn-outline btn-sm md:hidden"
                        x-on:click="open = ! open"
                        x-bind:aria-expanded="open.toString()"
                        aria-controls="mobile-menu">
                    Menu
                </button>
            </div>

            <div id="mobile-menu" x-show="open" x-cloak x-transition.opacity.duration.200ms class="absolute inset-x-0 top-full border-t border-line bg-white px-4 pb-6 pt-2 shadow-card md:hidden">
                <nav aria-label="Mobile" class="flex flex-col">
                    @foreach ($links as $link)
                        <a href="{{ route($link['route']) }}"
                           @if (request()->routeIs($link['route'])) aria-current="page" @endif
                           class="flex min-h-12 items-center border-b border-line text-base font-medium {{ request()->routeIs($link['route']) ? 'text-primary' : 'text-ink' }}">{{ $link['label'] }}</a>
                    @endforeach
                </nav>

                <div class="mt-5 flex flex-col items-stretch gap-3">
                    @auth
                        <a href="{{ route('dashboard') }}" class="btn-outline">Open dashboard</a>
                    @else
                        <a href="{{ route('facility.register') }}" class="btn-primary">Register Your Facility</a>
                        <a href="{{ route('login') }}" class="flex min-h-11 items-center justify-center text-base font-medium text-ink">Staff Login</a>
                    @endauth
                </div>
            </div>
        </header>

        <main id="main">
            {{ $slot }}
        </main>

        <footer class="border-t border-line bg-canvas">
            <div class="mx-auto flex max-w-6xl flex-col gap-8 px-4 py-12 md:flex-row md:items-start md:justify-between">
                <div class="max-w-xs">
                    <a href="{{ route('home') }}" class="flex items-center gap-2.5 text-primary">
                        <x-application-logo class="h-8 w-8" />
                        <span class="text-lg font-semibold">CareFlow</span>
                    </a>
                    <p class="mt-3 text-sm text-ink/70">Patient flow and communication for clinics and hospitals in Kenya.</p>
                </div>

                <nav aria-label="Footer" class="flex flex-wrap gap-x-8 gap-y-2 text-sm">
                    <a href="{{ route('home') }}" class="site-nav-link !text-sm">Home</a>
                    <a href="{{ route('about') }}" class="site-nav-link !text-sm">About</a>
                    <a href="{{ route('contact') }}" class="site-nav-link !text-sm">Contact</a>
                    @guest
                        <a href="{{ route('facility.register') }}" class="site-nav-link !text-sm">Register Your Facility</a>
                        <a href="{{ route('login') }}" class="site-nav-link !text-sm">Staff Login</a>
                    @endguest
                </nav>
            </div>

            <p class="mx-auto max-w-6xl px-4 pb-8 text-xs text-ink/60">&copy; {{ now()->year }} CareFlow Kenya</p>
        </footer>
    </body>
</html>
