{{-- The left sidebar. The menu comes from config/navigation.php through App\Support\Navigation. --}}
@inject('navigation', 'App\Support\Navigation')

<div class="left-side-menu">
    <div class="logo-box">
        <x-layouts.logo />
    </div>

    <div class="h-100" data-simplebar>
        <div id="sidebar-menu">
            <ul id="side-menu">
                @foreach ($navigation->items() as $item)
                    @isset($item['title'])
                        <li class="menu-title">{{ $item['title'] }}</li>
                    @else
                        <li @class(['menuitem-active' => $item['active']])>
                            <a href="{{ $item['url'] }}" @class(['active' => $item['active']])>
                                <i class="{{ $item['icon'] }}"></i>
                                <span> {{ $item['label'] }} </span>
                            </a>
                        </li>
                    @endisset
                @endforeach
            </ul>
        </div>

        <div class="clearfix"></div>
    </div>
</div>
