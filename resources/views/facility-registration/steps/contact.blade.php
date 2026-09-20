<x-wizard-layout :steps="$steps" :step="$step" :completed="$completed">
    <form method="POST" action="{{ route('facility.register.save', $step) }}" class="mt-6 space-y-5">
        @csrf

        <x-field name="phone" label="Official phone">
            <x-text-input id="phone" name="phone" type="tel" inputmode="tel" :value="$value('phone')" required autofocus autocomplete="tel" />
        </x-field>

        <x-field name="alt_phone" label="Alternate phone" optional>
            <x-text-input id="alt_phone" name="alt_phone" type="tel" inputmode="tel" :value="$value('alt_phone')" />
        </x-field>

        <x-field name="email" label="Official email">
            <x-text-input id="email" name="email" type="email" :value="$value('email')" required autocomplete="email" />
        </x-field>

        <x-field name="website" label="Website" optional>
            <x-text-input id="website" name="website" type="url" :value="$value('website')" placeholder="https://" />
        </x-field>

        <x-wizard-actions :step="$step" />
    </form>
</x-wizard-layout>
