<?php

namespace App\Import;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The tkbtc database being imported: the `tkbtc` connection in
 * config/database.php, set by the TKBTC_DB_* environment variables. It can
 * be a restored dump or the live server. Nothing is ever written to it.
 */
class TkbtcSource
{
    public const CONNECTION = 'tkbtc';

    /** The tables the import reads, all of which a tkbtc database has. */
    public const TABLES = ['user', 'auth', 'exchange', 'market', 'asset', 'asset_log', 'tick'];

    /**
     * Open the connection and check that it looks like tkbtc.
     *
     * @throws RuntimeException when it cannot be reached or a table is missing
     */
    public function connect(): Connection
    {
        $db = $this->db();
        $missing = array_values(array_filter(self::TABLES, fn (string $table) => ! $db->getSchemaBuilder()->hasTable($table)));

        if ($missing !== []) {
            throw new RuntimeException('This is not a tkbtc database: it has no '.implode(', ', $missing).' table.');
        }

        return $db;
    }

    public function db(): Connection
    {
        return DB::connection(self::CONNECTION);
    }

    /**
     * How many rows each table has.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        return collect(self::TABLES)->mapWithKeys(fn (string $table) => [$table => $this->db()->table($table)->count()])->all();
    }

    /**
     * Where the source is, without its password, to show the operator.
     */
    public function describe(): string
    {
        $config = $this->db()->getConfig();

        return ($config['driver'] ?? '') === 'sqlite'
            ? "sqlite {$config['database']}"
            : "{$config['driver']} {$config['username']}@{$config['host']}:{$config['port']}/{$config['database']}";
    }
}
