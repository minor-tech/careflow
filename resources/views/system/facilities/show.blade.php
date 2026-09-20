@php
    $at = fn ($date): ?string => $date?->format('j M Y, g:i A');

    $gps = $facility->latitude !== null && $facility->longitude !== null
        ? new \Illuminate\Support\HtmlString(e("{$facility->latitude}, {$facility->longitude}")
            .' &middot; <a class="link" target="_blank" rel="noopener" href="'
            .e("https://www.openstreetmap.org/?mlat={$facility->latitude}&mlon={$facility->longitude}#map=16/{$facility->latitude}/{$facility->longitude}")
            .'">View on map</a>')
        : null;

    $channels = collect($facility->notification_channels)
        ->map(fn (string $channel) => \App\Enums\NotificationChannel::tryFrom($channel)?->label() ?? $channel)
        ->join(', ');
@endphp

<x-app-layout :title="$facility->name">
    <x-slot name="header">
        <a href="{{ route('system.facilities.pending') }}" class="link inline-flex min-h-11 items-center text-sm">&larr; Pending facilities</a>
        <h1 class="text-2xl font-semibold">{{ $facility->name }}</h1>
        <p class="text-sm text-ink/70">
            {{ $facility->facility_type->label() }} &middot; {{ $facility->county }} &middot; License <span class="font-medium text-ink">{{ $facility->license_number }}</span>
        </p>
    </x-slot>

    @if ($errors->has('review'))
        <div role="alert" class="mb-6 rounded-2xl border-l-4 border-danger bg-white px-4 py-3 text-sm shadow-card">{{ $errors->first('review') }}</div>
    @endif

    <div class="cf-card mb-6 space-y-2">
        <p class="text-sm text-ink/70">Status</p>
        <div><x-status-pill :label="$facility->status->label()" :tone="$facility->status->tone()" /></div>
        @if ($facility->reviewed_at)
            <p class="text-sm text-ink/70">
                Reviewed {{ $at($facility->reviewed_at) }}@if ($facility->reviewer) by {{ $facility->reviewer->name }}@endif
            </p>
        @endif
        @if ($facility->rejection_reason)
            <p class="text-sm">Reason given: {{ $facility->rejection_reason }}</p>
        @endif
    </div>

    <div class="space-y-6">
        <x-detail-list title="Identity" :rows="[
            'Facility name' => $facility->name,
            'Facility type' => $facility->facility_type->label(),
            'Ownership' => $facility->ownership_type?->label(),
            'License number' => $facility->license_number,
        ]" />

        <x-detail-list title="Location" :rows="[
            'County' => $facility->county,
            'Sub-county' => $facility->sub_county,
            'Address' => $facility->address,
            'GPS' => $gps,
        ]" />

        <x-detail-list title="Contact" :rows="[
            'Phone' => $facility->phone,
            'Alternate phone' => $facility->alt_phone,
            'Email' => $facility->email,
            'Website' => $facility->website,
        ]" />

        <x-detail-list title="Operations" :rows="[
            'Operating days' => $facility->operatingDaysLabel(),
            'Hours' => $facility->operatingHoursLabel(),
            'Departments' => $departments->pluck('name')->join(', '),
            'Doctors on staff' => $facility->doctors_count,
            'Consultation rooms' => $facility->consultation_rooms,
        ]" />

        @foreach ($admins as $admin)
            <x-detail-list :title="$admins->count() > 1 ? 'Admin account '.$loop->iteration : 'Admin account'" :rows="[
                'Name' => $admin->name,
                'Title' => $admin->title,
                'Email' => $admin->email,
                'Phone' => $admin->phone,
                'Two-factor' => $admin->two_factor_enabled ? 'Enabled' : 'Off',
            ]" />
        @endforeach

        <x-detail-list title="Notifications and staff" :rows="[
            'Notification channels' => $channels,
            'SMS sender ID' => $facility->sms_sender_id,
            'Staff invited at registration' => $invitedStaffCount,
        ]" />

        <x-detail-list title="Data protection and consent" :rows="[
            'Data protection role' => $facility->data_role->label(),
            'ODPC registration number' => $facility->odpc_registration_no,
            'Data Processing Agreement accepted' => $at($facility->dpa_accepted_at),
            'Patient consent confirmed' => $at($facility->patient_consent_confirmed_at),
            'Terms of Service accepted' => $at($facility->terms_accepted_at),
            'Privacy Policy accepted' => $at($facility->privacy_accepted_at),
            'Signed by' => $facility->signature_name,
            'Submitted' => $at($facility->created_at),
        ]" />
    </div>

    @if ($facility->isPendingReview())
        <section class="mt-10 space-y-4 border-t border-line pt-6" x-data="{ rejecting: {{ $errors->has('reason') ? 'true' : 'false' }} }">
            <div>
                <h2 class="text-base font-semibold">Decision</h2>
                <p class="text-sm text-ink/70">Check the license number against the relevant register before approving. Approving lets this facility's admin and staff log in.</p>
            </div>

            <div class="flex flex-col gap-3 sm:flex-row sm:items-center" x-show="! rejecting">
                <form method="POST" action="{{ route('system.facilities.approve', $facility) }}"
                      onsubmit="return confirm('Approve {{ e(addslashes($facility->name)) }}? Its admin and staff will be able to log in.')">
                    @csrf
                    <x-primary-button class="w-full sm:w-auto">Approve facility</x-primary-button>
                </form>

                <button type="button" class="btn-danger" x-on:click="rejecting = true">Reject&hellip;</button>
            </div>

            <form method="POST" action="{{ route('system.facilities.reject', $facility) }}" class="space-y-4" x-show="rejecting" x-cloak>
                @csrf

                <x-field name="reason" label="Reason for rejecting" hint="The facility admin will receive this by email.">
                    <textarea id="reason" name="reason" rows="4" maxlength="1000" class="field-input" required>{{ old('reason') }}</textarea>
                </x-field>

                <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                    <x-danger-button>Reject registration</x-danger-button>
                    <button type="button" class="btn-outline" x-on:click="rejecting = false">Cancel</button>
                </div>
            </form>
        </section>
    @endif
</x-app-layout>
