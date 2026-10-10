<?php

namespace App\Console\Commands;

use App\Backfill\Backfill;
use App\Backfill\BtcMarketsCsv;
use App\Models\Exchange;
use Illuminate\Console\Command;
use Throwable;

class BackfillExchange extends Command
{
    protected $signature = 'nugget:backfill
                            {exchange : The id of the exchange (a BTC Markets account)}
                            {--csv= : The BTC Markets transaction report. php://stdin reads it from a pipe}
                            {--dry-run : Report what would change and change nothing}';

    protected $description = 'Replace an exchange\'s estimated history with the real one from its BTC Markets transaction report CSV';

    public function handle(Backfill $backfill): int
    {
        $exchange = Exchange::with('user')->find($this->argument('exchange'));

        if (! $exchange) {
            $this->components->error("There is no exchange with the id {$this->argument('exchange')}.");

            return self::FAILURE;
        }

        if ($exchange->driver !== 'btcmarkets') {
            $this->components->error("{$exchange->label} is a {$exchange->driver} account. Only a BTC Markets transaction report can be read.");

            return self::FAILURE;
        }

        $path = (string) $this->option('csv');
        $stream = $path === '' ? false : @fopen($path, 'r');

        if ($stream === false) {
            $this->components->error($path === '' ? 'Pass the report: --csv=path/to/TransactionReport.csv' : "The file {$path} could not be opened.");

            return self::FAILURE;
        }

        try {
            $report = BtcMarketsCsv::read($stream);
            $dryRun = (bool) $this->option('dry-run');
            $result = $backfill->run($exchange, $report, $dryRun);
        } catch (Throwable $e) {
            $this->components->error('The backfill failed and nothing was changed: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            fclose($stream);
        }

        if ($report->entries === []) {
            $this->components->warn('The report has no coin transactions, so nothing was changed.');

            return self::SUCCESS;
        }

        $this->components->info("{$exchange->label} of {$exchange->user->email}: {$report->rows} rows, {$report->startsAt->toDateString()} to {$report->endsAt->toDateString()} (UTC).");

        $this->table(['Stored', 'Transactions'], collect($result->added)->map(fn (int $count, string $type) => [$type, $count])->values()->all());
        $this->line("  Already in the ledger and passed over: {$result->alreadyThere}");
        $this->line("  Estimated transactions replaced: {$result->estimatesRemoved}");
        $this->line("  Synced trades the report also has, removed: {$result->syncedRemoved}");
        $this->line("  Valued from the nearest trade in the report (flagged estimated): {$result->valuedFromTheReport}");
        $this->line("  Could not be valued, stored at \$0 (flagged estimated): {$result->notValued}");

        if ($result->assetsCreated) {
            $this->line('  Assets made: '.implode(', ', $result->assetsCreated));
        }

        $this->newLine();
        $this->table(
            ['Asset', 'Units before', 'Cost base before', 'Units after', 'Cost base after'],
            collect($result->summary)->map(fn (array $asset, string $name) => [$name, $asset['before'][0], '$'.$asset['before'][1], $asset['after'][0], '$'.$asset['after'][1]])->values()->all(),
        );

        if ($result->depositsWithoutCost) {
            $count = count($result->depositsWithoutCost);
            $this->components->warn(($count === 1 ? '1 deposit has' : "{$count} deposits have").' no cost known. Each is at $0 and flagged estimated: put the real cost on it.');

            foreach ($result->depositsWithoutCost as $deposit) {
                $this->line("  {$deposit}");
            }
        }

        foreach ($result->warnings as $warning) {
            $this->components->warn($warning);
        }

        $this->components->info($dryRun ? 'Dry run: nothing was changed.' : 'Backfill finished. The trade sync carries on from the end of the report.');

        return self::SUCCESS;
    }
}
