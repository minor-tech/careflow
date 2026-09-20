@props(['title', 'rows'])

<section {{ $attributes->class('cf-list-card') }}>
    <h2 class="cf-list-card__title">{{ $title }}</h2>

    <dl>
        @foreach ($rows as $label => $value)
            <div class="cf-list-row text-sm">
                <dt class="w-full text-ink/70 sm:w-1/3">{{ $label }}</dt>
                <dd class="min-w-0 flex-1 break-words font-medium">{{ filled($value) ? $value : 'Not provided' }}</dd>
            </div>
        @endforeach
    </dl>
</section>
