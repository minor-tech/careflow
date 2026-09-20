<x-app-layout title="Profile">
    <x-slot name="header">
        <h1 class="text-2xl font-semibold">Profile</h1>
    </x-slot>

    <div class="space-y-6">
        <div class="cf-card">
            <div class="max-w-xl">
                @include('profile.partials.update-profile-information-form')
            </div>
        </div>

        <div class="cf-card">
            <div class="max-w-xl">
                @include('profile.partials.update-password-form')
            </div>
        </div>

        <div class="cf-card">
            <div class="max-w-xl">
                @include('profile.partials.delete-user-form')
            </div>
        </div>
    </div>
</x-app-layout>
