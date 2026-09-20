@php
    $accepted = fn (string $key): bool => filter_var($value($key, false), FILTER_VALIDATE_BOOLEAN);
    $facilityTypeLabel = $options['facilityTypes'][$value('facility_type')] ?? null;
    $departmentCount = count((array) $value('departments', []));
@endphp

<x-wizard-layout :steps="$steps" :step="$step" :completed="$completed">
    <p class="mt-2 text-sm text-ink/70">Check your details, then sign to submit. Nothing goes live until our team has reviewed your registration.</p>

    <dl class="mt-6 divide-y divide-line rounded-md border border-line bg-white text-sm">
        <div class="flex justify-between gap-4 px-4 py-3"><dt class="text-ink/60">Facility</dt><dd class="text-right font-medium">{{ $value('name') }}@if ($facilityTypeLabel) &middot; {{ $facilityTypeLabel }}@endif</dd></div>
        <div class="flex justify-between gap-4 px-4 py-3"><dt class="text-ink/60">Location</dt><dd class="text-right font-medium">{{ $value('sub_county') }}, {{ $value('county') }}</dd></div>
        <div class="flex justify-between gap-4 px-4 py-3"><dt class="text-ink/60">Departments</dt><dd class="numeral text-right text-base">{{ $departmentCount }}</dd></div>
        <div class="flex justify-between gap-4 px-4 py-3"><dt class="text-ink/60">Admin</dt><dd class="text-right font-medium">{{ $value('admin_name') }}<span class="block font-normal text-ink/60">{{ $value('admin_email') }}</span></dd></div>
        <div class="flex justify-between gap-4 px-4 py-3"><dt class="text-ink/60">Staff invited</dt><dd class="numeral text-right text-base">{{ count((array) $value('staff', [])) }}</dd></div>
    </dl>

    <form method="POST" action="{{ route('facility.register.store') }}" class="mt-6 space-y-5">
        @csrf

        <div class="space-y-3">
            <div class="space-y-1.5">
                <x-check name="accepted_terms" value="1" :checked="$accepted('accepted_terms')">I accept the Terms of Service.</x-check>
                <x-input-error :messages="$errors->get('accepted_terms')" />
            </div>
            <div class="space-y-1.5">
                <x-check name="accepted_privacy" value="1" :checked="$accepted('accepted_privacy')">I accept the Privacy Policy.</x-check>
                <x-input-error :messages="$errors->get('accepted_privacy')" />
            </div>
        </div>

        <x-field name="signature" label="Digital signature" hint="Type your full name to sign this registration on behalf of the facility.">
            <x-text-input id="signature" name="signature" type="text" :value="$value('signature')" required autocomplete="name" />
        </x-field>

        <x-wizard-actions :step="$step" label="Submit registration" />
    </form>
</x-wizard-layout>
