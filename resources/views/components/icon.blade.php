@props(['name'])

{{-- The dashboard's line icons: simple shapes on a 24px grid, drawn in the current text colour. (The public site has its own set in site/icon.) --}}
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" {{ $attributes->class('cf-icon') }}>
    @switch($name)
        @case('facility')
            <path d="M4 21V6l8-3 8 3v15"/>
            <path d="M2.5 21h19"/>
            <path d="M12 9v6M9 12h6"/>
            @break

        @case('analytics')
            <path d="M4 20V10M10 20V4M16 20v-7M21 20H3"/>
            @break

        @case('queue')
            <path d="M9 6h11M9 12h11M9 18h11"/>
            <circle cx="4.5" cy="6" r="1"/>
            <circle cx="4.5" cy="12" r="1"/>
            <circle cx="4.5" cy="18" r="1"/>
            @break

        @case('register')
            <circle cx="10" cy="8" r="4"/>
            <path d="M3 20c0-3.6 3-6 7-6"/>
            <path d="M18 13v6M15 16h6"/>
            @break

        @case('bell')
            <path d="M6 16V11a6 6 0 0 1 12 0v5l1.5 2h-15z"/>
            <path d="M10 21h4"/>
            @break

        @case('megaphone')
            <path d="M4 10v4h3l5 4V6L7 10z"/>
            <path d="M16 9a4 4 0 0 1 0 6"/>
            @break

        @case('staff')
            <circle cx="9" cy="8" r="3.5"/>
            <path d="M2.5 20c0-3.5 2.8-6 6.5-6s6.5 2.5 6.5 6"/>
            <path d="M16 4.6a3.5 3.5 0 0 1 0 6.8M18 14.5c2 .7 3.5 2.6 3.5 5.5"/>
            @break

        @case('grid')
            <rect x="4" y="4" width="7" height="7" rx="1.5"/>
            <rect x="13" y="4" width="7" height="7" rx="1.5"/>
            <rect x="4" y="13" width="7" height="7" rx="1.5"/>
            <rect x="13" y="13" width="7" height="7" rx="1.5"/>
            @break

        @case('settings')
            <path d="M4 7h9M17 7h3M4 17h3M11 17h9"/>
            <circle cx="15" cy="7" r="2"/>
            <circle cx="9" cy="17" r="2"/>
            @break

        @case('logout')
            <path d="M9 4H5v16h4"/>
            <path d="M16 8l4 4-4 4M20 12H9"/>
            @break

        @case('person')
            <circle cx="12" cy="8" r="4"/>
            <path d="M4 21c0-4 3.6-6 8-6s8 2 8 6"/>
            @break

        @case('plus')
            <path d="M12 5v14M5 12h14"/>
            @break

        @case('help')
            <circle cx="12" cy="12" r="9"/>
            <path d="M9.6 9.5a2.5 2.5 0 1 1 3.6 2.2c-.8.4-1.2.9-1.2 1.8"/>
            <circle cx="12" cy="16.8" r=".6" fill="currentColor"/>
            @break

        @case('menu')
            <path d="M4 7h16M4 12h16M4 17h16"/>
            @break

        @case('clock')
            <circle cx="12" cy="12" r="9"/>
            <path d="M12 7v5l3 2"/>
            @break

        @case('hourglass')
            <path d="M7 3h10M7 21h10"/>
            <path d="M8 3c0 5 4 5.5 4 9s-4 4-4 9M16 3c0 5-4 5.5-4 9s4 4 4 9"/>
            @break

        @case('check')
            <circle cx="12" cy="12" r="9"/>
            <path d="M8 12.5l2.8 2.8L16 9.5"/>
            @break

        @case('x')
            <circle cx="12" cy="12" r="9"/>
            <path d="M9 9l6 6M15 9l-6 6"/>
            @break

        @case('activity')
            <path d="M3 12h4l3-8 4 16 3-8h4"/>
            @break

        @case('star')
            <path d="M12 3.5l2.6 5.3 5.9.9-4.3 4.1 1 5.8-5.2-2.8-5.2 2.8 1-5.8-4.3-4.1 5.9-.9z"/>
            @break
    @endswitch
</svg>
