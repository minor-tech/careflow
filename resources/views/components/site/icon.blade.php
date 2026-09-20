@props(['name'])

{{-- A small set of plain line icons drawn for this site: simple shapes on a 24px grid, white by default (they sit on coloured circles). --}}
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" {{ $attributes->class('h-7 w-7') }}>
    @switch($name)
        @case('clock')
            <circle cx="12" cy="12" r="9"/>
            <path d="M12 7v5l3 2"/>
            @break

        @case('question')
            <circle cx="12" cy="12" r="9"/>
            <path d="M9.6 9.5a2.5 2.5 0 1 1 3.6 2.2c-.8.4-1.2.9-1.2 1.8"/>
            <circle cx="12" cy="16.8" r=".6" fill="currentColor"/>
            @break

        @case('phone-off')
            <rect x="7" y="3" width="10" height="18" rx="2"/>
            <path d="M11 18h2"/>
            <path d="M3.5 3.5l17 17"/>
            @break

        @case('queue')
            <path d="M9 6h11M9 12h11M9 18h11"/>
            <circle cx="4.5" cy="6" r="1"/>
            <circle cx="4.5" cy="12" r="1"/>
            <circle cx="4.5" cy="18" r="1"/>
            @break

        @case('pin')
            <path d="M12 21s-6-5.2-6-10a6 6 0 1 1 12 0c0 4.8-6 10-6 10z"/>
            <circle cx="12" cy="11" r="2"/>
            @break

        @case('message')
            <path d="M4 5h16v11H9l-5 4V5z"/>
            @break

        @case('chart')
            <path d="M5 20V11M12 20V4M19 20v-6"/>
            @break

        @case('shield')
            <path d="M12 3l7 3v5c0 5-3 8-7 10-4-2-7-5-7-10V6l7-3z"/>
            <path d="M9 12l2 2 4-4"/>
            @break

        @case('link')
            <path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/>
            <path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>
            @break

        @case('check')
            <path d="M5 12.5l4.2 4.2L19 7"/>
            @break

        @case('eye')
            <path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/>
            <circle cx="12" cy="12" r="2.6"/>
            @break

        @case('hourglass')
            <path d="M7 3h10M7 21h10M8 3v3.5L12 12l4-5.5V3M8 21v-3.5L12 12l4 5.5V21"/>
            @break
    @endswitch
</svg>
