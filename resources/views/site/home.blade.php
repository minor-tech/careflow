@php
    $problems = [
        ['icon' => 'hourglass', 'color' => 'bg-accent-gold', 'text' => 'Not knowing how long they\'ll wait'],
        ['icon' => 'question', 'color' => 'bg-accent-clay', 'text' => 'Not knowing what\'s happening with their visit'],
        ['icon' => 'phone-off', 'color' => 'bg-accent-slate', 'text' => 'Having to keep asking staff for updates'],
    ];

    $steps = [
        ['number' => '01', 'text' => 'Patient registers at reception, gets a queue number'],
        ['number' => '02', 'text' => 'They get a private link — no app, no login'],
        ['number' => '03', 'text' => 'Staff move them through departments; their link updates live'],
        ['number' => '04', 'text' => 'They get texted at the moments that matter'],
    ];

    $staffGets = [
        ['icon' => 'queue', 'color' => 'gloss-fill', 'accent' => 'teal', 'title' => 'Live queue dashboard', 'text' => 'Call, start and complete in one tap.'],
        ['icon' => 'pin', 'color' => 'bg-accent-sage', 'accent' => 'sage', 'title' => 'Every patient, located', 'text' => 'See exactly where every patient is, facility-wide.'],
        ['icon' => 'message', 'color' => 'bg-accent-clay', 'accent' => 'clay', 'title' => 'Automatic SMS', 'text' => 'A text at each step, without staff typing a thing.'],
        ['icon' => 'chart', 'color' => 'bg-accent-slate', 'accent' => 'slate', 'title' => 'Real bottleneck data', 'text' => 'Know which department is slow, and when.'],
    ];

    // Plain facts about how the product works: not statistics, and nothing that needs customers to be true.
    $facts = [
        ['number' => '0', 'text' => 'apps for patients to install', 'underline' => 'gloss-fill'],
        ['number' => '1', 'text' => 'private link for every visit', 'underline' => 'bg-accent-gold'],
        ['number' => '5', 'text' => 'moments a visit can text the patient', 'underline' => 'bg-accent-clay'],
    ];

    $trust = [
        ['title' => 'Only what a visit needs', 'text' => 'A patient\'s name and phone number are all it takes to register them.'],
        ['title' => 'Private patient links', 'text' => 'Every visit has its own unguessable link. It stops working at the end of the day and is kept out of search engines.'],
        ['title' => 'Access by role', 'text' => 'Receptionists, doctors, nurses and admins each see what their job needs.'],
        ['title' => 'A record of every step', 'text' => 'Every call, start, transfer and completion is logged with who did it and when.'],
    ];

    $faqs = [
        ['q' => 'Does this replace our existing hospital system?', 'a' => 'No — CareFlow sits alongside your existing workflow to manage patient flow and communication; it isn\'t a full medical records system.'],
        ['q' => 'Do patients need to install an app?', 'a' => 'No. They open a link in their phone\'s browser — no download, no account.'],
        ['q' => 'Is our patients\' data secure?', 'a' => 'We collect only what a visit needs, keep each visit\'s link private, and show staff only what their role needs. Connections are encrypted, and we design with Kenya\'s Data Protection Act and the ODPC\'s guidance on health data in mind. Ask us how it applies to your facility.'],
        ['q' => 'How do we get started?', 'a' => 'Register your facility, and our team reviews and activates your account, usually within 24 hours.'],
    ];
@endphp

