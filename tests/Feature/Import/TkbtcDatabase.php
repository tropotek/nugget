<?php

namespace Tests\Feature\Import;

use App\Import\TkbtcSource;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * A made-up tkbtc database for an import test: the `tkbtc` connection as an
 * in-memory SQLite database with tkbtc's tables.
 */
trait TkbtcDatabase
{
    protected function setUpTkbtc(): Connection
    {
        config(['database.connections.'.TkbtcSource::CONNECTION => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::purge(TkbtcSource::CONNECTION);

        $tkbtc = DB::connection(TkbtcSource::CONNECTION);
        $tkbtc->unprepared(file_get_contents(base_path('tests/Fixtures/tkbtc/schema.sql')));

        return $tkbtc;
    }

    protected function tkbtc(): Connection
    {
        return DB::connection(TkbtcSource::CONNECTION);
    }
}
