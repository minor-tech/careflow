@php
    // Departments take the accent colours in turn: a dot to tell the rows apart, not a meaning.
    $dotColors = ['blue', 'sage', 'gold', 'clay', 'slate'];
@endphp

<x-app-layout title="Departments">
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex items-baseline gap-3">
                <h1 class="text-2xl font-semibold">Departments</h1>
                <span class="numeral" aria-label="{{ $departments->count() }} departments">{{ $departments->count() }}</span>
            </div>
            <x-primary-button :href="route('departments.create')">Add department</x-primary-button>
        </div>
    </x-slot>

    @if ($errors->has('department'))
        <div role="alert" class="mb-6 rounded-2xl border-l-4 border-danger bg-white px-4 py-3 text-sm shadow-card">
            {{ $errors->first('department') }}
        </div>
    @endif

    <div class="cf-list-card">
        @forelse ($departments as $department)
            <div class="cf-list-row">
                <span class="cf-dot cf-dot--{{ $dotColors[$loop->index % count($dotColors)] }}" aria-hidden="true"></span>
                <div class="cf-list-row__main">
                    <p class="truncate font-medium">{{ $department->name }}</p>
                    <p class="text-sm text-ink/70">{{ $department->type->label() }}</p>
                </div>

                <div class="cf-list-row__side">
                    <span><span class="numeral text-base">{{ $department->users_count }}</span> <span class="text-ink/70">staff</span></span>
                    <x-status-pill :label="$department->is_active ? 'Active' : 'Inactive'" :tone="$department->is_active ? 'ok' : 'muted'" size="sm" />
                    <a href="{{ route('departments.edit', $department) }}" class="btn-outline btn-sm">Edit</a>
                    <form method="POST" action="{{ route('departments.destroy', $department) }}"
                          onsubmit="return confirm('Remove {{ e(addslashes($department->name)) }}?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn-danger btn-sm">Remove</button>
                    </form>
                </div>
            </div>
        @empty
            <p class="cf-list-card__empty">No departments yet. Add the first one to start assigning staff.</p>
        @endforelse
    </div>
</x-app-layout>
