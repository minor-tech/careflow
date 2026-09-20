<x-app-layout title="Register patient">
    <x-slot name="header">
        <h1 class="text-2xl font-semibold">Register patient</h1>
    </x-slot>

    <form method="POST" action="{{ route('patients.register.store') }}" class="cf-card max-w-xl space-y-5"
          x-data="patientRegistration({
              lookupUrl: @js(route('patients.lookup')),
              doctorsUrl: @js(route('patients.doctors')),
              assignmentDepartments: @js($assignmentDepartments),
              phone: @js(old('phone')),
              name: @js(old('name')),
              dob: @js(old('dob')),
              gender: @js(old('gender')),
              departmentId: @js((string) old('department_id', $receptionId)),
              serviceId: @js((string) old('service_id')),
              doctorId: @js((string) old('doctor_id')),
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
            <x-select id="department_id" name="department_id" :options="$departments" :selected="old('department_id', $receptionId)" :placeholder="$receptionId ? null : 'No department yet'" x-model="departmentId" x-on:change="departmentChanged()" />
        </x-field>

        {{-- Only where the department gives each patient their own doctor. --}}
        <div x-show="requiresDoctor" x-cloak class="space-y-5">
            <x-field name="service_id" label="What is the patient here for?" optional x-show="services.length > 0" x-cloak>
                <select id="service_id" name="service_id" class="field-input" x-model="serviceId" x-on:change="loadDoctors()">
                    <option value="">Any service</option>
                    <template x-for="service in services" :key="service.id">
                        <option :value="service.id" x-text="service.name"></option>
                    </template>
                </select>
            </x-field>

            <fieldset class="space-y-1.5">
                <legend class="mb-1.5 block text-sm font-medium text-ink">Recommended doctors</legend>
                <p class="text-sm text-ink/60">Doctors on duty, shortest line first. The recommendation is only a suggestion: choose whoever is right for this patient.</p>

                <div class="cf-list-card mt-2">
                    <template x-for="doctor in doctors" :key="doctor.id">
                        <label class="cf-list-row cursor-pointer has-[:checked]:bg-tint-mint-soft">
                            <input type="radio" name="doctor_id" :value="doctor.id" x-model="doctorId" class="h-4 w-4 border-line text-primary focus:ring-primary">
                            <span class="cf-dot" :class="loadDot(doctor.waiting)" aria-hidden="true"></span>
                            <div class="cf-list-row__main">
                                <p class="font-medium">
                                    <span x-text="doctor.name"></span>
                                    <span x-show="doctor.specialty" class="font-normal text-ink/60" x-text="'· ' + doctor.specialty"></span>
                                </p>
                                <p class="text-sm text-ink/70">
                                    <span x-text="doctor.waiting === 1 ? '1 patient waiting' : doctor.waiting + ' patients waiting'"></span>
                                    &middot; <span x-text="doctor.waiting === 0 ? doctor.estimate : 'Est. ' + doctor.estimate"></span>
                                </p>
                            </div>
                            <span x-show="doctor.recommended" class="cf-status-pill cf-status-pill--ok cf-status-pill--sm">Recommended</span>
                        </label>
                    </template>

                    <p class="cf-list-card__empty" x-show="doctors.length === 0 && ! doctorsError">No doctor is on duty for this department right now. Ask a doctor to switch on duty, or register the patient at Reception.</p>
                    <p class="cf-list-card__empty" x-show="doctorsError">Couldn't load the doctors. <button type="button" class="link" x-on:click="loadDoctors()">Try again</button></p>
                </div>

                <x-input-error :messages="$errors->get('doctor_id')" />
            </fieldset>
        </div>

        <x-primary-button class="w-full sm:w-auto" x-bind:disabled="submitting || (requiresDoctor && ! doctorId)">
            <span x-text="submitLabel">Register patient</span>
        </x-primary-button>
    </form>
</x-app-layout>
