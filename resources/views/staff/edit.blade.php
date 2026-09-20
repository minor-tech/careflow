<x-app-layout title="Edit staff">
    <x-slot name="header">
        <h1 class="text-2xl font-semibold">{{ $member->name }}</h1>
        <p class="text-sm text-ink/60">{{ $member->email }}</p>
    </x-slot>

    <form method="POST" action="{{ route('staff.update', $member) }}" class="cf-card max-w-xl space-y-5">
        @csrf
        @method('PATCH')

        <x-field name="name" label="Full name">
            <x-text-input id="name" name="name" type="text" :value="old('name', $member->name)" required autofocus autocomplete="off" />
        </x-field>

        <x-field name="phone" label="Phone">
            <x-text-input id="phone" name="phone" type="tel" inputmode="tel" :value="old('phone', $member->phone)" required autocomplete="off" />
        </x-field>

        <div class="grid gap-5 sm:grid-cols-2">
            <x-field name="role" label="Role">
                <x-select id="role" name="role" :options="$roles" :selected="old('role', $member->role->value)" required />
            </x-field>

            <x-field name="department_id" label="Department">
                <x-select id="department_id" name="department_id" :options="$departments" :selected="old('department_id', $member->department_id)" placeholder="Select a department" required />
            </x-field>
        </div>

        <x-field name="status" label="Account status" hint="A suspended person can't log in, but their account and history are kept.">
            <x-select id="status" name="status" :options="\App\Enums\UserStatus::options()" :selected="old('status', $member->status->value)" required />
        </x-field>

        <div class="flex flex-col gap-3 sm:flex-row-reverse sm:items-center sm:justify-between">
            <x-primary-button>Save changes</x-primary-button>
            <a href="{{ route('staff.index') }}" class="btn-outline">Cancel</a>
        </div>
    </form>

    <section class="cf-card mt-6 max-w-xl">
        <h2 class="text-base font-semibold">Lost or forgotten password</h2>
        <p class="mt-1 text-sm text-ink/70">Make a new temporary password for {{ $member->name }}. It's shown to you once, to pass on. They'll be signed out everywhere and asked to choose their own password when they next log in.</p>

        <form method="POST" action="{{ route('staff.reset-password', $member) }}" class="mt-4"
              onsubmit="return confirm('Reset {{ e(addslashes($member->name)) }}\'s password? Their current password will stop working straight away.')">
            @csrf
            <x-outline-button type="submit">Reset password</x-outline-button>
        </form>
    </section>
</x-app-layout>
