{{--
    An unrealised gain or loss in AUD, green or red, with its percentage when there is one.

    <x-gain :value="$valuation->gain()" :percent="$valuation->gainPercent()" />
--}}
@props(['value', 'percent' => null])

@if ($value)
    <span @class(['text-success' => $value->isPositive(), 'text-danger' => $value->isNegative()])>
        {{ $value->isNegative() ? '-' : '' }}${{ $value->abs()->format() }}
        @if ($percent)
            <small>{{ $percent->format() }}%</small>
        @endif
    </span>
@endif
