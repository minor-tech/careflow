@props(['name', 'label', 'hint' => null, 'optional' => false, 'id' => null, 'group' => false])

@php
    $id ??= str_replace(['.', '[', ']'], '-', $name);
    $labelClasses = 'block text-sm font-medium text-ink';
@endphp

{{-- A group (checkbox list) is a fieldset; a single control is a label + input. --}}
<{{ $group ? 'fieldset' : 'div' }} {{ $attributes->class('space-y-1.5') }}>
    @if ($group)
        <legend class="{{ $labelClasses }} mb-1.5">
    @else
        <label for="{{ $id }}" class="{{ $labelClasses }}">
    @endif
        {{ $label }}
        @if ($optional)
            <span class="font-normal text-ink/60">(optional)</span>
        @endif
    @if ($group)
        </legend>
    @else
        </label>
    @endif

    {{ $slot }}

    @if ($hint)
        <p class="text-sm text-ink/60">{{ $hint }}</p>
    @endif

    <x-input-error :messages="$errors->get($name)" />
</{{ $group ? 'fieldset' : 'div' }}>
