{{-- An illustration of the patient's own page, drawn in HTML. The names and numbers are made up: it is a picture of the product, not a photograph or a real patient. --}}
<figure class="mx-auto w-full max-w-[300px]" role="group" aria-label="Illustration of a patient's tracking page">
    <div class="rounded-[2.5rem] bg-ink p-2.5 shadow-card-hover">
        <div class="overflow-hidden rounded-[2rem] bg-canvas px-5 pb-6 pt-7" aria-hidden="true">
            <p class="text-xs font-medium text-ink/70">Sample Clinic</p>

            <div class="mt-2 flex items-baseline justify-between gap-2">
                <p class="text-base font-semibold">Brian Kamau</p>
                <p class="text-xs text-ink/70">Queue <span class="numeral !text-base text-ink">#27</span></p>
            </div>
            <p class="mt-1 text-xs text-ink/70">Currently serving: <span class="font-semibold tabular-nums text-ink">#21</span></p>

            <div class="mt-6 text-center">
                <p class="text-[5.5rem] font-bold leading-none text-primary tabular-nums">6</p>
                <p class="mt-1 text-sm text-ink/70">patients ahead of you</p>
                <p class="mx-auto mt-3 max-w-[13rem] text-xs text-ink/70">You don't need to wait here &mdash; we'll text you when you're about to be called.</p>
            </div>

            <p class="mt-6 flex items-center gap-2 text-sm font-medium"><span class="h-3 w-3 rounded-full bg-wait"></span>Waiting for Consultation</p>
            <p class="mt-1 pl-5 text-xs text-ink/60">Estimated wait: 25&ndash;35 minutes</p>

            <div class="mt-5 space-y-3 border-t border-line pt-4 text-sm">
                <p class="flex items-center gap-2"><span class="text-ok">&#10003;</span> Reception</p>
                <p class="flex items-center gap-2"><span class="inline-block h-2.5 w-2.5 rounded-full bg-wait"></span> Consultation</p>
            </div>
        </div>
    </div>
    <figcaption class="mt-3 text-center text-xs text-ink/60">Illustration of what a patient sees on their phone</figcaption>
</figure>
