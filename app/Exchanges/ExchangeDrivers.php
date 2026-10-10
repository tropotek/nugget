<?php

namespace App\Exchanges;

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
}
