<?php

namespace App\Import\Passes;

use App\Exchanges\ExchangeDrivers;
use App\Import\ImportContext;
use App\Import\Pass;
use App\Models\Exchange;
use App\Support\Decimal;
use Illuminate\Support\Collection;

/**
 * tkbtc's exchange accounts. tkbtc keeps the API key and secret as they are;
 * Nugget encrypts them at rest.
 *
 * An exchange Nugget has no driver for (coinspot, binance) is reported and
 * not imported: there would be nothing to sync it with.
 */
class ExchangesPass implements Pass
{
    public const TYPE = 'exchange';

    public function __construct(private readonly ExchangeDrivers $drivers) {}

    public function name(): string
    {
        return 'Exchanges';
    }

    public function count(ImportContext $context): int
    {
        return $this->rows($context)->count();
    }

    public function run(ImportContext $context): int
    {
        $imported = 0;
        $names = $this->drivers->names();

        foreach ($this->rows($context) as $row) {
            if ($context->has(self::TYPE, $row->exchange_id)) {
                continue;
            }

            if (! $this->drivers->has($row->driver)) {
                $context->warn("The {$row->driver} exchange (tkbtc exchange {$row->exchange_id}) was not imported: Nugget has no driver for it.");

                continue;
            }

            $userId = $context->newId(UsersPass::TYPE, $row->user_id);

            if ($userId === null) {
                $context->warn("The {$row->driver} exchange (tkbtc exchange {$row->exchange_id}) was not imported: its user was not.");

                continue;
            }

            $exchange = new Exchange([
                'driver' => $row->driver,
                'label' => $names[$row->driver],
                'api_key' => $row->api_key !== '' ? $row->api_key : null,
                'secret' => $row->secret !== '' ? $row->secret : null,
                'active' => (bool) $row->active,
            ]);
            $exchange->forceFill([
                'user_id' => $userId,
                // tkbtc's `credit` is the AUD on the exchange.
                'aud_balance' => Decimal::of((string) $row->credit)->round(Decimal::AUD_SCALE),
            ])->save();

            $context->map(self::TYPE, $row->exchange_id, $exchange->id);
            $imported++;
        }

        return $imported;
    }

    public function clear(ImportContext $context): void
    {
        Exchange::whereKey($context->created(self::TYPE))->get()->each->delete();
        $context->forget(self::TYPE);
    }

    /**
     * @return Collection<int, object>
     */
    private function rows(ImportContext $context): Collection
    {
        return $context->source->db()->table('exchange')->orderBy('exchange_id')
            ->get(['exchange_id', 'user_id', 'driver', 'api_key', 'secret', 'credit', 'active']);
    }
}
