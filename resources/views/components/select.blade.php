@props(['options' => [], 'selected' => null, 'placeholder' => null])

<select {{ $attributes->merge(['class' => 'field-input']) }}>
    @if ($placeholder !== null)
        <option value="">{{ $placeholder }}</option>
    @endif
    @foreach ($options as $value => $label)
        <option value="{{ $value }}" @selected((string) $selected === (string) $value)>{{ $label }}</option>
    @endforeach
</select>
