<?php

namespace Tests\Feature;

use App\Exchanges\ExchangeClient;
use App\Models\Exchange;
use ccxt\btcmarkets;
use ccxt\kraken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Fakes\FakeCcxt;
use Tests\Fakes\FakeExchangeClient;
use Tests\TestCase;

/**
 * Building a ccxt client makes no request, so these touch no network.
 */
class ExchangeClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_the_ccxt_client_of_the_exchanges_driver_with_its_key(): void
    {
        $exchange = Exchange::factory()->create(['driver' => 'btcmarkets', 'api_key' => 'my-key', 'secret' => 'my-secret']);

        $ccxt = $this->app->make(ExchangeClient::class)->for($exchange);

        $this->assertInstanceOf(btcmarkets::class, $ccxt);
        $this->assertSame('my-key', $ccxt->apiKey);
        $this->assertSame('my-secret', $ccxt->secret);
        $this->assertTrue($ccxt->enableRateLimit);
        $this->assertSame(10000, $ccxt->timeout);

        $this->assertInstanceOf(kraken::class, $this->app->make(ExchangeClient::class)->for(Exchange::factory()->create(['driver' => 'kraken'])));
    }

    public function test_an_exchange_keeps_one_client_so_its_rate_limiter_sees_every_call(): void
    {
        $client = $this->app->make(ExchangeClient::class);
        $exchange = Exchange::factory()->create();

        $this->assertSame($client->for($exchange), $client->for($exchange));
        $this->assertSame($client->for($exchange), $client->for(Exchange::find($exchange->id)));
        $this->assertNotSame($client->for($exchange), $client->for(Exchange::factory()->create()));
    }

    public function test_the_container_gives_one_client_for_a_request(): void
    {
        $this->assertSame($this->app->make(ExchangeClient::class), $this->app->make(ExchangeClient::class));

        $before = $this->app->make(ExchangeClient::class);
        $this->app->forgetScopedInstances();
        $this->assertNotSame($before, $this->app->make(ExchangeClient::class));
    }

    public function test_an_unknown_driver_is_refused(): void
    {
        $exchange = Exchange::factory()->create(['driver' => 'Exchange']);

        $this->expectException(InvalidArgumentException::class);

        $this->app->make(ExchangeClient::class)->for($exchange);
    }

    public function test_it_says_which_calls_an_exchange_supports(): void
    {
        $client = $this->app->make(ExchangeClient::class);
        $exchange = Exchange::factory()->create(['driver' => 'btcmarkets']);

        $this->assertTrue($client->supports($exchange, 'fetchTicker'));
        $this->assertFalse($client->supports($exchange, 'fetchFundingRate'));
        $this->assertFalse($client->supports($exchange, 'noSuchCall'));
    }

    public function test_a_fake_client_answers_from_its_own_map_of_calls(): void
    {
        $client = new FakeExchangeClient(new FakeCcxt(['fetchTicker' => false, 'fetchMyTrades' => 'emulated']));
        $exchange = Exchange::factory()->create();

        $this->assertFalse($client->supports($exchange, 'fetchTicker'));
        $this->assertTrue($client->supports($exchange, 'fetchMyTrades'));
        $this->assertFalse($client->supports($exchange, 'fetchBalance'));
    }
}
