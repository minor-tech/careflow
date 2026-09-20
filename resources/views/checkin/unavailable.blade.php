<x-guest-layout :title="'Check in · '.$facility->name">
    <p class="text-sm text-ink/70">{{ $facility->name }}</p>
    <h1 class="mt-1 text-2xl font-semibold">Please go to the front desk</h1>
    <p class="mt-2 text-ink/70">Checking in on your own phone isn't available here. Staff at the front desk will register you.</p>
</x-guest-layout>
