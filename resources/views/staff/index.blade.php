<x-app-layout title="Staff">
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex items-baseline gap-3">
                <h1 class="text-2xl font-semibold">Staff</h1>
                <span class="numeral" aria-label="{{ $staff->count() }} accounts">{{ $staff->count() }}</span>
            </div>
            <x-primary-button :href="route('staff.create')">Add staff</x-primary-button>
        </div>
    </x-slot>

    @if ($revealed)
        {{-- The only time this password is ever shown: it isn't stored anywhere readable, and a reload won't bring it back. --}}
        <section class="cf-card mb-6 border-l-4 border-ok" role="status" x-data="{ copied: false }">
            <p class="font-medium">
                @if ($revealed['created'])
                    Account created for {{ $revealed['name'] }}.
                @else
                    New temporary password for {{ $revealed['name'] }}.
                @endif
            </p>

            <p class="mt-3 text-sm text-ink/70">Temporary password</p>
            <div class="mt-1 flex flex-wrap items-center gap-x-4 gap-y-2">
                <code id="temporary-password" x-ref="password" class="select-all rounded-md border border-line bg-line/40 px-3 py-2 font-mono text-xl font-semibold">{{ $revealed['password'] }}</code>
                <button type="button" class="btn-outline btn-sm"
                        x-on:click="navigator.clipboard.writeText($refs.password.textContent.trim()).then(() => copied = true).catch(() => {})">
                    <span x-text="copied ? 'Copied' : 'Copy'">Copy</span>
                </button>
            </div>

            <p class="mt-3 text-sm">Share this with {{ $revealed['name'] }} directly. <strong>It won't be shown again.</strong></p>
            <p class="text-sm text-ink/70">They'll be asked to choose their own password when they first log in.</p>
        </section>
    @endif

    @if ($errors->has('staff'))
        <div role="alert" class="mb-6 rounded-2xl border-l-4 border-danger bg-white px-4 py-3 text-sm shadow-card">
            {{ $errors->first('staff') }}
        </div>
    @endif

    <div class="cf-person-list">
        @foreach ($staff as $member)
            <article class="cf-person-card">
                <x-avatar />

                <div class="cf-person-card__who">
                    <p class="truncate font-bold">{{ $member->name }}</p>
                    <p class="truncate text-sm text-ink/70">{{ $member->email }} &middot; {{ $member->phone }}</p>
                    @if ($member->must_change_password)
                        <p class="text-sm text-ink/70">Hasn't set their own password yet</p>
                    @endif
                </div>

                <div class="cf-person-card__role">
                    <p class="font-bold">{{ $member->role->label() }}</p>
                    @if ($member->department)
                        <p class="text-sm text-accent-blue">{{ $member->department->name }}</p>
                    @else
                        <p class="text-sm text-ink/70">No department</p>
                    @endif
                    @if ($member->isDoctor() && $member->service)
                        <p class="text-sm text-ink/70">{{ $member->service->name }}</p>
                    @endif
                </div>

                <div class="cf-person-card__status">
                    <x-status-pill :label="$member->status->label()" :tone="$member->status->tone()" />
                    @if ($member->isDoctor() && ! $member->isSuspended())
                        <x-status-pill :label="$member->isOnDuty() ? 'On duty' : 'Off duty'" :tone="$member->isOnDuty() ? 'ok' : 'muted'" size="sm" class="mt-1" />
                    @endif
                </div>

                <div class="cf-person-card__actions">
                    @if ($member->isDoctor() && ! $member->isSuspended())
                        <form method="POST" action="{{ route('staff.duty', $member) }}">
                            @csrf
                            <input type="hidden" name="on_duty" value="{{ $member->isOnDuty() ? 0 : 1 }}">
                            <x-outline-button type="submit" class="btn-sm">{{ $member->isOnDuty() ? 'Set off duty' : 'Set on duty' }}</x-outline-button>
                        </form>
                    @endif
                    @unless ($member->isAdmin())
                        <a href="{{ route('staff.edit', $member) }}" class="btn-outline btn-sm">Edit</a>
                        @unless ($member->isSuspended())
                            <form method="POST" action="{{ route('staff.destroy', $member) }}"
                                  onsubmit="return confirm('Suspend {{ e(addslashes($member->name)) }}? They will no longer be able to log in. Their history is kept.')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn-danger btn-sm">Suspend</button>
                            </form>
                        @endunless
                    @endunless
                </div>
            </article>
        @endforeach
    </div>
</x-app-layout>
