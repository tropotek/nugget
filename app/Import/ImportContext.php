<?php

namespace App\Import;

use Illuminate\Support\Facades\DB;

/**
 * What one run of the import shares between its passes: the source, whether
 * it is a dry run, the map from tkbtc ids to Nugget ids, and what to tell
 * the operator at the end.
 *
 * The map is kept in the `import_ids` table, so a later run knows what an
 * earlier one brought over. A dry run reads it and never writes it.
 */
class ImportContext
{
    /** @var array<string, array<string, int>> the map as read so far, by type and tkbtc id */
    private array $ids = [];

    /** @var list<string> problems the operator should see: a row skipped, a password that needs resetting */
    public array $warnings = [];

    /** @var list<string> lines for the end of the report */
    public array $notes = [];

    public function __construct(
        public readonly TkbtcSource $source,
        public readonly bool $dryRun = false,
    ) {}

    /**
     * The Nugget id of a tkbtc row, or null when it has not been imported.
     *
     * @param  string  $type  what kind of row: "user", "exchange", "asset"
     */
    public function newId(string $type, int|string|null $legacyId): ?int
    {
        if ($legacyId === null) {
            return null;
        }

        $this->ids[$type] ??= DB::table('import_ids')->where('type', $type)->pluck('new_id', 'legacy_id')
            ->map(fn (mixed $id) => (int) $id)->all();

        return $this->ids[$type][(string) $legacyId] ?? null;
    }

    public function has(string $type, int|string $legacyId): bool
    {
        return $this->newId($type, $legacyId) !== null;
    }

    /**
     * Record that a tkbtc row is now a Nugget row.
     *
     * @param  bool  $created  false when the Nugget row was already there and the import only matched it
     */
    public function map(string $type, int|string $legacyId, int $newId, bool $created = true): void
    {
        $this->newId($type, $legacyId);
        $this->ids[$type][(string) $legacyId] = $newId;

        if (! $this->dryRun) {
            DB::table('import_ids')->updateOrInsert(
                ['type' => $type, 'legacy_id' => (string) $legacyId],
                ['new_id' => $newId, 'created' => $created, 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    /**
     * The Nugget ids of the rows of a type that the import made itself,
     * not the ones it only matched: what clearing the import may delete.
     *
     * @return list<int>
     */
    public function created(string $type): array
    {
        return DB::table('import_ids')->where('type', $type)->where('created', true)->pluck('new_id')
            ->map(fn (mixed $id) => (int) $id)->all();
    }

    /**
     * Drop the map for a type, once its rows are cleared.
     */
    public function forget(string $type): void
    {
        unset($this->ids[$type]);

        DB::table('import_ids')->where('type', $type)->delete();
    }

    public function warn(string $message): void
    {
        $this->warnings[] = $message;
    }

    public function note(string $message): void
    {
        $this->notes[] = $message;
    }
}
