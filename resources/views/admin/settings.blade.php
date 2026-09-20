<x-app-layout title="Settings">
    <x-slot name="header">
        <h1 class="text-2xl font-semibold">Settings</h1>
    </x-slot>

    <div class="cf-card max-w-2xl">
        <p class="max-w-prose text-ink/70">Facility settings such as notification channels and opening hours will be editable here in a later sprint. To change your own password or details, use your <a href="{{ route('profile.edit') }}" class="link">profile</a>.</p>
    </div>
</x-app-layout>
