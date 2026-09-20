@props(['href' => null])

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => 'btn-outline']) }}>{{ $slot }}</a>
@else
    <button {{ $attributes->merge(['type' => 'button', 'class' => 'btn-outline']) }}>
        {{ $slot }}
    </button>
@endif
