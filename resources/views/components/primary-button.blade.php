@props(['href' => null])

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => 'btn-primary']) }}>{{ $slot }}</a>
@else
    <button {{ $attributes->merge(['type' => 'submit', 'class' => 'btn-primary']) }}>
        {{ $slot }}
    </button>
@endif
