@props(['title' => null])

<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>{{ $title ? $title.' | ' : '' }}{{ config('app.name') }}</title>
{{-- The nugget. The .ico is for a browser that does not take an SVG favicon; the PNG is a phone's home screen. --}}
<link rel="icon" href="/favicon.ico" sizes="32x32">
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
{{-- Sets the saved light or dark theme before the first paint. minton/layout.js applies the rest once it loads. --}}
<script>
    try {
        var saved = JSON.parse(localStorage.getItem('__MINTON_CONFIG__'));
        if (saved && saved.theme) document.documentElement.setAttribute('data-bs-theme', saved.theme);
    } catch (e) {}
</script>
@fonts
@vite(['resources/scss/app.scss', 'resources/scss/icons.scss', 'resources/js/app.js'])
