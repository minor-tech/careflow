<x-wizard-layout :steps="$steps" :step="$step" :completed="$completed">
    <form method="POST" action="{{ route('facility.register.save', $step) }}" class="mt-6 space-y-5">
        @csrf

        <x-field name="name" label="Facility name">
            <x-text-input id="name" name="name" type="text" :value="$value('name')" required autofocus autocomplete="organization" />
        </x-field>

        <x-field name="facility_type" label="Facility type">
            <x-select id="facility_type" name="facility_type" :options="$options['facilityTypes']" :selected="$value('facility_type')" placeholder="Select a type" required />
        </x-field>

        <x-field name="license_number" label="License or registration number" hint="For example your KMPDC or Pharmacy and Poisons Board number.">
            <x-text-input id="license_number" name="license_number" type="text" :value="$value('license_number')" required />
        </x-field>

        <x-field name="ownership_type" label="Ownership type" optional>
            <x-select id="ownership_type" name="ownership_type" :options="$options['ownershipTypes']" :selected="$value('ownership_type')" placeholder="Select ownership" />
        </x-field>

        <x-wizard-actions :step="$step" />
    </form>
</x-wizard-layout>
