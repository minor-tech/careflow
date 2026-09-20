<x-guest-layout title="Follow your visit">
    {{-- The clinic's own page: its name first, so it doesn't read like a third-party site. --}}
    <p class="text-sm text-ink/70">{{ $facility->name }}</p>
    <h1 class="mt-1 text-2xl font-semibold">Follow your visit</h1>
    <p class="mt-2 text-sm text-ink/70">Type the queue code and PIN printed on your ticket.</p>

    <form method="POST" action="{{ route('tracking.entry.attempt', $facility->slug) }}" class="mt-6 space-y-5">
        @csrf

        <x-field name="queue_code" label="Queue code" hint="For example V027">
            <x-text-input id="queue_code" name="queue_code" :value="old('queue_code')" required autofocus autocomplete="off" autocapitalize="characters" spellcheck="false" maxlength="12" />
        </x-field>

        {{-- Never pre-filled: the PIN is not sent back after a miss. --}}
        <x-field name="pin" label="Access PIN" hint="The 4 digits on your ticket">
            <x-text-input id="pin" name="pin" type="text" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" required autocomplete="off" class="tabular-nums tracking-widest" />
        </x-field>

        <x-primary-button class="w-full">Follow my visit</x-primary-button>
    </form>

    <p class="mt-6 text-sm text-ink/70">Have a QR code or a link from a text message? Open that instead: it goes straight to your visit.</p>
</x-guest-layout>
