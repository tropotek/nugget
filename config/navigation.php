<?php

/*
|--------------------------------------------------------------------------
| Sidebar menu
|--------------------------------------------------------------------------
|
| The one definition of the left sidebar, read by App\Support\Navigation.
| Entries appear in this order.
|
|   ['title' => 'Heading']
|       A section heading. Shown only when a link follows it.
|
|   ['label' => 'Assets', 'icon' => 'ri-coin-line', 'route' => 'assets.index']
|       A link to a named route. It is left out until that route exists, so
|       screens that are not built yet are listed here ahead of time.
|
|       'active' => ['assets.*', 'parcels.*']
|           Route name patterns the link is highlighted on. The default is the
|           route's own group: "assets.index" is active on "assets.*".
|
|       'can' => 'admin'
|           A Gate ability the user needs to see the link.
|
| Icons are Remix (ri-), Material Design (mdi mdi-) or Feather (fe-) classes.
|
*/

return [
    ['title' => 'Portfolio'],
    ['label' => 'Dashboard', 'icon' => 'ri-dashboard-line', 'route' => 'dashboard'],
    ['label' => 'Assets', 'icon' => 'ri-coin-line', 'route' => 'assets.index'],
    ['label' => 'Transactions', 'icon' => 'ri-exchange-line', 'route' => 'transactions.index'],
    ['label' => 'Exchanges', 'icon' => 'ri-bank-line', 'route' => 'exchanges.index'],
    ['label' => 'Tax report', 'icon' => 'ri-file-list-3-line', 'route' => 'tax-report.index'],
    ['label' => 'TradingView', 'icon' => 'ri-line-chart-line', 'route' => 'tradingview'],

    ['title' => 'Admin'],
    ['label' => 'Users', 'icon' => 'ri-group-line', 'route' => 'users.index', 'can' => 'admin'],
    ['label' => 'Settings', 'icon' => 'ri-settings-3-line', 'route' => 'settings.edit', 'can' => 'admin'],
];
