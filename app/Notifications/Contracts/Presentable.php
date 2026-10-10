<?php

namespace App\Notifications\Contracts;

use App\Notifications\Presented;

/**
 * A database notification that says how it is shown. It is shown from the
 * data it stored, long after it was sent, so this works from that data alone.
 */
interface Presentable
{
    /**
     * @param  array<string, mixed>  $data  as the notification's `toArray()` gave it
     */
    public static function present(array $data): Presented;
}
