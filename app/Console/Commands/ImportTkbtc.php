<?php

namespace App\Console\Commands;

use App\Import\ImportContext;
use App\Import\Importer;
use App\Import\TkbtcSource;
use Illuminate\Console\Command;
use Throwable;

class ImportTkbtc extends Command
{
    protected $signature = 'nugget:import-tkbtc
                            {--dry-run : Report what would be imported and change nothing}
                            {--fresh : Clear what earlier imports brought over first}';

    protected $description = 'Import a tkbtc database (the TKBTC_DB_* connection): users, exchanges, assets, the asset log and prices. Safe to run again';

    public function handle(Importer $importer, TkbtcSource $source): int
    {
        try {
            $source->connect();
            $counts = $source->counts();
        } catch (Throwable $e) {
            $this->components->error('The tkbtc database could not be read: '.$e->getMessage());
            $this->line('  Set TKBTC_DB_HOST, TKBTC_DB_PORT, TKBTC_DB_DATABASE, TKBTC_DB_USERNAME and TKBTC_DB_PASSWORD in .env.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $this->components->info('tkbtc source: '.$source->describe());
        $this->table(['Table', 'Rows'], collect($counts)->map(fn (int $rows, string $table) => [$table, $rows])->values()->all());

        $context = new ImportContext($source, $dryRun);

        try {
            $report = $importer->run($context, (bool) $this->option('fresh'));
        } catch (Throwable $e) {
            $this->components->error('The import failed and nothing was changed: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($report === []) {
            $this->components->warn('There are no import passes yet, so nothing was imported.');

            return self::SUCCESS;
        }

        $this->table(
            ['Pass', 'In tkbtc', $dryRun ? 'Imported (dry run)' : 'Imported'],
            collect($report)->map(fn (array $pass, string $name) => [$name, $pass['count'], $pass['imported'] ?? '—'])->values()->all(),
        );

        foreach ($context->warnings as $warning) {
            $this->components->warn($warning);
        }

        foreach ($context->notes as $note) {
            $this->line("  {$note}");
        }

        $this->components->info($dryRun ? 'Dry run: nothing was changed.' : 'Import finished.');

        return self::SUCCESS;
    }
}
