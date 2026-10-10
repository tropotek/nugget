<?php

use Illuminate\Support\Facades\Schedule;

/*
| The schedule, run by the `scheduler` container (`php artisan schedule:work`).
| The 10 is minutes: a lock left by a run that died is dropped after that long.
*/

Schedule::command('nugget:fetch-prices')->everyFiveMinutes()->withoutOverlapping(10);

// Times are UTC. This one only has to be off the 5-minute marks.
Schedule::command('nugget:prune-prices')->dailyAt('03:20')->withoutOverlapping(10);
