@php
    $details = [
        'Type' => $facility->facility_type->label().($facility->ownership_type ? ', '.$facility->ownership_type->label() : ''),
        'License number' => $facility->license_number,
        'Location' => "{$facility->address}, {$facility->sub_county}, {$facility->county}",
        'Phone' => $facility->phone.($facility->alt_phone ? ", {$facility->alt_phone}" : ''),
        'Email' => $facility->email,
        'Website' => $facility->website ?? 'Not set',
        'Operating days' => $facility->operatingDaysLabel(),
        'Hours' => $facility->operatingHoursLabel(),
        'Patient notifications' => collect($facility->notification_channels)->map(fn (string $channel) => \App\Enums\NotificationChannel::tryFrom($channel)?->label() ?? $channel)->join(', '),
        'Data protection role' => $facility->data_role->label(),
    ];

    // Departments take the accent colours in turn: a dot to tell the rows apart, not a meaning.
    $dotColors = ['blue', 'sage', 'gold', 'clay', 'slate'];
@endphp

<x-app-layout title="Facility">
    <x-slot name="header">
        <h1 class="text-2xl font-semibold">{{ $facility->name }}</h1>
    </x-slot>

    <div class="grid gap-4 sm:grid-cols-3">
        <x-stat-card :number="$staffCount" label="Staff accounts" icon="staff" tone="blue" :href="route('staff.index')" />
        <x-stat-card :number="$departmentCount" label="Departments" icon="grid" tone="sage" :href="route('departments.index')" />

        <div class="cf-card flex flex-col justify-center gap-2">
            <p class="text-sm text-ink/70">Status</p>
            <div><x-status-pill :label="$facility->status->label()" :tone="$facility->status->tone()" /></div>
        </div>
    </div>

    <section class="cf-list-card mt-6" aria-labelledby="departments-heading">
        <h2 id="departments-heading" class="cf-list-card__title">Departments</h2>

        @forelse ($departments as $department)
            <div class="cf-list-row">
                <span class="cf-dot cf-dot--{{ $dotColors[$loop->index % count($dotColors)] }}" aria-hidden="true"></span>
                <div class="cf-list-row__main">
                    <p class="truncate font-medium">{{ $department->name }}</p>
                    <p class="text-sm text-ink/70">{{ $department->type->label() }}</p>
                </div>
                <div class="cf-list-row__side">
                    <span><span class="numeral text-base">{{ $department->users_count }}</span> <span class="text-ink/70">staff</span></span>
                    @unless ($department->is_active)
                        <x-status-pill label="Inactive" tone="muted" size="sm" />
                    @endunless
                </div>
            </div>
        @empty
            <p class="cf-list-card__empty">No departments yet. <a href="{{ route('departments.create') }}" class="link">Add the first one</a> to start assigning staff.</p>
        @endforelse
    </section>

    <section class="cf-list-card mt-6" aria-labelledby="details-heading">
        <h2 id="details-heading" class="cf-list-card__title">Facility details</h2>

        <dl>
            @foreach ($details as $label => $value)
                <div class="cf-list-row text-sm">
                    <dt class="w-full text-ink/70 sm:w-1/3">{{ $label }}</dt>
                    <dd class="min-w-0 flex-1 font-medium">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
    </section>
</x-app-layout>
