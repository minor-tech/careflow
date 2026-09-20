<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('layouts.head', ['title' => $title])
    </head>
    <body>
        @php
            $user = auth()->user();
        @endphp

        {{-- Below 1024px the sidebar is a drawer that the Menu button opens; above it the sidebar is always there. --}}
        <div class="cf-shell" x-data="{ open: false }" x-on:keydown.escape.window="open = false">
            <div class="cf-backdrop" x-show="open" x-cloak x-transition.opacity x-on:click="open = false" aria-hidden="true"></div>

            <aside id="sidebar" class="cf-sidebar" x-bind:class="{ 'is-open': open }">
                <a href="{{ route('dashboard') }}" class="cf-sidebar__brand">
                    <div class="cf-sidebar__logo gloss-fill" aria-hidden="true"><x-icon name="plus" /></div>
                    <div>
                        <div class="cf-sidebar__brand-name">CareFlow</div>
                        @if ($user->facility)
                            <div class="cf-sidebar__facility-name">{{ $user->facility->name }}</div>
                        @endif
                    </div>
                </a>

                <nav aria-label="Main">
                    @foreach ($navGroups as $group)
                        <div class="cf-nav-group">
                            <div class="cf-nav-group__label">{{ $group['label'] }}</div>
                            @foreach ($group['items'] as $item)
                                <a href="{{ route($item['route']) }}"
                                   @if ($item['active']) aria-current="page" @endif
                                   class="cf-nav-link {{ $item['active'] ? 'is-active' : '' }}">
                                    <x-icon :name="$item['icon']" />
                                    {{ $item['label'] }}
                                </a>
                            @endforeach
                        </div>
                    @endforeach
                </nav>

                <div class="cf-sidebar__spacer"></div>

                <a href="{{ route('contact') }}" class="cf-help-card">
                    <x-icon name="help" />
                    <div>
                        <div class="cf-help-card__title">Need help?</div>
                        <div class="cf-help-card__subtitle">Contact CareFlow support</div>
                    </div>
                </a>

                <div class="cf-user-card">
                    <div class="cf-avatar gloss-fill" aria-hidden="true">{{ $user->initials() }}</div>
                    <a href="{{ route('profile.edit') }}" class="cf-user-card__who" title="Your profile">
                        <div class="cf-user-card__name">{{ $user->name }}</div>
                        <div class="cf-user-card__role">{{ $user->role->label() }}</div>
                    </a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="cf-icon-btn" aria-label="Log out"><x-icon name="logout" /></button>
                    </form>
                </div>
            </aside>

            <div class="cf-main">
                <header class="cf-topbar">
                    <div class="cf-topbar__lead">
                        <button type="button" class="cf-icon-btn cf-menu-btn"
                                aria-label="Menu"
                                aria-controls="sidebar"
                                x-on:click="open = ! open"
                                x-bind:aria-expanded="open.toString()">
                            <x-icon name="menu" />
                        </button>
                        <div class="min-w-0">
                            <div class="cf-topbar__crumb">{{ $crumbLabel }}</div>
                            <div class="cf-topbar__title">{{ $title }}</div>
                        </div>
                    </div>

                    <div class="cf-topbar__actions">
                        @if ($user->isAdmin())
                            <a href="{{ route('notifications.index') }}" class="cf-icon-btn" aria-label="Notifications"><x-icon name="bell" /></a>
                            <div class="cf-topbar__divider"></div>
                        @endif
                        <div class="cf-avatar gloss-fill" aria-hidden="true">{{ $user->initials() }}</div>
                        <div class="cf-topbar__user">
                            <div class="cf-topbar__user-name">{{ $user->name }}</div>
                            <div class="cf-topbar__user-role">{{ $user->role->label() }}</div>
                        </div>
                    </div>
                </header>

                <main class="cf-content">
                    @isset($header)
                        <div class="mb-6">
                            {{ $header }}
                        </div>
                    @endisset

                    @if (session('success'))
                        <div role="status" class="mb-6 rounded-2xl border-l-4 border-ok bg-white px-4 py-3 text-sm shadow-card">
                            {{ session('success') }}
                        </div>
                    @endif

                    {{ $slot }}
                </main>
            </div>
        </div>
    </body>
</html>
