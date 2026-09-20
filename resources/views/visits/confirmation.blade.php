<x-app-layout :title="'Queue code '.$visit->queueCode()">
    {{-- On screen: the code and PIN are the whole point, nothing else competes with them. --}}
    <div class="print:hidden mx-auto flex min-h-[70vh] max-w-md flex-col items-center justify-center text-center" x-data="{ showQr: false }">
        <p class="cf-status-pill cf-status-pill--ok">Patient added</p>
        <p class="mt-4 text-sm text-ink/70">{{ $visit->facility->name }}</p>
        <p class="mt-1 text-base text-ink/70">{{ $visit->patient->name }}</p>
        @if ($visit->isInDoctorQueue())
            <p class="mt-1 text-base font-medium">Assigned to {{ $visit->assignedDoctor->doctorName() }} &middot; their line {{ $visit->queueLabel() }}</p>
        @endif

        <p class="mt-8 text-sm font-bold uppercase tracking-widest text-ink/70">Queue code</p>
        <p class="mt-1 text-[5rem] font-bold leading-none text-primary tabular-nums sm:text-[7rem]" aria-label="Queue code {{ $visit->queueCode() }}">{{ $visit->queueCode() }}</p>
        <p class="mt-2 text-sm text-ink/70">Not a secret: fine to say out loud.</p>

        @if ($accessPin)
            <div class="cf-card mt-8 w-full">
                <p class="text-sm font-bold uppercase tracking-widest text-ink/70">Access PIN</p>
                <p class="mt-1 text-5xl font-bold tracking-[0.3em] tabular-nums" aria-label="Access PIN {{ implode(' ', str_split($accessPin)) }}">{{ $accessPin }}</p>
                <p class="mt-2 text-sm text-ink/70">Shown once. Give it to the patient with the ticket, and don't read it out.</p>
            </div>
        @else
            <p class="cf-card mt-8 w-full text-sm text-ink/70">The access PIN is shown only once, straight after registering, and isn't stored anywhere it can be read back. The patient can still follow their visit with the QR code or link.</p>
        @endif

        <div class="mt-8 flex flex-wrap items-center justify-center gap-3">
            @if ($trackingUrl)
                <button type="button" class="btn-outline" x-on:click="showQr = ! showQr" x-bind:aria-expanded="showQr.toString()" aria-controls="qr-panel">
                    <span x-show="! showQr">Show QR</span>
                    <span x-show="showQr" x-cloak>Hide QR</span>
                </button>
            @endif
            @if ($accessPin)
                <button type="button" class="btn-outline" x-on:click="window.print()">Print ticket</button>
            @endif
        </div>

        @if ($trackingUrl)
            {{-- For the patient's own phone: a scan is quicker and safer than reading out a web address. --}}
            <section id="qr-panel" class="cf-card mt-6 w-full" aria-label="Follow this visit on a phone" x-show="showQr" x-cloak x-data="copyLink">
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

    {{-- On paper: only this. Hidden on screen, and only there while the PIN exists to print. --}}
    @if ($accessPin)
        <section id="ticket" class="hidden text-center print:block" aria-label="Patient ticket">
            <p class="text-xl font-bold">{{ $visit->facility->name }}</p>
            @if ($visit->isInDoctorQueue())
                <p class="mt-2 text-base">Your doctor: {{ $visit->assignedDoctor->doctorName() }}</p>
            @endif
            <p class="mt-6 text-sm font-bold uppercase tracking-widest">Queue code</p>
            <p class="text-7xl font-bold leading-none tabular-nums">{{ $visit->queueCode() }}</p>
            <p class="mt-6 text-sm font-bold uppercase tracking-widest">Access PIN</p>
            <p class="text-6xl font-bold leading-none tracking-[0.25em] tabular-nums">{{ $accessPin }}</p>

            @if ($qrCode)
                <img src="{{ $qrCode }}" alt="QR code for following this visit" width="200" height="200" class="mx-auto mt-6 h-48 w-48">
                <p class="text-sm">Scan to follow your visit</p>
            @endif

            <p class="mt-6 text-sm">No smartphone? Follow your visit at</p>
            <p class="text-base font-bold break-all">{{ preg_replace('#^https?://#', '', $entryUrl) }}</p>
            <p class="mt-1 text-sm">and type your queue code and PIN.</p>
            <p class="mt-6 text-xs">Keep this ticket private: the PIN opens your visit.</p>
        </section>
    @endif
</x-app-layout>
