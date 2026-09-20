@props(['label', 'tone' => 'ok', 'size' => 'md'])

{{-- The one look for every status. $tone is ok, wait, info, danger or muted (the enums' tone() methods give it); $size "sm" is for use inside list rows. --}}
<span {{ $attributes->class(['cf-status-pill', 'cf-status-pill--'.$tone, 'cf-status-pill--sm' => $size === 'sm']) }}>{{ $label }}</span>
