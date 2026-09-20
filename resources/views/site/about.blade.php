@php
    $aims = [
        ['icon' => 'pin', 'color' => 'gloss-fill', 'title' => 'Know where every patient is', 'text' => 'One live view of the whole facility, so nobody is lost between reception, the doctor, the lab and the pharmacy.'],
        ['icon' => 'hourglass', 'color' => 'bg-accent-gold', 'title' => 'Reduce unnecessary waiting', 'text' => 'When staff can see the queue and where it is slow, they can do something about it.'],
        ['icon' => 'message', 'color' => 'bg-accent-clay', 'title' => 'Keep patients informed', 'text' => 'Each patient gets their own link and, where the facility turns it on, a text at the moments that matter.'],
    ];
@endphp

<x-site-layout title="About" description="Why CareFlow exists, who it is for, and what it does and does not do.">

    <section class="bg-gradient-to-b from-tint-mid to-canvas px-4 pb-14 pt-8 text-center lg:pb-24 lg:pt-14">
        <div class="mx-auto max-w-3xl">
            <span class="badge-section bg-white">About CareFlow</span>
            <h1 class="display-section mt-5">Helping clinics know where every patient is</h1>
            <p class="mt-6 text-lg text-ink/80">CareFlow is queue and patient-flow software for clinics and hospitals in Kenya.</p>
        </div>
    </section>

    <section class="site-section">
        <div class="mx-auto max-w-3xl">
            <h2 class="text-2xl font-bold tracking-tight sm:text-3xl">Why it exists</h2>
            <div class="mt-5 space-y-4 text-lg text-ink/80">
                <p>In a busy waiting room, patients don't know how long they'll wait or what is happening with their visit, so they keep asking. Staff spend part of every day answering the same question: &ldquo;niko number gani?&rdquo;</p>
                <p>CareFlow gives patients their own page to see where they stand, and gives staff one place to move people through the facility. Less asking, less guessing, less standing around.</p>
            </div>
        </div>
    </section>

    <section class="site-section bg-tint-light">
        <div class="mx-auto max-w-6xl text-center">
            <span class="badge-section bg-white">What it is for</span>
            <h2 class="display-section mx-auto mt-5 max-w-2xl">Three aims</h2>

            <ul class="mt-12 grid gap-6 text-left md:grid-cols-3">
                @foreach ($aims as $aim)
                    <li class="card-soft p-6">
                        <span class="icon-badge {{ $aim['color'] }}"><x-site.icon :name="$aim['icon']" /></span>
                        <h3 class="mt-5 text-lg font-semibold">{{ $aim['title'] }}</h3>
                        <p class="mt-2 text-ink/70">{{ $aim['text'] }}</p>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>

    <section class="site-section">
        <div class="mx-auto max-w-3xl">
            <h2 class="text-2xl font-bold tracking-tight sm:text-3xl">What it is not</h2>
            <p class="mt-5 text-lg text-ink/80">CareFlow is not a medical records system and doesn't replace one. It sits alongside how your facility already works, and only handles the flow of patients and what they are told. It keeps to what a visit needs: a patient's name and phone number are all it requires.</p>

            <h2 class="mt-12 text-2xl font-bold tracking-tight sm:text-3xl">Where we are</h2>
            <p class="mt-5 text-lg text-ink/80">CareFlow is new. That means we don't have customer stories or usage figures to show you yet, and we won't invent them. If you'd like to try it at your facility, register it and our team will review and activate your account. If you'd rather talk first, write to us.</p>

            <div class="mt-10 flex flex-col gap-4 sm:flex-row">
                <a href="{{ route('facility.register') }}" class="btn-primary">Register Your Facility</a>
                <a href="{{ route('contact') }}" class="btn-outline">Contact us</a>
            </div>
        </div>
    </section>
</x-site-layout>
