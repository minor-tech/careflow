{{--
    Shown in place of the journey once a visit is complete and hasn't been
    rated. One screen, one tap to rate: the stars are large touch targets, and
    "what went wrong" only appears for 3 stars or fewer, because a happy
    patient shouldn't be asked to diagnose anything.
--}}
@php
    $firstName = \Illuminate\Support\Str::before(trim($snapshot->visit->patient->name), ' ');
    $lowestRatingThatAsks = 1;
    $highestRatingThatAsks = \App\Models\Feedback::LOW_RATING;
@endphp

<section class="mt-8 border-t border-line pt-6" aria-labelledby="feedback-heading">
    <h2 id="feedback-heading" class="text-xl font-semibold">Thank you, {{ $firstName }}.</h2>

    <form method="POST"
          action="{{ route('tracking.feedback.store', $snapshot->visit->tracking_token) }}"
          class="mt-4"
          x-data="{ rating: {{ (int) old('rating', 0) }}, get unhappy() { return this.rating >= {{ $lowestRatingThatAsks }} && this.rating <= {{ $highestRatingThatAsks }}; } }">
        <fieldset>
            <legend class="font-medium">How was your visit?</legend>

            <div class="mt-3 flex justify-center gap-1">
                @foreach (range(1, 5) as $star)
                    <div class="relative">
                        <input type="radio"
                               name="rating"
                               id="rating-{{ $star }}"
                               value="{{ $star }}"
                               x-model.number="rating"
                               @checked((int) old('rating') === $star)
                               @required($star === 1)
                               class="peer sr-only">
                        <label for="rating-{{ $star }}"
                               class="flex h-14 w-14 cursor-pointer items-center justify-center rounded-md peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-primary"
                               x-bind:class="rating >= {{ $star }} ? 'text-wait' : 'text-ink/30'">
                            <span class="sr-only">{{ $star }} {{ $star === 1 ? 'star' : 'stars' }}</span>
                            <svg viewBox="0 0 24 24" class="h-10 w-10 stroke-current" stroke-width="1.5" stroke-linejoin="round" aria-hidden="true"
                                 x-bind:class="rating >= {{ $star }} ? 'fill-current' : 'fill-none'">
                                <path d="M12 3.5l2.6 5.6 6.1.7-4.5 4.2 1.2 6L12 17l-5.4 3 1.2-6L3.3 9.8l6.1-.7L12 3.5z"/>
                            </svg>
                        </label>
                    </div>
                @endforeach
            </div>

            @error('rating')
                <p class="mt-2 text-center text-sm text-danger" role="alert">{{ $message }}</p>
            @enderror
        </fieldset>

        <fieldset x-show="unhappy" x-cloak class="mt-6">
            <legend class="font-medium">What went wrong? <span class="font-normal text-ink/60">(optional, select any)</span></legend>

            <div class="mt-2 grid grid-cols-2 gap-2">
                @foreach (\App\Enums\FeedbackIssue::options() as $value => $label)
                    <label class="flex min-h-11 items-center gap-2 rounded-md border border-line bg-white px-3 text-sm">
                        <input type="checkbox"
                               name="issues[]"
                               value="{{ $value }}"
                               @checked(in_array($value, (array) old('issues', []), true))
                               x-bind:disabled="! unhappy"
                               class="h-5 w-5 rounded border-line text-primary focus:ring-primary">
                        {{ $label }}
                    </label>
                @endforeach
            </div>
        </fieldset>

        <div class="mt-6">
            <label for="feedback-comment" class="font-medium">Anything else? <span class="font-normal text-ink/60">(optional)</span></label>
            <textarea id="feedback-comment" name="comment" rows="3" maxlength="1000" class="field-input mt-2">{{ old('comment') }}</textarea>
            @error('comment')
                <p class="mt-1 text-sm text-danger" role="alert">{{ $message }}</p>
            @enderror
        </div>

        <x-primary-button class="mt-6 w-full">Send feedback</x-primary-button>
    </form>
</section>
