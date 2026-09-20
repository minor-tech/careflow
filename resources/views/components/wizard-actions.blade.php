@props(['step', 'label' => 'Continue'])

{{-- The primary button comes first in the DOM so Enter always submits it, never a secondary "Skip". --}}
<div class="mt-8 flex flex-col gap-3 sm:flex-row-reverse sm:items-center sm:justify-between">
    <x-primary-button>{{ $label }}</x-primary-button>

    <div class="flex items-center gap-4">
        @if ($step > 1)
            <a href="{{ route('facility.register.step', $step - 1) }}" class="btn-outline">Back</a>
        @endif
        {{ $slot }}
    </div>
</div>
