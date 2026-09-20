<x-app-layout title="Add staff">
    <x-slot name="header">
        <h1 class="text-2xl font-semibold">Add staff</h1>
    </x-slot>

    <form method="POST" action="{{ route('staff.store') }}" class="cf-card max-w-xl space-y-5"
          x-data="staffSpecialty({ role: @js((string) old('role')), department: @js((string) old('department_id')), service: @js((string) old('service_id')), services: @js($servicesByDepartment) })">
        @csrf

        <x-field name="name" label="Full name">
            <x-text-input id="name" name="name" type="text" :value="old('name')" required autofocus autocomplete="off" />
        </x-field>

        <x-field name="email" label="Email">
            <x-text-input id="email" name="email" type="email" :value="old('email')" required autocomplete="off" />
        </x-field>

        <x-field name="phone" label="Phone">
            <x-text-input id="phone" name="phone" type="tel" inputmode="tel" :value="old('phone')" required autocomplete="off" />
        </x-field>

        <div class="grid gap-5 sm:grid-cols-2">
            <x-field name="role" label="Role">
                <x-select id="role" name="role" :options="$roles" :selected="old('role')" placeholder="Select a role" required x-model="role" />
            </x-field>

            <x-field name="department_id" label="Department">
                <x-select id="department_id" name="department_id" :options="$departments" :selected="old('department_id')" placeholder="Select a department" required x-model="department" x-on:change="service = ''" />
            </x-field>
        </div>

        <x-field name="service_id" label="Specialty" optional x-show="offersSpecialty" x-cloak hint="What this doctor is seen for. Registration recommends doctors whose specialty matches what the patient came for.">
            <select id="service_id" name="service_id" class="field-input" x-model="service">
                <option value="">General (no specialty)</option>
                <template x-for="option in options" :key="option.id">
                    <option :value="option.id" x-text="option.name"></option>
                </template>
            </select>
        </x-field>

        <p class="text-sm text-ink/60">We'll generate a temporary password and show it to you once, to pass on to them yourself. They'll be asked to choose their own the first time they log in.</p>

        <div class="flex flex-col gap-3 sm:flex-row-reverse sm:items-center sm:justify-between">
            <x-primary-button>Create account</x-primary-button>
            <a href="{{ route('staff.index') }}" class="btn-outline">Cancel</a>
        </div>
    </form>
</x-app-layout>
