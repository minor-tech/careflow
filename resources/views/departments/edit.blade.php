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

        <div class="flex flex-col gap-3 sm:flex-row-reverse sm:items-center sm:justify-between">
            <x-primary-button>Save changes</x-primary-button>
            <a href="{{ route('departments.index') }}" class="btn-outline">Cancel</a>
        </div>
    </form>
</x-app-layout>
