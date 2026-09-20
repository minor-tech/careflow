@props(['number', 'label', 'icon', 'tone' => 'primary', 'href' => null])

{{-- A pastel tile with one big number. $tone is primary, blue, gold, clay, slate or sage. Pass $href to make the whole card a link. --}}
<{{ $href ? 'a' : 'div' }}@if ($href) href="{{ $href }}"@endif {{ $attributes->class(['cf-stat-card', 'cf-stat-card--'.$tone]) }}>
    <div class="cf-stat-card__icon"><x-icon :name="$icon" /></div>
    <div>
        <div class="cf-stat-card__number">{{ $number }}</div>
        <div class="cf-stat-card__label">{{ $label }}</div>
    </div>
</{{ $href ? 'a' : 'div' }}>
