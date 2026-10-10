<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>
    @fonts
    @vite(['resources/scss/app.scss', 'resources/scss/icons.scss', 'resources/js/app.js'])
</head>
<body>
    {{-- Placeholder until the base layout (task 03): shows the Minton skin, icon fonts and scripts are loading. --}}
    <div class="container py-5" style="max-width: 540px">
        <div class="card">
            <div class="card-body">
                <h4 class="header-title mb-3">{{ config('app.name') }}</h4>
                <p class="text-muted">The template assets are loaded. The app layout is not built yet.</p>
                <p class="font-22 mb-3">
                    <i class="ri-coin-line" title="Remix"></i>
                    <i class="mdi mdi-gold" title="Material Design"></i>
                    <i class="fe-trending-up" title="Feather"></i>
                </p>
                <button type="button" class="btn btn-primary" id="light-dark-mode">
                    <i class="ri-moon-line me-1"></i> Light / dark
                </button>
            </div>
        </div>
    </div>
</body>
</html>
