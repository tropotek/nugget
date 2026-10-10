<?php

namespace App\Import;

use Illuminate\Support\Facades\DB;

/**
 * Runs the import passes in order against a tkbtc database.
 *
 * A run is all or nothing: every pass is inside one database transaction, so
 * one that fails part-way leaves nothing half imported. A dry run only
 * counts.
 */
class Importer
{
    /** @var list<class-string<Pass>> in the order they depend on each other */
    public const PASSES = [
        Passes\UsersPass::class,
        Passes\ExchangesPass::class,
    ];

    /**
     * @param  list<Pass>|null  $passes  the passes to run, in place of PASSES
     */
    public function __construct(private readonly ?array $passes = null) {}

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
