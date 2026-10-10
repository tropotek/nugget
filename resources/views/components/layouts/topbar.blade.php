<div class="navbar-custom">
    <div class="container-fluid">
        <ul class="list-unstyled topnav-menu float-end mb-0">
            <li class="d-none d-lg-inline-block">
                <a class="nav-link" data-toggle="fullscreen" href="#" title="Full screen">
                    <i class="fe-maximize noti-icon"></i>
                </a>
            </li>

            <li>
                <button type="button" class="nav-link border-0 bg-transparent" id="light-dark-mode" title="Light or dark">
                    <i class="ri-moon-line noti-icon theme-icon-light"></i>
                    <i class="ri-sun-line noti-icon theme-icon-dark"></i>
                </button>
            </li>

            @auth
                <li class="dropdown notification-list topbar-dropdown">
                    <a class="nav-link dropdown-toggle nav-user me-0" data-bs-toggle="dropdown" href="#" role="button" aria-haspopup="false" aria-expanded="false">
                        <i class="ri-account-circle-line noti-icon"></i>
                        <span class="pro-user-name ms-1">
                            {{ auth()->user()->name }} <i class="mdi mdi-chevron-down"></i>
                        </span>
                    </a>
                    <div class="dropdown-menu dropdown-menu-end profile-dropdown">
                        @if (Route::has('profile.edit'))
                            <a href="{{ route('profile.edit') }}" class="dropdown-item notify-item">
                                <i class="ri-account-circle-line"></i>
                                <span>Profile</span>
                            </a>
                        @endif

                        @if (Route::has('logout'))
                            <div class="dropdown-divider"></div>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="dropdown-item notify-item">
                                    <i class="ri-logout-box-line"></i>
                                    <span>Log out</span>
                                </button>
                            </form>
                        @endif
                    </div>
                </li>
            @endauth
        </ul>

        <div class="logo-box">
            <x-layouts.logo />
        </div>

        <ul class="list-unstyled topnav-menu topnav-menu-left m-0">
            <li>
                <button class="button-menu-mobile" title="Menu">
                    <i class="fe-menu"></i>
                </button>
            </li>
        </ul>

        <div class="clearfix"></div>
    </div>
</div>
