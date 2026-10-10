@props(['title' => null])

<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>{{ $title ? $title.' | ' : '' }}{{ config('app.name') }}</title>
<link rel="shortcut icon" href="/favicon.ico">
{{-- Sets the saved light or dark theme before the first paint. minton/layout.js applies the rest once it loads. --}}
<script>
    try {
        var saved = JSON.parse(localStorage.getItem('__MINTON_CONFIG__'));
        if (saved && saved.theme) document.documentElement.setAttribute('data-bs-theme', saved.theme);
    } catch (e) {}
</script>
@fonts
@vite(['resources/scss/app.scss', 'resources/scss/icons.scss', 'resources/js/app.js'])
