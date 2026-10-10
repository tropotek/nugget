<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * What the /up route reports.
 */
class HealthStatus
{
    public function __construct(private ?string $versionFile = null) {}

    public function version(): string
    {
        $file = $this->versionFile ?? base_path('version.md');
        $version = is_readable($file) ? trim((string) file_get_contents($file)) : '';

        return $version !== '' ? $version : 'unknown';
    }

    /**
     * Whether the SQLite database accepts a write.
     *
     * Setting user_version to the value it already has needs a write lock but changes nothing,
     * and needs no table, so it works before the first migration.
     */
    public function databaseWritable(): bool
    {
        try {
            $version = (int) DB::scalar('PRAGMA user_version');
            DB::unprepared('PRAGMA user_version = '.$version);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
