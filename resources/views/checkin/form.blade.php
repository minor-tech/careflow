<x-guest-layout :title="'Check in · '.$facility->name">
    {{-- The clinic's own page: its name first, so it doesn't read like a third-party site. --}}
    <p class="text-sm text-ink/70">{{ $facility->name }}</p>
    <h1 class="mt-1 text-2xl font-semibold">Check yourself in</h1>
    <p class="mt-2 text-sm text-ink/70">Already here? Tell us who you are and the front desk will confirm you.</p>

    @if ($errors->has('request'))
        <div role="alert" class="mt-4 rounded-2xl border-l-4 border-danger bg-white px-4 py-3 text-sm shadow-card">{{ $errors->first('request') }}</div>
    @endif

    <form method="POST" action="{{ route('checkin.store', $facility->slug) }}" class="mt-6 space-y-5"
          x-data="{ submitting: false }" x-on:submit="submitting = true" x-on:pageshow.window="submitting = false">
        @csrf

        {{-- Real people never see this. If it comes back filled in, it was a bot. --}}
        <div class="absolute -left-[9999px]" aria-hidden="true">
            <label for="website">Leave this empty</label>
            <input type="text" id="website" name="website" tabindex="-1" autocomplete="off" value="">
        </div>

        @if ($services !== [])
            <fieldset>
                <legend class="text-sm font-medium text-ink">Service</legend>
                <div class="mt-2 space-y-2">
                    <x-check type="radio" name="service_id" value="" :checked="old('service_id') === null || old('service_id') === ''">Any service</x-check>
                    @foreach ($services as $id => $name)
                        <x-check type="radio" name="service_id" :value="$id" :checked="(string) old('service_id') === (string) $id">{{ $name }}</x-check>
                    @endforeach
                </div>
                <x-input-error :messages="$errors->get('service_id')" />
            </fieldset>
        @endif

        <x-field name="name" label="Name">
            <x-text-input id="name" name="name" :value="old('name')" required autofocus autocomplete="name" maxlength="120" />
        </x-field>

        <x-field name="phone" label="Phone" hint="We text you a link to follow your place in the queue.">
            <x-text-input id="phone" name="phone" type="tel" inputmode="tel" :value="old('phone')" required autocomplete="tel" />
        </x-field>

        <x-primary-button class="w-full" x-bind:disabled="submitting">Check In</x-primary-button>
    </form>
</x-guest-layout>
