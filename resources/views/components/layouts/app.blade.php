{{--
    The signed-in page layout: top bar, left sidebar, page title, flash messages and footer.

    <x-layouts.app title="Transactions">
        <x-slot:actions><a class="btn btn-primary" href="…">Add</a></x-slot:actions>
        …page…
    </x-layouts.app>
--}}
@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-bs-theme="light">
<head>
    <x-layouts.head :title="$title" />
</head>
<body>
    <div id="wrapper">
        <x-layouts.topbar />
        <x-layouts.sidebar />

        <div class="content-page">
            <div class="content">
                <div class="container-fluid">
                    <x-layouts.page-title :title="$title" :actions="$actions ?? null" />
                    <x-layouts.flash />

                    {{ $slot }}
                </div>
            </div>

            <x-layouts.footer />
        </div>
    </div>
</body>
</html>
