<?php

namespace App\Import\Passes;

use App\Enums\AssetClass;
use App\Import\ImportContext;
use App\Import\Pass;
use App\Models\Asset;
use Illuminate\Support\Collection;

/**
 * tkbtc's assets. tkbtc has a `market` (what is priced, and how) and an
 * `asset` (a user's holding of it); Nugget's asset is both, so each tkbtc
 * asset comes over joined to its market. A market nobody holds has no asset
 * and is not imported.
 *
 * A market on an exchange is crypto, priced by that market. A market with no
 * exchange is priced by the feed under its symbol: gold and silver are metal
 * and counted in troy ounces, anything else is "other".
 */
class AssetsPass implements Pass
{
    public const TYPE = 'asset';

    /** The feed symbols' bases that are metals, quoted by the troy ounce. */
    private const METALS = ['XAU', 'XAG', 'XPT', 'XPD'];

    public function name(): string
    {
        return 'Assets';
    }

    public function count(ImportContext $context): int
    {
        return $this->rows($context)->count();
    }

    public function run(ImportContext $context): int
    {
        $imported = 0;

        foreach ($this->rows($context) as $row) {
            if ($context->has(self::TYPE, $row->asset_id)) {
                continue;
            }

            $userId = $context->newId(UsersPass::TYPE, $row->user_id);

            if ($userId === null) {
                $context->warn("{$row->name} (tkbtc asset {$row->asset_id}) was not imported: its user was not.");

                continue;
            }

            $asset = new Asset([
                'name' => $row->name !== '' ? $row->name : $row->base,
                'in_total' => (bool) $row->in_total,
                'notes' => $row->notes !== null && trim($row->notes) !== '' ? trim($row->notes) : null,
                ...$this->pricing($context, $row),
            ]);
            $asset->forceFill(['user_id' => $userId])->save();

            $context->map(self::TYPE, $row->asset_id, $asset->id);
            $imported++;
        }

        return $imported;
    }

    /**
     * An asset that has gained a transaction the import did not make is
     * kept, and stays in the map so that it is not imported a second time.
     */
    public function clear(ImportContext $context): void
    {
        foreach (Asset::whereKey($context->created(self::TYPE))->get() as $asset) {
            if ($asset->hasTransactions()) {
                $context->note("{$asset->name} has transactions that were not imported, so it was kept.");

                continue;
            }

            $asset->delete();
            $context->forgetId(self::TYPE, $asset->id);
        }
    }

    /**
     * The symbol, unit, class and price source of a tkbtc asset.
     *
     * @return array<string, mixed>
     */
    private function pricing(ImportContext $context, object $row): array
    {
        $base = strtoupper($row->base);

        if ($row->exchange_id === null) {
            $metal = in_array($base, self::METALS, true);

            return [
                'symbol' => $base,
                'unit' => $metal ? 'oz' : $base,
                'class' => $metal ? AssetClass::Metal : AssetClass::Other,
                'feed_symbol' => $row->symbol !== '' ? $row->symbol : null,
            ];
        }

        $exchangeId = $context->newId(ExchangesPass::TYPE, $row->exchange_id);

        if ($exchangeId === null || $row->api_id === '') {
            $context->warn("{$row->name} (tkbtc asset {$row->asset_id}) was imported with no price: its exchange was not imported.");
        }

        return [
            'symbol' => $base,
            'unit' => $base,
            'class' => AssetClass::Crypto,
            'exchange_id' => $exchangeId !== null && $row->api_id !== '' ? $exchangeId : null,
            'market_id' => $exchangeId !== null && $row->api_id !== '' ? $row->api_id : null,
        ];
    }

    /**
     * @return Collection<int, object>
     */
    private function rows(ImportContext $context): Collection
    {
        return $context->source->db()->table('asset')
            ->join('market', 'market.market_id', '=', 'asset.market_id')
            ->orderBy('asset.asset_id')
            ->get([
                'asset.asset_id', 'asset.user_id', 'asset.in_total', 'asset.notes',
                'market.exchange_id', 'market.name', 'market.api_id', 'market.symbol', 'market.base',
            ]);
    }
}
