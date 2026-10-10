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
    */

    'prices' => [
        'feed_url' => env('NUGGET_PRICE_FEED_URL', 'https://forex-data-feed.swissquote.com/public-quotes/bboquotes/instrument'),
    ],

    'dev' => [
        'admin' => [
            'name' => env('NUGGET_DEV_ADMIN_NAME', 'Admin'),
            'email' => env('NUGGET_DEV_ADMIN_EMAIL', 'admin@example.com'),
            'password' => env('NUGGET_DEV_ADMIN_PASSWORD', 'password'),
        ],
    ],

];
