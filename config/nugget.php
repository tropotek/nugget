<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Login and recovery throttling
    |--------------------------------------------------------------------------
    |
    | An email is locked out from one IP address after this many failed logins,
    | for this many minutes. The same limit applies to how many password reset
    | links one address can ask for.
    |
    */

    'login' => [
        'max_attempts' => (int) env('NUGGET_LOGIN_MAX_ATTEMPTS', 5),
        'lockout_minutes' => (int) env('NUGGET_LOGIN_LOCKOUT_MINUTES', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Development seed data
    |--------------------------------------------------------------------------
    |
    | The admin that the database seeder creates, so a rebuilt development
    | database always has the same account to sign in with. The seeder does
    | nothing in production.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Prices
    |--------------------------------------------------------------------------
    |
    | The Swissquote forex feed that prices an asset with a feed symbol. The
    | symbol (XAU/AUD) is added to the URL.
    |
    | A price is fetched every 5 minutes and kept for this many hours. The
    | last price of each day is kept for good.
    |
    */

    'prices' => [
        'intraday_hours' => (int) env('NUGGET_PRICE_INTRADAY_HOURS', 48),
        'feed_url' => env('NUGGET_PRICE_FEED_URL', 'https://forex-data-feed.swissquote.com/public-quotes/bboquotes/instrument'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Exchanges
    |--------------------------------------------------------------------------
    |
    | Where each exchange driver finds its API.
    |
    */

    'exchanges' => [
        'btcmarkets' => [
            'url' => env('NUGGET_BTCMARKETS_URL', 'https://api.btcmarkets.net/v3'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Exchange sync
    |--------------------------------------------------------------------------
    |
    | An exchange's balance of an asset and the ledger's may differ by this
    | many units before the owner is told. It allows for rounding dust.
    |
    */

    'sync' => [
        'balance_tolerance' => env('NUGGET_SYNC_BALANCE_TOLERANCE', '0.000001'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | A notification that has been read is deleted this many days after it
    | last changed. An unread one is kept, and so is a balance mismatch.
    |
    */

    'notifications' => [
        'keep_read_days' => (int) env('NUGGET_NOTIFICATIONS_KEEP_READ_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | TradingView
    |--------------------------------------------------------------------------
    |
    | The symbols the TradingView page offers after the user's own markets,
    | and the name TradingView has for a timezone where it differs from
    | PHP's: it knows the state capitals by a handful of names only.
    |
    */

    'tradingview' => [
        'watchlist' => ['BTCAUD', 'ADAAUD', 'ETHAUD', 'AUDUSD'],
        'timezones' => [
            'Australia/Melbourne' => 'Australia/Sydney',
            'Australia/Canberra' => 'Australia/Sydney',
            'Australia/Hobart' => 'Australia/Sydney',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tax report
    |--------------------------------------------------------------------------
    |
    | The asset classes (crypto, metal, other) a year's tax report takes in
    | until its user chooses others on the report page.
    |
    */

    'tax' => [
        'default_asset_classes' => ['crypto'],
    ],

    /*
    |--------------------------------------------------------------------------
    | API
    |--------------------------------------------------------------------------
    |
    | How many requests a minute one API token may make.
    |
    */

    'api' => [
        'per_minute' => (int) env('NUGGET_API_PER_MINUTE', 60),
    ],

    'dev' => [
        'admin' => [
            'name' => env('NUGGET_DEV_ADMIN_NAME', 'Admin'),
            'email' => env('NUGGET_DEV_ADMIN_EMAIL', 'admin@example.com'),
            'password' => env('NUGGET_DEV_ADMIN_PASSWORD', 'password'),
        ],
    ],

];
