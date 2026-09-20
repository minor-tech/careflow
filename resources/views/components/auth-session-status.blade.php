@props(['status'])

@if ($status)
    <div {{ $attributes->merge(['class' => 'text-sm font-medium text-ok']) }}>
        {{ $status }}
    </div>
@endif
