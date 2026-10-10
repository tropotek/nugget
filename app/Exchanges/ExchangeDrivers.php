<?php

namespace App\Exchanges;

use App\Exchanges\Contracts\ChecksConnection;
use App\Exchanges\Contracts\ListsMarkets;
use App\Exchanges\Contracts\QuotesPrices;
use App\Exchanges\Contracts\ReportsBalances;
use App\Exchanges\Contracts\ReportsTrades;
use App\Exchanges\Drivers\BtcMarkets;
use App\Models\Exchange;
use InvalidArgumentException;

/**
 * The exchanges Nugget has a driver for, and the driver of each of a user's
 * exchanges. To support another exchange, write its driver in
 * App\Exchanges\Drivers and add it to DRIVERS.
 *
 * A driver is kept for as long as this object lives: a request, or a run of
 * a command.
 */
class ExchangeDrivers
{
    /** @var array<string, class-string<ExchangeDriver>> by the name stored in `exchanges.driver` */
    public const DRIVERS = [
        'btcmarkets' => BtcMarkets::class,
    ];

    /** @var array<class-string, string> what each interface in Contracts lets an exchange do, for its owner to read */
    public const CAPABILITIES = [
        QuotesPrices::class => 'Prices',
        ListsMarkets::class => 'Market list',
        ReportsBalances::class => 'Balances',
        ReportsTrades::class => 'Trades',
        ChecksConnection::class => 'Connection test',
    ];

    /** @var array<int, ExchangeDriver> by exchange id */
    private array $made = [];

    /**
     * @param  array<string, class-string<ExchangeDriver>>  $drivers
     */
    public function __construct(private readonly array $drivers = self::DRIVERS) {}

    /**
     * @throws InvalidArgumentException when there is no driver of that name
     */
    public function for(Exchange $exchange): ExchangeDriver
    {
        if (! $this->has($exchange->driver)) {
            throw new InvalidArgumentException("There is no exchange driver called '{$exchange->driver}'.");
        }

        return $this->made[$exchange->id] ??= new ($this->drivers[$exchange->driver])($exchange);
    }

    public function has(string $driver): bool
    {
        return isset($this->drivers[$driver]);
    }

    /**
     * Each driver's name and the exchange it is for, A to Z.
     *
     * @return array<string, string>
     */
    public function names(): array
    {
        $names = array_map(fn (string $driver) => $driver::label(), $this->drivers);
        asort($names);

        return $names;
    }

    /**
     * What a driver can do, as the labels in CAPABILITIES. None for a name with no driver.
     *
     * @return list<string>
     */
    public function capabilities(string $driver): array
    {
        $class = $this->drivers[$driver] ?? null;

        return $class === null ? [] : array_values(array_filter(
            self::CAPABILITIES,
            fn (string $contract) => is_subclass_of($class, $contract),
            ARRAY_FILTER_USE_KEY,
        ));
    }

    /**
     * Whether a driver of that name can do what an interface in Contracts says.
     *
     * @param  class-string  $contract
     */
    public function can(string $driver, string $contract): bool
    {
        return isset($this->drivers[$driver]) && is_subclass_of($this->drivers[$driver], $contract);
    }
}
