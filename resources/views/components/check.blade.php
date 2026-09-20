@props(['checked' => false, 'type' => 'checkbox'])

@php
    $inputAttributes = ['name', 'value', 'x-model', 'x-bind:disabled', 'x-on:change'];
@endphp

<label {{ $attributes->except($inputAttributes)->class('flex min-h-11 cursor-pointer items-start gap-3 rounded-md border border-line bg-white px-3 py-2.5 text-sm text-ink has-[:checked]:border-primary') }}>
    <input type="{{ $type }}" @checked($checked) {{ $attributes->only($inputAttributes)->merge(['class' => 'mt-0.5 h-4 w-4 '.($type === 'radio' ? 'rounded-full' : 'rounded').' border-line text-primary focus:ring-primary']) }}>
    <span>{{ $slot }}</span>
</label>
