<x-app-layout title="Register patient">
    <x-slot name="header">
        <h1 class="text-2xl font-semibold">Register patient</h1>
    </x-slot>

    <form method="POST" action="{{ route('patients.register.store') }}" class="cf-card max-w-xl space-y-5"
          x-data="patientRegistration({
              lookupUrl: @js(route('patients.lookup')),
              phone: @js(old('phone')),
              name: @js(old('name')),
              dob: @js(old('dob')),
              gender: @js(old('gender')),
          })"
          x-on:submit="submitting = true"
          x-on:pageshow.window="submitting = false">
        @csrf

        <x-field name="phone" label="Phone">
            <x-text-input id="phone" name="phone" type="tel" inputmode="tel" x-model="phone" x-on:input.debounce.400ms="lookup()" required autofocus autocomplete="off" />
            <p class="text-sm text-ink/70" x-show="knownAs" x-cloak>
                Returning patient: <span class="font-medium text-ink" x-text="knownAs"></span>. Their saved details are filled in below; change anything that is out of date.
            </p>
        </x-field>

        <x-field name="name" label="Full name">
            <x-text-input id="name" name="name" type="text" x-model="name" required autocomplete="off" />
        </x-field>

        <div class="grid gap-5 sm:grid-cols-2">
            <x-field name="dob" label="Date of birth" optional>
                <x-text-input id="dob" name="dob" type="date" x-model="dob" :max="now(config('careflow.timezone'))->toDateString()" min="1900-01-02" />
            </x-field>

            <x-field name="gender" label="Gender" optional>
                <x-select id="gender" name="gender" :options="$genders" :selected="old('gender')" placeholder="Not stated" x-model="gender" />
            </x-field>
        </div>

        <x-field name="department_id" label="Service or department">
            <x-select id="department_id" name="department_id" :options="$departments" :selected="old('department_id', $receptionId)" :placeholder="$receptionId ? null : 'No department yet'" />
        </x-field>

        <x-primary-button class="w-full sm:w-auto" x-bind:disabled="submitting">Register patient</x-primary-button>
    </form>
</x-app-layout>
