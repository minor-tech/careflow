<x-guest-layout title="Choose your password">
    <h1 class="text-2xl font-semibold">Choose your own password</h1>
    <p class="mt-2 text-sm text-ink/70">You signed in with a temporary password that someone else chose and passed on. Pick one only you know. You'll use it from now on.</p>

    <form method="POST" action="{{ route('password.force.store') }}" class="mt-6 space-y-5" x-data="passwordStrength()">
        @csrf

        <x-field name="password" label="New password" hint="At least 8 characters, with letters and numbers.">
            <div class="flex gap-2">
                <x-text-input id="password" name="password" x-model="password" x-bind:type="show ? 'text' : 'password'" type="password" required autofocus autocomplete="new-password" />
                <button type="button" class="link inline-flex min-h-11 items-center px-2 text-sm" x-on:click="show = ! show" x-text="show ? 'Hide' : 'Show'">Show</button>
            </div>
            <div x-show="password.length > 0" x-cloak aria-live="polite">
                <div class="mt-2 flex gap-1" aria-hidden="true">
                    <template x-for="segment in 4" :key="segment">
                        <span class="h-1.5 flex-1 rounded-full" :class="segment <= score ? 'gloss-fill' : 'bg-line'"></span>
                    </template>
                </div>
                <p class="mt-1 text-sm text-ink/70">Password strength: <span x-text="label" class="font-medium"></span></p>
            </div>
        </x-field>

        <x-field name="password_confirmation" label="Confirm new password">
            <x-text-input id="password_confirmation" name="password_confirmation" x-bind:type="show ? 'text' : 'password'" type="password" required autocomplete="new-password" />
        </x-field>

        <x-primary-button class="w-full">Save password</x-primary-button>
    </form>

    <form method="POST" action="{{ route('logout') }}" class="mt-4">
        @csrf
        <button type="submit" class="btn-outline w-full">Log out instead</button>
    </form>
</x-guest-layout>
