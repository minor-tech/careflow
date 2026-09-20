<x-app-layout :title="'Queue number '.$visit->queue_number">
    {{-- The number is the whole point of this screen: nothing else competes with it. --}}
    <div class="mx-auto flex min-h-[70vh] max-w-md flex-col items-center justify-center text-center">
        <p class="text-sm text-ink/60">{{ $visit->facility->name }}</p>
        <p class="mt-1 text-base text-ink/70">{{ $visit->patient->name }}</p>

        <p class="mt-8 text-[7rem] font-bold leading-none text-primary tabular-nums sm:text-[10rem]" aria-label="Queue number {{ $visit->queue_number }}">{{ $visit->queue_number }}</p>
        <p class="mt-3 text-lg text-ink/70">Queue number</p>

        @if ($trackingUrl)
            {{-- For the patient's own phone: a scan is quicker and safer than reading out a web address. --}}
            <section class="cf-card mt-10 w-full" aria-label="Follow this visit on a phone" x-data="copyLink">
                <img src="{{ $qrCode }}" alt="QR code linking to this patient's tracking page" width="176" height="176" class="mx-auto h-44 w-44">
                <p class="mt-2 text-sm text-ink/70">Scan with a phone camera to follow this visit</p>

                <div class="mt-4 flex gap-2">
                    <input type="text" readonly value="{{ $trackingUrl }}" x-ref="link" aria-label="Tracking link" class="field-input min-w-0 flex-1 text-sm" @focus="$el.select()">
                    <x-outline-button @click="copy()">
                        <span x-show="!copied">Copy link</span>
                        <span x-show="copied" x-cloak role="status">Copied</span>
                    </x-outline-button>
                </div>
            </section>
        @endif

        <x-primary-button :href="route('patients.register')" class="mt-10">Register another patient</x-primary-button>
    </div>
</x-app-layout>
