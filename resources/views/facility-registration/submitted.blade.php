<x-guest-layout title="Registration received">
    <div class="space-y-4">
        <h1 class="text-2xl font-semibold">Registration received</h1>

        @if ($facilityName)
            <p class="text-sm text-ink/70">{{ $facilityName }}</p>
        @endif

        <p>Your facility registration is under review. You'll receive a confirmation within 24 hours.</p>

        <p class="text-sm text-ink/70">Until then your team can't use CareFlow. The admin can log in to see the status of the review.</p>

        <a href="{{ route('login') }}" class="link inline-flex min-h-11 items-center text-sm">Log in to check status</a>
    </div>
</x-guest-layout>
