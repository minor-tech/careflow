@php
    $rows = array_values((array) $value('staff', []));
    $chosenDepartments = collect((array) $value('departments', []))
        ->mapWithKeys(fn (string $type) => [$type => $options['departmentTypes'][$type] ?? $type])
        ->all();
@endphp

<x-wizard-layout :steps="$steps" :step="$step" :completed="$completed">
    <p class="mt-2 text-sm text-ink/70">Add the people who will use CareFlow. They each get a temporary password once your facility is approved. You can skip this and add staff from the dashboard later.</p>

    <form method="POST" action="{{ route('facility.register.save', $step) }}" class="mt-6 space-y-5"
          x-data="staffRows(@js($rows), @js($errors->getMessages()))">
        @csrf

        <template x-for="(row, i) in rows" :key="row.key">
            <fieldset class="space-y-3 rounded-md border border-line bg-white p-4">
                <div class="flex items-center justify-between">
                    <legend class="text-sm font-medium">Staff member <span class="tabular-nums" x-text="i + 1"></span></legend>
                    <button type="button" class="btn-danger btn-sm" x-on:click="remove(i)">Remove</button>
                </div>

                <div class="space-y-1.5">
                    <label class="block text-sm font-medium" :for="`staff-${row.key}-name`">Full name</label>
                    <input type="text" class="field-input" :id="`staff-${row.key}-name`" :name="`staff[${i}][name]`" x-model="row.name" autocomplete="off">
                    <p class="text-sm text-danger" x-text="error(i, 'name')"></p>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="space-y-1.5">
                        <label class="block text-sm font-medium" :for="`staff-${row.key}-email`">Email</label>
                        <input type="email" class="field-input" :id="`staff-${row.key}-email`" :name="`staff[${i}][email]`" x-model="row.email" autocomplete="off">
                        <p class="text-sm text-danger" x-text="error(i, 'email')"></p>
                    </div>
                    <div class="space-y-1.5">
                        <label class="block text-sm font-medium" :for="`staff-${row.key}-phone`">Phone</label>
                        <input type="tel" inputmode="tel" class="field-input" :id="`staff-${row.key}-phone`" :name="`staff[${i}][phone]`" x-model="row.phone" autocomplete="off">
                        <p class="text-sm text-danger" x-text="error(i, 'phone')"></p>
                    </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="space-y-1.5">
                        <label class="block text-sm font-medium" :for="`staff-${row.key}-role`">Role</label>
                        <select class="field-input" :id="`staff-${row.key}-role`" :name="`staff[${i}][role]`" x-model="row.role">
                            <option value="">Select a role</option>
                            @foreach ($options['staffRoles'] as $role => $label)
                                <option value="{{ $role }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="text-sm text-danger" x-text="error(i, 'role')"></p>
                    </div>
                    <div class="space-y-1.5">
                        <label class="block text-sm font-medium" :for="`staff-${row.key}-department`">Department <span class="font-normal text-ink/60">(optional)</span></label>
                        <select class="field-input" :id="`staff-${row.key}-department`" :name="`staff[${i}][department]`" x-model="row.department">
                            <option value="">Assign later</option>
                            @foreach ($chosenDepartments as $type => $label)
                                <option value="{{ $type }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="text-sm text-danger" x-text="error(i, 'department')"></p>
                    </div>
                </div>
            </fieldset>
        </template>

        <x-input-error :messages="$errors->get('staff')" />

        <button type="button" class="btn-outline" x-on:click="add()" x-show="rows.length < 20">
            + <span x-text="rows.length === 0 ? 'Invite a staff member' : 'Add another'"></span>
        </button>

        <x-wizard-actions :step="$step">
            <button type="submit" name="skip" value="1" formnovalidate class="btn-outline">Skip for now</button>
        </x-wizard-actions>
    </form>
</x-wizard-layout>
