@php
    $supportEmail = config('careflow.support_email');
@endphp

<x-site-layout title="Contact" description="Write to the CareFlow team about bringing CareFlow to your facility.">

    <section class="bg-gradient-to-b from-tint-mid to-canvas px-4 pb-10 pt-8 text-center lg:pb-16 lg:pt-14">
        <div class="mx-auto max-w-3xl">
            <span class="badge-section bg-white">Contact</span>
            <h1 class="display-section mt-5">Talk to us</h1>
            <p class="mt-6 text-lg text-ink/80">Tell us about your facility and what you'd like to fix in your waiting room.</p>
        </div>
    </section>

    <section class="px-4 pb-14 pt-6 lg:pb-28">
        <div class="mx-auto grid max-w-5xl gap-10 lg:grid-cols-[1.4fr_1fr]">
            <div class="card-soft p-6 sm:p-8">
                @if (session('status'))
                    <div role="status" class="mb-6 rounded-xl border-l-4 border-l-ok bg-tint-light px-4 py-3 text-ink">{{ session('status') }}</div>
                @endif

                <form method="POST" action="{{ route('contact.store') }}" class="space-y-5" novalidate>
                    @csrf

                    {{-- Real people never see this. If it comes back filled in, it was a bot. --}}
                    <div class="absolute -left-[9999px]" aria-hidden="true">
                        <label for="website">Leave this empty</label>
                        <input type="text" id="website" name="website" tabindex="-1" autocomplete="off" value="">
                    </div>

                    <div>
                        <label for="name" class="font-medium">Your name</label>
                        <input type="text" id="name" name="name" value="{{ old('name') }}" required maxlength="120" autocomplete="name" class="field-input mt-2 !rounded-xl" @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
                        @error('name')<p id="name-error" class="mt-1 text-sm text-danger" role="alert">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label for="contact" class="font-medium">Email or phone number</label>
                        <input type="text" id="contact" name="contact" value="{{ old('contact') }}" required maxlength="255" autocomplete="email" class="field-input mt-2 !rounded-xl" @error('contact') aria-invalid="true" aria-describedby="contact-error" @enderror>
                        @error('contact')<p id="contact-error" class="mt-1 text-sm text-danger" role="alert">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label for="facility_name" class="font-medium">Facility name <span class="font-normal text-ink/60">(optional)</span></label>
                        <input type="text" id="facility_name" name="facility_name" value="{{ old('facility_name') }}" maxlength="255" autocomplete="organization" class="field-input mt-2 !rounded-xl">
                        @error('facility_name')<p class="mt-1 text-sm text-danger" role="alert">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label for="message" class="font-medium">Message</label>
                        <textarea id="message" name="message" rows="6" required maxlength="5000" class="field-input mt-2 !rounded-xl" @error('message') aria-invalid="true" aria-describedby="message-error" @enderror>{{ old('message') }}</textarea>
                        @error('message')<p id="message-error" class="mt-1 text-sm text-danger" role="alert">{{ $message }}</p>@enderror
                    </div>

                    <button type="submit" class="btn-primary w-full sm:w-auto">Send message</button>
                </form>
            </div>

            <aside class="space-y-5 lg:pt-4">
                <h2 class="text-xl font-semibold">What happens next</h2>
                <p class="text-ink/80">A person on the CareFlow team reads every message and replies using the email address or phone number you give.</p>
                <p class="text-ink/80">Ready to go ahead? You don't need to wait for a reply: <a href="{{ route('facility.register') }}" class="link">register your facility</a> and we'll review it.</p>
                @if (filled($supportEmail))
                    <p class="text-ink/80">You can also email us at <a href="mailto:{{ $supportEmail }}" class="link">{{ $supportEmail }}</a>.</p>
                @endif
            </aside>
        </div>
    </section>
</x-site-layout>
