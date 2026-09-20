@php
    $selectedDays = (array) $value('operating_days', ['mon', 'tue', 'wed', 'thu', 'fri']);
    $selectedDepartments = (array) $value('departments', []);
    $isAllDay = filter_var($value('is_24hr', false), FILTER_VALIDATE_BOOLEAN);
@endphp

<x-wizard-layout :steps="$steps" :step="$step" :completed="$completed">
    <form method="POST" action="{{ route('facility.register.save', $step) }}" class="mt-6 space-y-6" x-data="{ allDay: @js($isAllDay) }">
        @csrf

        <x-field name="operating_days" label="Operating days" group>
            <div class="grid grid-cols-3 gap-2 sm:grid-cols-4">
                @foreach ($options['days'] as $day => $label)
                    <x-check name="operating_days[]" :value="$day" :checked="in_array($day, $selectedDays, true)">{{ $label }}</x-check>
                @endforeach
            </div>
        </x-field>

        <div class="space-y-4">
            <input type="hidden" name="is_24hr" value="0">
            <x-check name="is_24hr" value="1" x-model="allDay" :checked="$isAllDay">Open 24 hours</x-check>

            <div class="grid grid-cols-2 gap-3" x-show="! allDay">
                <x-field name="opens_at" label="Opens at">
                    <x-text-input id="opens_at" name="opens_at" type="time" :value="$value('opens_at', '08:00')" x-bind:required="! allDay" x-bind:disabled="allDay" />
                </x-field>
                <x-field name="closes_at" label="Closes at">
                    <x-text-input id="closes_at" name="closes_at" type="time" :value="$value('closes_at', '17:00')" x-bind:required="! allDay" x-bind:disabled="allDay" />
                </x-field>
            </div>
        </div>

        <x-field name="departments" label="Departments and services offered" group hint="Pick everything you run. You can add or rename departments later.">
            <div class="grid gap-2 sm:grid-cols-2">
                @foreach ($options['departmentTypes'] as $type => $label)
                    <x-check name="departments[]" :value="$type" :checked="in_array($type, $selectedDepartments, true)">{{ $label }}</x-check>
                @endforeach
            </div>
        </x-field>

        <div class="grid grid-cols-2 items-end gap-3">
            <x-field name="doctors_count" label="Doctors on staff" optional>
                <x-text-input id="doctors_count" name="doctors_count" type="number" inputmode="numeric" min="0" :value="$value('doctors_count')" />
            </x-field>
            <x-field name="consultation_rooms" label="Consultation rooms" optional>
                <x-text-input id="consultation_rooms" name="consultation_rooms" type="number" inputmode="numeric" min="0" :value="$value('consultation_rooms')" />
            </x-field>
        </div>

        <x-wizard-actions :step="$step" />
    </form>
</x-wizard-layout>
