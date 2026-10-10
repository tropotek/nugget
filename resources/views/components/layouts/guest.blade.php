{{--
    The signed-out page layout, for the login and password pages: the logo above one centred card.

    <x-layouts.guest title="Log in">
        …form…
    </x-layouts.guest>
--}}
@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-bs-theme="light">
<head>
    <x-layouts.head :title="$title" />
</head>
<body class="authentication-bg">
    <div class="account-pages mt-5 mb-5">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-md-8 col-lg-6 col-xl-4">
                    <div class="card">
                        <div class="card-body p-4">
                            <div class="auth-logo text-center mb-4">
                                <x-layouts.logo />
                            </div>

                            <x-layouts.flash />

                            {{ $slot }}
                        </div>
                    </div>

                    @isset($below)
                        <div class="text-center mt-3">{{ $below }}</div>
                    @endisset
                </div>
            </div>
        </div>
    </div>
</body>
</html>
