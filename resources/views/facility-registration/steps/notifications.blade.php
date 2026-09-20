@php
    $selectedChannels = (array) $value('notification_channels', ['sms']);
@endphp

<x-wizard-layout :steps="$steps" :step="$step" :completed="$completed">
    <p class="mt-2 text-sm text-ink/70">How should CareFlow reach your patients, and your staff when you add them?</p>

    <form method="POST" action="{{ route('facility.register.save', $step) }}" class="mt-6 space-y-5">
        @csrf

        <x-field name="notification_channels" label="Notification channels" group hint="Choose at least one.">
            <div class="grid gap-2 sm:grid-cols-3">
                @foreach ($options['channels'] as $channel => $label)
                    <x-check name="notification_channels[]" :value="$channel" :checked="in_array($channel, $selectedChannels, true)">{{ $label }}</x-check>
                @endforeach
            </div>
        </x-field>

        <x-field name="sms_sender_id" label="SMS sender ID" optional hint="The name patients see on your messages. Up to 11 letters or numbers, no spaces. You can set this later.">
            <x-text-input id="sms_sender_id" name="sms_sender_id" type="text" maxlength="11" :value="$value('sms_sender_id')" autocomplete="off" />
        </x-field>

        <x-wizard-actions :step="$step" />
    </form>
</x-wizard-layout>
