<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static mixed get(string $key)
 * @method static void set(string $key, mixed $value)
 * @method static array<string, mixed> all()
 * @method static array<string, array{default: mixed, type: string, rules: array<int, mixed>}> definitions()
 * @method static int currentYear()
 *
 * @see \App\Support\Settings
 */
class Settings extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \App\Support\Settings::class;
    }
}
