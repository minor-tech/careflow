@props(['src' => null])

{{-- A person's circle: their photo when there is one, otherwise a plain gray silhouette. Never a coloured dot standing in for a person. --}}
<span {{ $attributes->class('cf-person-avatar') }}>
    @if ($src)
        <img src="{{ $src }}" alt="">
    @else
        <x-icon name="person" class="h-6 w-6" />
    @endif
</span>
