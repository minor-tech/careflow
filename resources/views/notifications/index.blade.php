@php
    $tabs = [
        ['label' => 'All', 'status' => null, 'count' => $counts['all']],
        ['label' => 'Failed', 'status' => 'failed', 'count' => $counts['failed']],
        ['label' => 'Queued', 'status' => 'queued', 'count' => $counts['queued']],
        ['label' => 'Sent', 'status' => 'sent', 'count' => $counts['sent']],
    ];
    $clinicTimezone = config('careflow.timezone');
@endphp

<x-app-layout title="Notifications">
    <x-slot name="header">
        <h1 class="text-2xl font-semibold">Notifications</h1>
        <p class="mt-1 text-sm text-ink/70">The messages sent to patients about their visits, newest first.</p>
    </x-slot>

    @if ($looksStuck)
        <div role="alert" class="mb-6 rounded-2xl border-l-4 border-wait bg-white px-4 py-3 text-sm shadow-card">
            Some messages have been waiting to send for more than 5 minutes. Check that the queue worker is running (<code class="font-mono">php artisan queue:work</code>).
        </div>
    @endif

    <nav aria-label="Filter by status" class="-mx-1 mb-4 flex gap-1 overflow-x-auto pb-1">
        @foreach ($tabs as $tab)
            @php
                $isActive = ($status?->value) === $tab['status'];
            @endphp
            <a href="{{ route('notifications.index', array_filter(['status' => $tab['status']])) }}"
               @if ($isActive) aria-current="page" @endif
               class="flex min-h-11 shrink-0 items-center gap-2 rounded-md px-3 text-sm font-medium {{ $isActive ? 'bg-tint-mint text-primary' : 'text-ink hover:bg-tint-mint-soft' }}">
                {{ $tab['label'] }}
                <span class="tabular-nums {{ $isActive ? '' : 'text-ink/60' }}">{{ $tab['count'] }}</span>
            </a>
        @endforeach
    </nav>

    <div class="cf-list-card">
        @forelse ($notifications as $notification)
            @php
                $when = ($notification->sent_at ?? $notification->created_at)->timezone($clinicTimezone)->format('j M, g:i A');
            @endphp
            <div class="cf-list-row items-start">
                <span class="cf-dot cf-dot--{{ $notification->status->tone() }} mt-2" aria-hidden="true"></span>
                <div class="cf-list-row__main">
                    <div class="flex flex-col gap-1 sm:flex-row sm:items-baseline sm:justify-between sm:gap-6">
                        <p class="min-w-0 break-words">{{ $notification->message }}</p>
                        <p class="flex shrink-0 items-center gap-2 text-sm text-ink/70">
                            <x-status-pill :label="$notification->status->label()" :tone="$notification->status->tone()" size="sm" />
                            <span class="tabular-nums">{{ $when }}</span>
                        </p>
                    </div>
                    <p class="mt-1 text-sm text-ink/70">
                        {{ $notification->patient?->name ?? 'Not a patient' }} &middot; <span class="tabular-nums">{{ $notification->recipientPhone() }}</span>
                    </p>

                    @if ($notification->status === \App\Enums\NotificationStatus::Failed && $notification->provider_response)
                        <details class="mt-2 text-sm">
                            <summary class="link inline-flex min-h-11 cursor-pointer items-center">Why it failed</summary>
                            <p class="mt-1 break-words rounded-md border border-line bg-line/40 px-3 py-2 font-mono text-xs">{{ $notification->provider_response }}</p>
                        </details>
                    @endif
                </div>
            </div>
        @empty
            <p class="cf-list-card__empty">
                @if ($status)
                    No {{ strtolower($status->label()) }} messages.
                @else
                    No messages yet. They appear here as patients are registered, called, moved and finished.
                @endif
            </p>
        @endforelse
    </div>

    @if ($notifications->hasPages())
        <div class="mt-6">{{ $notifications->links() }}</div>
    @endif
</x-app-layout>
