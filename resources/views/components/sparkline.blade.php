{{--
    A small line of amounts over time, green when the last is not below the first and red when it is.
    Nothing is drawn for fewer than two amounts.

    <x-sparkline :amounts="$bids" label="Price over 30 days" />
--}}
@props(['amounts', 'label', 'width' => 120, 'height' => 32])

@php
    $amounts = array_values(collect($amounts)->all());
    $points = App\Support\Sparkline::points($amounts, $width, $height);
@endphp

@if ($points !== '')
    <svg width="{{ $width }}" height="{{ $height }}" viewBox="0 0 {{ $width }} {{ $height }}" role="img" aria-label="{{ $label }}"
        class="{{ end($amounts)->lessThan($amounts[0]) ? 'text-danger' : 'text-success' }}">
        <polyline points="{{ $points }}" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round" />
    </svg>
@endif
