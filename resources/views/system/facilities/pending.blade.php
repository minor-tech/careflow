<x-app-layout title="Pending facilities">
    <x-slot name="header">
        <div class="flex items-baseline gap-3">
            <h1 class="text-2xl font-semibold">Pending facilities</h1>
            <span class="numeral" aria-label="{{ $facilities->total() }} waiting">{{ $facilities->total() }}</span>
        </div>
        <p class="mt-1 text-sm text-ink/70">Registrations waiting for you to verify and approve, newest first.</p>
    </x-slot>

    <div class="cf-list-card">
        @forelse ($facilities as $facility)
            <div class="cf-list-row">
                <span class="cf-dot cf-dot--wait" aria-hidden="true"></span>
                <div class="cf-list-row__main">
                    <a href="{{ route('system.facilities.show', $facility) }}" class="link block truncate text-base">{{ $facility->name }}</a>
                    <p class="text-sm text-ink/70">{{ $facility->facility_type->label() }} &middot; {{ $facility->county }} &middot; License {{ $facility->license_number }}</p>
                    <p class="truncate text-sm text-ink/70">
                        @if ($facility->admin)
                            {{ $facility->admin->name }} &middot; {{ $facility->admin->email }}
                        @else
                            No admin account
                        @endif
                    </p>
                </div>

                <div class="cf-list-row__side">
                    <span class="text-ink/70" title="{{ $facility->created_at->format('j M Y, g:i A') }}">Submitted {{ $facility->created_at->format('j M Y') }}</span>
                    <a href="{{ route('system.facilities.show', $facility) }}" class="btn-outline btn-sm">Review</a>
                </div>
            </div>
        @empty
            <p class="cf-list-card__empty">No facilities are waiting for review.</p>
        @endforelse
    </div>

    @if ($facilities->hasPages())
        <div class="mt-6">{{ $facilities->links() }}</div>
    @endif
</x-app-layout>
