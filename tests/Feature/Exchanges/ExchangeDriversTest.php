<?php

namespace Tests\Feature\Exchanges;

use App\Exchanges\Drivers\BtcMarkets;
use App\Exchanges\ExchangeDriver;
use App\Exchanges\ExchangeDrivers;
use App\Models\Exchange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Fakes\PlainDriver;
use Tests\TestCase;

class ExchangeDriversTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_makes_the_driver_an_exchange_names(): void
    {
        $drivers = $this->app->make(ExchangeDrivers::class);

        $this->assertInstanceOf(BtcMarkets::class, $drivers->for(Exchange::factory()->create(['driver' => 'btcmarkets'])));
    }

    public function test_an_exchange_keeps_one_driver(): void
    {
        $drivers = $this->app->make(ExchangeDrivers::class);
        $exchange = Exchange::factory()->create();

        $this->assertSame($drivers->for($exchange), $drivers->for(Exchange::find($exchange->id)));
        $this->assertNotSame($drivers->for($exchange), $drivers->for(Exchange::factory()->create()));
    }

    public function test_the_driver_is_given_its_exchange(): void
    {
        $drivers = new ExchangeDrivers(['plain' => PlainDriver::class]);
        $exchange = Exchange::factory()->create(['driver' => 'plain']);

        $driver = $drivers->for($exchange);

        $this->assertInstanceOf(PlainDriver::class, $driver);
        $this->assertTrue($driver->exchange->is($exchange));
    }

    public function test_an_exchange_with_no_driver_is_refused(): void
    {
        $exchange = Exchange::factory()->create(['driver' => 'kraken']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("There is no exchange driver called 'kraken'.");

        $this->app->make(ExchangeDrivers::class)->for($exchange);
    }

    public function test_it_lists_the_drivers_by_the_name_of_their_exchange(): void
    {
        $this->assertSame(['btcmarkets' => 'BTC Markets'], $this->app->make(ExchangeDrivers::class)->names());
        $this->assertSame(
            ['plain' => 'A plain exchange', 'btcmarkets' => 'BTC Markets'],
            (new ExchangeDrivers(['btcmarkets' => BtcMarkets::class, 'plain' => PlainDriver::class]))->names(),
        );
    }

    public function test_it_says_whether_a_driver_exists(): void
    {
        $drivers = $this->app->make(ExchangeDrivers::class);

        $this->assertTrue($drivers->has('btcmarkets'));
        $this->assertFalse($drivers->has('kraken'));
        $this->assertFalse($drivers->has(''));
    }

    public function test_every_driver_is_an_exchange_driver(): void
    {
        foreach (ExchangeDrivers::DRIVERS as $name => $class) {
            $this->assertMatchesRegularExpression('/^[a-z0-9]+$/', $name);
            $this->assertTrue(is_subclass_of($class, ExchangeDriver::class), $class);
            $this->assertNotSame('', $class::label());
        }
    }

    public function test_the_container_gives_one_set_of_drivers_for_a_request(): void
    {
        $before = $this->app->make(ExchangeDrivers::class);

        $this->assertSame($before, $this->app->make(ExchangeDrivers::class));

        $this->app->forgetScopedInstances();
        $this->assertNotSame($before, $this->app->make(ExchangeDrivers::class));
    }
}
