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

];
