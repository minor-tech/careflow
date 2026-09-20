@props(['steps', 'step', 'completed' => []])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('layouts.head', ['title' => 'Register your facility'])
    </head>
    <body>
        <div class="mx-auto flex min-h-screen w-full max-w-xl flex-col px-4 py-6 sm:py-10">
            <header class="flex items-center justify-between gap-4">
                <a href="/" class="flex items-center gap-2 text-primary">
                    <x-application-logo class="h-8 w-8" />
                    <span class="text-lg font-semibold">CareFlow</span>
                </a>
                <a href="{{ route('login') }}" class="link text-sm">Already registered? Log in</a>
            </header>

            {{-- Numbered progress rail: a literal sequence, so numbers earn their place. --}}
            <nav aria-label="Registration progress" class="mt-8">
                <ol class="flex items-center justify-between gap-1">
                    @foreach ($steps as $number => $definition)
                        @php
                            $isCurrent = $number === $step;
                            $isDone = in_array($number, $completed, true);
                            $circle = 'flex h-8 w-8 items-center justify-center rounded-full border text-sm font-semibold tabular-nums';
                        @endphp
                        <li class="flex-1 last:flex-none">
                            <div class="flex items-center gap-1">
                                @if ($isDone && ! $isCurrent)
                                    <a href="{{ route('facility.register.step', $number) }}"
                                       class="{{ $circle }} border-primary bg-tint-mint text-primary"
                                       aria-label="Step {{ $number }}: {{ $definition['title'] }}, completed">{{ $number }}</a>
                                @else
                                    <span class="{{ $circle }} {{ $isCurrent ? 'gloss-fill border-transparent text-white' : 'border-line bg-white text-ink/50' }}"
                                          @if ($isCurrent) aria-current="step" @endif
                                          aria-label="Step {{ $number }}: {{ $definition['title'] }}">{{ $number }}</span>
                                @endif
                                @unless ($loop->last)
                                    <span class="h-px flex-1 {{ $isDone ? 'gloss-fill opacity-50' : 'bg-line' }}" aria-hidden="true"></span>
                                @endunless
                            </div>
                        </li>
                    @endforeach
                </ol>
            </nav>

            <main class="step-enter mt-8 flex-1">
                <p class="text-sm text-ink/70">
                    Step <span class="numeral text-base">{{ $step }}</span> of <span class="numeral text-base">{{ count($steps) }}</span>
                    @if ($steps[$step]['optional'])
                        &middot; optional
                    @endif
                </p>
                <h1 class="mt-1 text-2xl font-semibold">{{ $steps[$step]['title'] }}</h1>

                @if ($errors->any())
                    <div role="alert" class="mt-4 rounded-md border border-danger/30 bg-white px-4 py-3 text-sm text-danger">
                        Please fix the highlighted {{ Str::plural('field', $errors->count()) }} below.
                    </div>
                @endif

                {{ $slot }}
            </main>
        </div>
    </body>
</html>
