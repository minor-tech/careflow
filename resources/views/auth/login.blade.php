<x-guest-layout title="Log in">
    <h1 class="text-2xl font-semibold">Log in</h1>

    <!-- Session Status -->
    <x-auth-session-status class="mt-4" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-5">
        @csrf

        <x-field name="email" label="Email">
            <x-text-input id="email" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" />
        </x-field>

        <x-field name="password" label="Password">
            <x-text-input id="password" type="password" name="password" required autocomplete="current-password" />
        </x-field>

        <x-check name="remember" value="1">Remember me on this device</x-check>

        <x-primary-button class="w-full">Log in</x-primary-button>

        <div class="flex flex-wrap items-center justify-between gap-x-4 text-sm">
            @if (Route::has('password.request'))
                <a class="link inline-flex min-h-11 items-center" href="{{ route('password.request') }}">Forgot your password?</a>
            @endif

            <a class="link inline-flex min-h-11 items-center" href="{{ route('facility.register') }}">Register your facility</a>
        </div>
    </form>
</x-guest-layout>
