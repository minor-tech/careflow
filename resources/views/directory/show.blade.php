<x-site-layout :title="$facility->name" :description="'Ask for a place in the queue at '.$facility->name.' and follow it from your phone.'">

    <section class="bg-gradient-to-b from-tint-mid to-canvas px-4 pb-10 pt-8 lg:pb-14 lg:pt-14">
        <div class="mx-auto max-w-3xl text-center">
            <span class="badge-section bg-white">{{ $card->area() !== '' ? $card->area() : 'Facility' }}</span>
            <h1 class="display-section mt-5">{{ $facility->name }}</h1>

            @if ($card->services !== [])
                <p class="mt-4 text-lg font-medium text-ink/80">{{ implode(' · ', $card->services) }}</p>
            @endif

            <p class="mt-4">
                @if ($availability->isOpen())
                    <span class="cf-status-pill cf-status-pill--ok">Remote queue available</span>
                @endif
            </p>

            <dl class="mx-auto mt-6 grid max-w-md grid-cols-2 gap-4 text-center">
                <div class="card-soft p-4">
                    <dt class="text-sm text-ink/70">Current estimated wait</dt>
                    <dd class="mt-1 text-lg font-semibold tabular-nums">{{ $card->waitLabel ?? 'Not available' }}</dd>
                </div>
                <div class="card-soft p-4">
                    <dt class="text-sm text-ink/70">Doctors on duty</dt>
                    <dd class="mt-1 text-lg font-semibold tabular-nums">{{ $card->doctorsOnDuty }}</dd>
                </div>
            </dl>
        </div>
    </section>

    <section class="px-4 pb-14 pt-6 lg:pb-24">
        <div class="mx-auto grid max-w-5xl gap-10 lg:grid-cols-[1.4fr_1fr]">
            <div class="card-soft p-6 sm:p-8">
                <h2 class="text-xl font-semibold">Request to join today's queue</h2>

                @if ($errors->has('request'))
                    <div role="alert" class="mt-4 rounded-xl border-l-4 border-l-danger bg-white px-4 py-3 text-sm shadow-card">{{ $errors->first('request') }}</div>
                @endif

                @if ($availability->isOpen())
                    <form method="POST" action="{{ route('remote.request.store', $facility->slug) }}" class="mt-6 space-y-5" novalidate
                          x-data="{ submitting: false }" x-on:submit="submitting = true" x-on:pageshow.window="submitting = false">
                        @csrf

                        {{-- Real people never see this. If it comes back filled in, it was a bot. --}}
                        <div class="absolute -left-[9999px]" aria-hidden="true">
                            <label for="website">Leave this empty</label>
                            <input type="text" id="website" name="website" tabindex="-1" autocomplete="off" value="">
                        </div>

                        @if ($services !== [])
                            <fieldset>
                                <legend class="font-medium">Service</legend>
                                <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                    <x-check type="radio" name="service_id" value="" :checked="old('service_id') === null || old('service_id') === ''">Any service</x-check>
                                    @foreach ($services as $id => $name)
                                        <x-check type="radio" name="service_id" :value="$id" :checked="(string) old('service_id') === (string) $id">{{ $name }}</x-check>
                                    @endforeach
                                </div>
                                @error('service_id')<p class="mt-1 text-sm text-danger" role="alert">{{ $message }}</p>@enderror
                            </fieldset>
                        @endif

                        @if ($doctors !== [])
                            <div>
                                <label for="preferred_doctor_id" class="font-medium">Doctor <span class="font-normal text-ink/60">(optional)</span></label>
                                <select id="preferred_doctor_id" name="preferred_doctor_id" class="field-input mt-2 !rounded-xl">
                                    <option value="">Whoever is free first</option>
                                    @foreach ($doctors as $id => $name)
                                        <option value="{{ $id }}" @selected((string) old('preferred_doctor_id') === (string) $id)>{{ $name }}</option>
                                    @endforeach
                                </select>
                                <p class="mt-1 text-sm text-ink/60">The facility decides in the end, but will try to give you the doctor you ask for.</p>
                                @error('preferred_doctor_id')<p class="mt-1 text-sm text-danger" role="alert">{{ $message }}</p>@enderror
                            </div>
                        @endif

                        <div>
                            <label for="requested_arrival" class="font-medium">When can you arrive?</label>
                            <input type="time" id="requested_arrival" name="requested_arrival" value="{{ old('requested_arrival') }}" required class="field-input mt-2 !rounded-xl sm:max-w-[12rem]" @error('requested_arrival') aria-invalid="true" @enderror>
                            @error('requested_arrival')<p class="mt-1 text-sm text-danger" role="alert">{{ $message }}</p>@enderror
                        </div>

                        <div>
                            <label for="name" class="font-medium">Name</label>
                            <input type="text" id="name" name="name" value="{{ old('name') }}" required maxlength="120" autocomplete="name" class="field-input mt-2 !rounded-xl" @error('name') aria-invalid="true" @enderror>
                            @error('name')<p class="mt-1 text-sm text-danger" role="alert">{{ $message }}</p>@enderror
                        </div>

                        <div>
                            <label for="phone" class="font-medium">Phone</label>
                            <input type="tel" id="phone" name="phone" value="{{ old('phone') }}" required inputmode="tel" autocomplete="tel" class="field-input mt-2 !rounded-xl" @error('phone') aria-invalid="true" @enderror>
                            <p class="mt-1 text-sm text-ink/60">We text you if your request is accepted or declined.</p>
                            @error('phone')<p class="mt-1 text-sm text-danger" role="alert">{{ $message }}</p>@enderror
                        </div>

                        <button type="submit" class="btn-primary w-full sm:w-auto" x-bind:disabled="submitting">Request to Join Queue</button>
                    </form>
                @else
                    <p class="mt-4 text-ink/80">
                        @if ($availability->reason())
                            {{ $availability->reason() }} You can still visit {{ $facility->name }} in person.
                        @else
                            {{ $facility->name }} does not take queue requests online. You can visit in person.
                        @endif
                    </p>
                @endif
            </div>

            <aside class="space-y-5 lg:pt-4">
                <h2 class="text-xl font-semibold">How it works</h2>
                <p class="text-ink/80">Tell the facility when you can arrive. Staff review your request and, if they accept it, you get a place in the queue and a text with a link to follow it live.</p>
                <p class="text-ink/80">You only go to the facility when it is nearly your turn, and staff check you in when you arrive.</p>

                @if ($card->selfCheckin)
                    <p class="text-ink/80">Already at {{ $facility->name }}? <a href="{{ route('checkin.form', $facility->slug) }}" class="link">Check yourself in</a> on your own phone.</p>
                @endif

                <p class="text-ink/80">Have a queue code and PIN from a ticket? <a href="{{ route('tracking.entry', $facility->slug) }}" class="link">Follow your visit</a>.</p>
            </aside>
        </div>
    </section>
</x-site-layout>
