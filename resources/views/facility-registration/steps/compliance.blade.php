@php
    $agreedDpa = filter_var($value('agreed_dpa', false), FILTER_VALIDATE_BOOLEAN);
    $confirmsConsent = filter_var($value('confirms_patient_consent', false), FILTER_VALIDATE_BOOLEAN);
@endphp

<x-wizard-layout :steps="$steps" :step="$step" :completed="$completed">
    <p class="mt-2 text-sm text-ink/70">CareFlow handles patient health data, so we need to record how your facility is responsible for it under the Data Protection Act.</p>

    <form method="POST" action="{{ route('facility.register.save', $step) }}" class="mt-6 space-y-5">
        @csrf

        <x-field name="data_role" label="Is your facility a data controller or a processor?" hint="Most clinics that decide how patient data is used are controllers.">
            <x-select id="data_role" name="data_role" :options="$options['dataRoles']" :selected="$value('data_role')" placeholder="Select one" required autofocus />
        </x-field>

        <x-field name="odpc_registration_no" label="ODPC registration number" optional hint="Your registration with the Office of the Data Protection Commissioner, if you have one.">
            <x-text-input id="odpc_registration_no" name="odpc_registration_no" type="text" :value="$value('odpc_registration_no')" />
        </x-field>

        <div class="space-y-3">
            <div class="space-y-1.5">
                <x-check name="agreed_dpa" value="1" :checked="$agreedDpa">I agree to CareFlow's Data Processing Agreement.</x-check>
                <x-input-error :messages="$errors->get('agreed_dpa')" />
            </div>
            <div class="space-y-1.5">
                <x-check name="confirms_patient_consent" value="1" :checked="$confirmsConsent">I confirm this facility will obtain patient consent before collecting their data.</x-check>
                <x-input-error :messages="$errors->get('confirms_patient_consent')" />
            </div>
        </div>

        <x-wizard-actions :step="$step" />
    </form>
</x-wizard-layout>
