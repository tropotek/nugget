<?php

use Illuminate\Support\Facades\Schedule;

/*
| The schedule, run by the `scheduler` container (`php artisan schedule:work`).
| The 10 is minutes: a lock left by a run that died is dropped after that long.
*/

Schedule::command('nugget:fetch-prices')->everyFiveMinutes()->withoutOverlapping(10);
