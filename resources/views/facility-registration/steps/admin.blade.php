@php
    $twoFactor = filter_var($value('two_factor_enabled', true), FILTER_VALIDATE_BOOLEAN);
@endphp

<x-wizard-layout :steps="$steps" :step="$step" :completed="$completed">
    <p class="mt-2 text-sm text-ink/70">This person becomes the facility's first admin and can add the rest of your staff.</p>

    <form method="POST" action="{{ route('facility.register.save', $step) }}" class="mt-6 space-y-5">
        @csrf

        <x-field name="admin_name" label="Full name">
            <x-text-input id="admin_name" name="admin_name" type="text" :value="$value('admin_name')" required autofocus autocomplete="name" />
        </x-field>

        <x-field name="admin_title" label="Role or title" hint="For example Facility Manager or Medical Superintendent.">
            <x-text-input id="admin_title" name="admin_title" type="text" :value="$value('admin_title')" required autocomplete="organization-title" />
        </x-field>

        <x-field name="admin_phone" label="Phone">
            <x-text-input id="admin_phone" name="admin_phone" type="tel" inputmode="tel" :value="$value('admin_phone')" required autocomplete="tel" />
        </x-field>

        <x-field name="admin_email" label="Email" hint="This is the email you will log in with.">
            <x-text-input id="admin_email" name="admin_email" type="email" :value="$value('admin_email')" required autocomplete="username" />
        </x-field>

        <div class="space-y-5" x-data="passwordStrength()">
            <x-field name="admin_password" label="Password" :hint="$hasPassword ? 'A password is already set. Leave both fields blank to keep it.' : 'At least 8 characters, with letters and numbers.'">
                <div class="flex gap-2">
                    <x-text-input id="admin_password" name="admin_password" x-model="password" x-bind:type="show ? 'text' : 'password'" type="password" :required="! $hasPassword" autocomplete="new-password" />
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

            <x-field name="admin_password_confirmation" label="Confirm password">
                <x-text-input id="admin_password_confirmation" name="admin_password_confirmation" x-bind:type="show ? 'text' : 'password'" type="password" :required="! $hasPassword" autocomplete="new-password" />
            </x-field>
        </div>

        <div>
            <input type="hidden" name="two_factor_enabled" value="0">
            <x-check name="two_factor_enabled" value="1" :checked="$twoFactor">
                <span class="font-medium">Enable two-factor authentication</span>
                <span class="block text-ink/60">Recommended for anyone who can see patient records.</span>
            </x-check>
        </div>

        <x-wizard-actions :step="$step" />
    </form>
</x-wizard-layout>