<x-site-layout title="Know where every patient is" description="CareFlow gives Kenyan clinics and hospitals a live view of every patient's visit, and gives patients their own link to see exactly where they stand.">

    {{-- 1. Hero --}}
    <section class="bg-gradient-to-b from-tint-mid to-canvas px-4 pb-16 pt-6 lg:pb-28 lg:pt-10">
        <div class="mx-auto grid max-w-6xl items-center gap-12 lg:grid-cols-[1.1fr_0.9fr] lg:gap-16">
            <div>
                <h1 class="display-hero text-ink">Know where every patient is.</h1>

                <p class="mt-6 max-w-xl text-lg text-ink/80">
                    CareFlow gives Kenyan clinics and hospitals a live view of every patient's visit, and gives patients their own link to see exactly where they stand &mdash; no more asking &ldquo;niko number gani?&rdquo;
                </p>

                <div class="mt-9 flex flex-col gap-4 sm:flex-row">
                    <a href="{{ route('facility.register') }}" class="btn-primary">Register Your Facility</a>
                    <a href="#how-it-works" class="btn-outline">See how it works <span aria-hidden="true">&darr;</span></a>
                </div>
            </div>

            @include('site._phone')
        </div>
    </section>

    {{-- 2. The problem, stated plainly --}}
    <section class="site-section">
        <div class="mx-auto max-w-6xl text-center">
            <span class="badge-section">The problem</span>
            <h2 class="display-section mx-auto mt-5 max-w-2xl">Three things patients complain about most</h2>

            <ul class="mt-12 grid gap-6 text-left md:grid-cols-3">
                @foreach ($problems as $problem)
                    <li class="card-soft flex items-center gap-5 p-6">
                        <span class="icon-badge {{ $problem['color'] }}"><x-site.icon :name="$problem['icon']" /></span>
                        <p class="text-lg font-medium">{{ $problem['text'] }}</p>
                    </li>
                @endforeach
            </ul>

            <p class="mt-10 text-xl font-semibold text-primary">CareFlow fixes all three with one link.</p>
        </div>
    </section>

    {{-- 3. How it works --}}
    <section id="how-it-works" class="site-section scroll-mt-4 bg-tint-light">
        <div class="mx-auto max-w-6xl text-center">
            <span class="badge-section bg-white">How it works</span>
            <h2 class="display-section mx-auto mt-5 max-w-2xl">From the front desk to the patient's phone</h2>

            <ol class="mt-12 grid gap-6 text-left sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($steps as $step)
                    <li class="card-soft p-6">
                        <p class="text-4xl font-bold text-primary tabular-nums">{{ $step['number'] }}</p>
                        <p class="mt-4 text-lg">{{ $step['text'] }}</p>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    {{-- 4. What staff get --}}
    <section class="site-section">
        <div class="mx-auto max-w-6xl text-center">
            <span class="badge-section">For your team</span>
            <h2 class="display-section mx-auto mt-5 max-w-2xl">What your staff get</h2>

            <ul class="mt-12 grid gap-6 text-left sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($staffGets as $item)
                    <li class="card-accent card-accent--{{ $item['accent'] }} card-lift">
                        <span class="icon-badge {{ $item['color'] }}"><x-site.icon :name="$item['icon']" /></span>
                        <h3 class="title-card mt-5">{{ $item['title'] }}</h3>
                        <p class="mt-2 text-ink/70">{{ $item['text'] }}</p>
                    </li>
                @endforeach
            </ul>

            {{-- Facts, not statistics: each is true of the product itself, so none of it waits on customers. --}}
            <dl class="mx-auto mt-16 grid max-w-3xl gap-10 sm:grid-cols-3">
                @foreach ($facts as $fact)
                    <div>
                        <dd class="text-5xl font-bold text-ink tabular-nums">{{ $fact['number'] }}</dd>
                        <span class="mx-auto mt-2 block h-1 w-12 rounded-full {{ $fact['underline'] }}" aria-hidden="true"></span>
                        <dt class="mt-3 text-ink/70">{{ $fact['text'] }}</dt>
                    </div>
                @endforeach
            </dl>
        </div>
    </section>

    {{-- 5. Honest trust: what the software actually does, no invented customers or numbers --}}
    <section class="site-section bg-tint-light">
        <div class="mx-auto max-w-6xl">
            <div class="mx-auto max-w-2xl text-center">
                <span class="badge-section bg-white">Built for Kenya</span>
                <h2 class="display-section mt-5">Built for Kenyan healthcare facilities</h2>
                <p class="mt-4 text-lg text-ink/80">Health data deserves care. CareFlow is designed around Kenya's Data Protection Act and the ODPC's guidance on health data: collect little, keep it private, and be able to show what happened.</p>
            </div>

            <ul class="mt-12 grid gap-6 sm:grid-cols-2">
                @foreach ($trust as $point)
                    <li class="card-soft flex gap-4 p-6">
                        <span class="icon-badge gloss-fill"><x-site.icon name="check" /></span>
                        <div>
                            <h3 class="text-lg font-semibold">{{ $point['title'] }}</h3>
                            <p class="mt-1 text-ink/70">{{ $point['text'] }}</p>
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>

    {{-- 6. FAQ --}}
    <section class="site-section" x-data="{ open: null }">
        <div class="mx-auto max-w-3xl">
            <div class="text-center">
                <span class="badge-section">Questions</span>
                <h2 class="display-section mt-5">Common questions</h2>
            </div>

            <div class="mt-10 space-y-3">
                @foreach ($faqs as $index => $faq)
                    <div class="rounded-2xl transition-colors duration-200" x-bind:class="open === {{ $index }} ? 'bg-tint-light' : ''">
                        <h3>
                            <button type="button"
                                    id="faq-button-{{ $index }}"
                                    class="flex min-h-14 w-full items-center justify-between gap-4 rounded-2xl px-5 py-4 text-left text-lg font-semibold"
                                    x-on:click="open = open === {{ $index }} ? null : {{ $index }}"
                                    x-bind:aria-expanded="(open === {{ $index }}).toString()"
                                    aria-controls="faq-panel-{{ $index }}">
                                <span>{{ $faq['q'] }}</span>
                                <span class="relative h-5 w-5 shrink-0 text-primary" aria-hidden="true">
                                    <span class="absolute left-0 top-1/2 h-0.5 w-5 -translate-y-1/2 bg-current transition-transform duration-200" x-bind:class="open === {{ $index }} ? 'rotate-45' : ''"></span>
                                    <span class="absolute left-1/2 top-0 h-5 w-0.5 -translate-x-1/2 bg-current transition-transform duration-200" x-bind:class="open === {{ $index }} ? 'rotate-45' : ''"></span>
                                </span>
                            </button>
                        </h3>

                        {{-- The panel grows from a zero-height row to its natural height: a smooth 200ms open with no fixed heights. --}}
                        <div id="faq-panel-{{ $index }}"
                             role="region"
                             aria-labelledby="faq-button-{{ $index }}"
                             class="grid transition-[grid-template-rows] duration-200 ease-in-out motion-reduce:transition-none"
                             x-bind:class="open === {{ $index }} ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]'"
                             x-bind:inert="open !== {{ $index }}">
                            <div class="overflow-hidden">
                                <p class="px-5 pb-5 text-ink/80">{{ $faq['a'] }}</p>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- 7. Final call to action, on the brand colour rather than a black band --}}
    <section class="px-4 pb-14 lg:pb-28">
        <div class="mx-auto max-w-6xl gloss-fill rounded-3xl px-6 py-14 text-center text-white sm:px-12 lg:py-20">
            <h2 class="display-section mx-auto max-w-2xl">Ready to stop the guessing game in your waiting room?</h2>
            <a href="{{ route('facility.register') }}" class="btn-primary mt-9">Register Your Facility</a>
        </div>
    </section>
</x-site-layout>
