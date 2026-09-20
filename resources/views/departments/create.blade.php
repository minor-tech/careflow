<x-app-layout title="Add department">
    <x-slot name="header">
        <h1 class="text-2xl font-semibold">Add department</h1>
    </x-slot>

    <form method="POST" action="{{ route('departments.store') }}" class="cf-card max-w-xl space-y-5">
        @csrf

        <x-field name="name" label="Name">
            <x-text-input id="name" name="name" type="text" :value="old('name')" required autofocus autocomplete="off" />
        </x-field>

        <x-field name="type" label="Type" hint="Used to decide which screens this department's staff see later on.">
            <x-select id="type" name="type" :options="$types" :selected="old('type')" placeholder="Select a type" required />
        </x-field>

        <div>
            <input type="hidden" name="is_active" value="0">
            <x-check name="is_active" value="1" :checked="old('is_active', true)">Active</x-check>
        </div>

        <div class="flex flex-col gap-3 sm:flex-row-reverse sm:items-center sm:justify-between">
            <x-primary-button>Add department</x-primary-button>
            <a href="{{ route('departments.index') }}" class="btn-outline">Cancel</a>
        </div>
    </form>
</x-app-layout>
