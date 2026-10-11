<?php

namespace App\Import;

use Illuminate\Support\Facades\DB;

/**
 * Runs the import passes in order against a tkbtc database.
 *
 * A run is all or nothing: every pass is inside one database transaction, so
 * one that fails part-way leaves nothing half imported. A dry run only
 * counts.
 *
 * Outside production, a run first drops the seeded development data, so the
 * real account is not left beside a demo one.
 */
class Importer
{
    /** @var list<class-string<Pass>> in the order they depend on each other */
    public const PASSES = [
        Passes\UsersPass::class,
        Passes\ExchangesPass::class,
        Passes\AssetsPass::class,
        Passes\AssetLogPass::class,
        Passes\TicksPass::class,
    ];

    /**
     * @param  list<Pass>|null  $passes  the passes to run, in place of PASSES
     */
    public function __construct(private readonly ?array $passes = null) {}

    private function seeded(): SeededData
    {
        return app(SeededData::class);
    }

    /**
     * @return list<Pass>
     */
    public function passes(): array
    {
        return $this->passes ?? array_map(fn (string $class) => app($class), static::PASSES);
    }

    /**
     * Run every pass. With `$fresh`, what earlier runs imported is cleared
     * first, the last pass's rows before the first's.
     *
     * @return array<string, array{count: int, imported: ?int}> by pass name; `imported` is null on a dry run
     */
    public function run(ImportContext $context, bool $fresh = false): array
    {
        if ($context->dryRun) {
            return $this->report($context, fn (Pass $pass) => null);
        }

        return DB::transaction(function () use ($context, $fresh) {
            // Real data is not left beside the made-up development data.
            if (($dropped = $this->seeded()->drop()) > 0) {
                $context->note("Dropped the seeded development data: {$dropped} made-up ".($dropped === 1 ? 'user' : 'users').' and all they owned.');
            }

            if ($fresh) {
                foreach (array_reverse($this->passes()) as $pass) {
                    $pass->clear($context);
                }
            }

            return $this->report($context, fn (Pass $pass) => $pass->run($context));
        });
    }

    /**
     * @param  callable(Pass): ?int  $import
     * @return array<string, array{count: int, imported: ?int}>
     */
    private function report(ImportContext $context, callable $import): array
    {
        $report = [];

        foreach ($this->passes() as $pass) {
            $report[$pass->name()] = ['count' => $pass->count($context), 'imported' => $import($pass)];
        }

        return $report;
    }
}
