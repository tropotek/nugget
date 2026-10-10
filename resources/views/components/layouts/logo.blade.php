{{-- The text logo, in the two colour variants the Minton skin switches between. --}}
@foreach (['dark', 'light'] as $variant)
    <a href="{{ url('/') }}" class="logo logo-{{ $variant }} text-center">
        <span class="logo-sm">
            <i class="mdi mdi-gold logo-lg-text-{{ $variant }}"></i>
        </span>
        <span class="logo-lg">
            <span class="logo-lg-text-{{ $variant }}">{{ config('app.name') }}</span>
        </span>
    </a>
@endforeach
