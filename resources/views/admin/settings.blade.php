@php
    $cutoff = old('remote_queue_accept_until', $facility->remote_queue_accept_until ? substr($facility->remote_queue_accept_until, 0, 5) : '');
@endphp

<x-app-layout title="Settings">
    <x-slot name="header">
        <h1 class="text-2xl font-semibold">Settings</h1>
    </x-slot>

    @if ($errors->any())
        <div role="alert" class="mb-6 max-w-2xl rounded-2xl border-l-4 border-danger bg-white px-4 py-3 text-sm shadow-card">Some settings need another look: {{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('admin.settings.update') }}" class="max-w-2xl space-y-6"
          x-data="{ remote: @js((bool) old('remote_queue_enabled', $facility->remote_queue_enabled)) }">
        @csrf
        @method('PATCH')

        <section class="cf-card space-y-5" aria-labelledby="remote-heading">
            <div>
                <h2 id="remote-heading" class="text-lg font-semibold">Remote queue</h2>
                <p class="mt-1 text-sm text-ink/70">Let people ask for a place in your queue from home, on your public page. Staff review every request: nothing joins the queue until you accept it. Your public page is <a href="{{ route('directory.show', $facility->slug) }}" class="link">{{ route('directory.show', $facility->slug) }}</a>.</p>
            </div>

            <div>
                <input type="hidden" name="remote_queue_enabled" value="0">
                <x-check name="remote_queue_enabled" value="1" x-model="remote">Take queue requests from home</x-check>
            </div>

            <div class="grid gap-5 sm:grid-cols-2" x-bind:class="{ 'opacity-50': ! remote }">
                <x-field name="remote_queue_max_pending" label="Most requests waiting for review" hint="New requests are refused once this many are waiting. Accepting or declining frees a place.">
                    <x-text-input id="remote_queue_max_pending" name="remote_queue_max_pending" type="number" min="1" max="500" :value="old('remote_queue_max_pending', $facility->remote_queue_max_pending)" required />
                </x-field>

                <x-field name="remote_queue_accept_until" label="Stop taking requests at" optional hint="Leave empty to take them all day. Uses your local time.">
                    <x-text-input id="remote_queue_accept_until" name="remote_queue_accept_until" type="time" :value="$cutoff" />
                </x-field>

                <x-field name="remote_queue_grace_minutes" label="Grace period (minutes)" hint="How long a patient who is next and not here is given before staff decide.">
                    <x-text-input id="remote_queue_grace_minutes" name="remote_queue_grace_minutes" type="number" min="1" max="60" :value="old('remote_queue_grace_minutes', $facility->remote_queue_grace_minutes)" required />
                </x-field>
            </div>

            <div class="space-y-3" x-bind:class="{ 'opacity-50': ! remote }">
                <div>
                    <input type="hidden" name="remote_queue_allow_service_choice" value="0">
                    <x-check name="remote_queue_allow_service_choice" value="1" :checked="old('remote_queue_allow_service_choice', $facility->remote_queue_allow_service_choice)">Let people choose the service</x-check>
                </div>
                <div>
                    <input type="hidden" name="remote_queue_allow_doctor_choice" value="0">
                    <x-check name="remote_queue_allow_doctor_choice" value="1" :checked="old('remote_queue_allow_doctor_choice', $facility->remote_queue_allow_doctor_choice)">Let people ask for a doctor</x-check>
                    <p class="mt-1.5 text-sm text-ink/60">Off by default. When on, the doctors on duty are listed by name on your public page, and staff still make the final choice.</p>
                </div>
            </div>
        </section>

        <section class="cf-card space-y-4" aria-labelledby="checkin-heading">
            <div>
                <h2 id="checkin-heading" class="text-lg font-semibold">Self check-in</h2>
                <p class="mt-1 text-sm text-ink/70">Let people who are already at your facility register on their own phone by scanning a QR code, instead of queueing at the desk. Staff still confirm each one, with the person standing in front of them.</p>
            </div>

            <div>
                <input type="hidden" name="self_checkin_enabled" value="0">
                <x-check name="self_checkin_enabled" value="1" :checked="old('self_checkin_enabled', $facility->self_checkin_enabled)">Let people check themselves in</x-check>
            </div>

            @if ($selfCheckinQr)
                <div class="flex flex-wrap items-center gap-5">
                    <a href="{{ $selfCheckinQr }}" download="{{ $facility->slug }}-self-checkin-qr.png" title="Download the QR code to print">
                        <img src="{{ $selfCheckinQr }}" alt="QR code that opens the self check-in page" width="160" height="160" class="h-40 w-40 rounded-xl border border-line">
                    </a>
                    <div class="min-w-0 text-sm">
                        <p class="font-medium">Print this and put it at the entrance.</p>
                        <p class="mt-1 text-ink/70">Or share the address: <a href="{{ $selfCheckinUrl }}" class="link break-all">{{ $selfCheckinUrl }}</a></p>
                        <a href="{{ $selfCheckinQr }}" download="{{ $facility->slug }}-self-checkin-qr.png" class="btn-outline btn-sm mt-3">Download QR code</a>
                    </div>
                </div>
            @endif
        </section>

        <div>
            <x-primary-button>Save settings</x-primary-button>
        </div>
    </form>

    <p class="mt-8 max-w-2xl text-sm text-ink/70">To change your own password or details, use your <a href="{{ route('profile.edit') }}" class="link">profile</a>.</p>
</x-app-layout>
