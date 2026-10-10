<?php

namespace App\Import;

/**
 * One step of the import: users, exchanges, assets and so on. The importer
 * runs the passes in order, so a pass can look up what an earlier one
 * brought over with `$context->newId()`.
 *
 * A pass must be safe to run again: it passes over a tkbtc row that is
 * already in the map, and maps each row it brings over.
 */
interface Pass
{
    /**
     * What the pass imports, in a word or two, for the report: "Users".
     */
    public function name(): string;

    /**
     * How many tkbtc rows the pass would look at. It reads and changes nothing.
     */
    public function count(ImportContext $context): int;

    /**
     * Bring the rows over. Never called on a dry run.
     *
     * @return int how many rows it added to Nugget; the ones already imported are not counted
     */
    public function run(ImportContext $context): int;

    /**
     * Remove what earlier runs of this pass added to Nugget and forget its
     * map, for `--fresh`. It leaves alone a row the import only matched.
     */
    public function clear(ImportContext $context): void;
}
