<x-app-layout title="Edit department">
    <x-slot name="header">
        <h1 class="text-2xl font-semibold">{{ $department->name }}</h1>
    </x-slot>

    <form method="POST" action="{{ route('departments.update', $department) }}" class="cf-card max-w-xl space-y-5">
        @csrf
        @method('PATCH')

        <x-field name="name" label="Name">
            <x-text-input id="name" name="name" type="text" :value="old('name', $department->name)" required autofocus autocomplete="off" />
        </x-field>

        <x-field name="type" label="Type">
            <x-select id="type" name="type" :options="$types" :selected="old('type', $department->type->value)" required />
        </x-field>

        <div>
            <input type="hidden" name="is_active" value="0">
            <x-check name="is_active" value="1" :checked="old('is_active', $department->is_active)">Active</x-check>
        </div>

        <div>
            <input type="hidden" name="requires_doctor_assignment" value="0">
            <x-check name="requires_doctor_assignment" value="1" :checked="old('requires_doctor_assignment', $department->requires_doctor_assignment)">Give each patient their own doctor</x-check>
            <p class="mt-1.5 text-sm text-ink/60">Reception chooses a doctor when registering the patient, and each doctor works their own line instead of one shared queue. Leave off for Laboratory, Pharmacy and the like. Patients already waiting when you switch it on have no doctor until an admin assigns one from the queue.</p>
        </div>

        <div class="flex flex-col gap-3 sm:flex-row-reverse sm:items-center sm:justify-between">
            <x-primary-button>Save changes</x-primary-button>
            <a href="{{ route('departments.index') }}" class="btn-outline">Cancel</a>
        </div>
    </form>

    <section class="cf-card mt-6 max-w-xl" aria-labelledby="services-heading">
        <h2 id="services-heading" class="text-base font-semibold">Services</h2>
        <p class="mt-1 text-sm text-ink/70">What patients can be seen for here, such as General Medicine or Pediatrics. A doctor's specialty is one of these, and it narrows which doctors are recommended at registration.</p>

        @if ($errors->has('service'))
            <p role="alert" class="mt-3 text-sm text-danger">{{ $errors->first('service') }}</p>
        @endif

        <ul class="mt-3 divide-y divide-line">
            @forelse ($department->services as $service)
                <li class="flex items-center justify-between gap-4 py-2.5">
                    <span class="min-w-0 break-words">{{ $service->name }}
                        <span class="text-sm text-ink/60">&middot; {{ $service->doctors_count }} {{ $service->doctors_count === 1 ? 'doctor' : 'doctors' }}</span>
                    </span>
                    <form method="POST" action="{{ route('services.destroy', $service) }}"
                          onsubmit="return confirm('Remove {{ e(addslashes($service->name)) }}?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn-danger btn-sm">Remove</button>
                    </form>
                </li>
            @empty
                <li class="py-2.5 text-sm text-ink/60">No services yet. Patients are then registered for the department as a whole.</li>
            @endforelse
        </ul>

        <form method="POST" action="{{ route('departments.services.store', $department) }}" class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-start">
            @csrf
            <div class="min-w-0 flex-1">
                <label for="service-name" class="sr-only">New service name</label>
                <x-text-input id="service-name" name="name" type="text" placeholder="e.g. Pediatrics" :value="old('name')" required autocomplete="off" />
                <x-input-error :messages="$errors->get('name')" class="mt-1.5" />
            </div>
            <x-outline-button type="submit">Add service</x-outline-button>
        </form>
    </section>
</x-app-layout>
