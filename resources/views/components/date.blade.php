{{--
    A date or time, shown in the signed-in user's timezone.

    <x-date :value="$transaction->occurred_at" />
    <x-date :value="$transaction->occurred_at" format="j M Y" />
--}}
@props(['value', 'format' => 'j M Y, g:ia'])

@if ($value)
    @php($local = App\Support\LocalTime::of($value))
    <time datetime="{{ $local->toIso8601String() }}">{{ $local->format($format) }}</time>
@endif
