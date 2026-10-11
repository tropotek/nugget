<?php

namespace Tests\Feature\Api;

use App\Enums\AssetClass;
use App\Facades\Settings;
use App\Ledger\Holdings;
use App\Ledger\Ledger;
use App\Ledger\ParcelMatcher;
use App\Models\Asset;
use App\Models\Exchange;
use App\Models\Price;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\BalanceMismatch;
use App\Support\Decimal;
use Database\Factories\TransactionFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The read-only endpoints, for a user in Melbourne. "Now" is midday there on
 * 1 August 2025, and the first year reported on is 2024–25.
 *
 * The ledger: 1 BTC bought in January 2023 and 1 in August 2024, the first
 * sold in October 2024 (a long-held gain of $3,000); 2 oz of gold bought in
 * 2022, one sold in March 2025 (a long-held gain of $900); and 3 g of gold
 * found in 2025 at no cost.
 */
class ApiEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Exchange $exchange;

    private Asset $bitcoin;

    private Asset $gold;

    private Asset $found;

    /** @var array<string, string> */
    private array $bearer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2025-08-01 02:00:00');
        Settings::set('first_report_year', 2025);

        $this->user = User::factory()->create(['timezone' => 'Australia/Melbourne']);
        $this->exchange = Exchange::factory()->for($this->user)->synced('1520.45')
            ->create(['label' => 'Main account', 'api_key' => 'EXCHANGE-KEY-ABCDEF', 'secret' => 'EXCHANGE-SECRET-UVWXYZ']);
        $this->bitcoin = Asset::factory()->onExchange($this->exchange, 'BTC-AUD')
            ->create(['name' => 'Bitcoin', 'symbol' => 'BTC', 'unit' => 'BTC', 'class' => AssetClass::Crypto]);
        $this->gold = Asset::factory()->for($this->user)->create(['name' => 'Gold', 'symbol' => 'XAU', 'unit' => 'oz', 'class' => AssetClass::Metal]);
        $this->found = Asset::factory()->for($this->user)->create(['name' => 'Prospected gold', 'symbol' => 'XAU', 'unit' => 'g', 'class' => AssetClass::Metal]);

        $this->add($this->bitcoin, '2023-01-10', Transaction::factory()->buy('1', '1000'));
        $this->add($this->bitcoin, '2024-08-01', Transaction::factory()->buy('1', '5000', '25')->estimated());
        $this->add($this->bitcoin, '2024-10-01', Transaction::factory()->sell('1', '4000')->fromExchange('trade-77'));
        $this->add($this->gold, '2022-05-05', Transaction::factory()->buy('2', '2500'));
        $this->add($this->gold, '2025-03-03', Transaction::factory()->sell('1', '3400'));
        $this->add($this->found, '2025-04-12', Transaction::factory()->found('3'));

        Price::factory()->for($this->bitcoin)->day('2025-08-01')->quoted('6000', '6010')->create(['updated_at' => '2025-08-01 01:55:00']);
        Price::factory()->for($this->gold)->day('2025-08-01')->quoted('3500', '3510')->create();

        $this->bearer = ['Authorization' => 'Bearer '.$this->user->createToken('Claude', ['read'])->plainTextToken, 'Accept' => 'application/json'];
    }

    private function add(Asset $asset, string $day, TransactionFactory $transaction): Transaction
    {
        $added = $transaction->for($asset)->create(['occurred_at' => "{$day} 02:00:00"]);
        app(ParcelMatcher::class)->rematch($asset);

        return $added;
    }

    private function api(string $path): TestResponse
    {
        return $this->getJson("/api/v1/{$path}", $this->bearer);
    }

    /**
     * Another user with a ledger, an exchange and a mismatch of their own.
     */
    private function somebodyElse(): Asset
    {
        $exchange = Exchange::factory()->create(['label' => 'Their account', 'api_key' => 'THEIR-KEY', 'secret' => 'THEIR-SECRET']);
        $asset = Asset::factory()->onExchange($exchange, 'BTC-AUD')->create(['name' => 'Their coin', 'class' => AssetClass::Crypto]);
        $this->add($asset, '2024-08-01', Transaction::factory()->buy('5', '100'));
        $this->add($asset, '2024-11-01', Transaction::factory()->sell('1', '99999'));
        $exchange->user->notify(new BalanceMismatch($exchange, app(Holdings::class)->forAsset($asset), Decimal::of('9')));

        return $asset;
    }

    /** The path of every endpoint, for the checks that go across them all. */
    private function everyPath(): array
    {
        return ['me', 'holdings', "assets/{$this->bitcoin->id}/parcels", 'transactions', 'tax/years', 'tax/2025', 'issues'];
    }

    // Holdings

    public function test_holdings_gives_each_holding_with_its_value_and_the_totals(): void
    {
        $this->api('holdings')
            ->assertOk()
            ->assertJsonPath('data.currency', 'AUD')
            ->assertJsonCount(3, 'data.holdings')
            ->assertJsonPath('data.holdings.0', [
                'asset' => ['id' => $this->bitcoin->id, 'name' => 'Bitcoin', 'symbol' => 'BTC', 'class' => 'crypto', 'unit' => 'BTC', 'in_total' => true, 'on_dashboard' => true, 'archived' => false],
                'units' => '1',
                'cost_base' => '5025.00',
                'average_cost' => '5025',
                'cost_is_estimated' => true,
                'open_parcels' => 1,
                'price' => ['bid' => '6000', 'ask' => '6010', 'as_at' => '2025-08-01T11:55:00+10:00'],
                'value' => '6000.00',
                'unrealised_gain' => '975.00',
                'unrealised_gain_percent' => '19.4',
            ])
            ->assertJsonPath('data.holdings.1.asset.name', 'Gold')
            ->assertJsonPath('data.holdings.1.value', '3500.00')
            // No price: no value and no gain.
            ->assertJsonPath('data.holdings.2.asset.name', 'Prospected gold')
            ->assertJsonPath('data.holdings.2.units', '3')
            ->assertJsonPath('data.holdings.2.price', null)
            ->assertJsonPath('data.holdings.2.value', null)
            ->assertJsonPath('data.holdings.2.unrealised_gain', null)
            // $6,000 and $3,500 against $5,025 and $2,500.
            ->assertJsonPath('data.totals', [
                'value' => '9500.00',
                'cost_base' => '7525.00',
                'unrealised_gain' => '1975.00',
                'unrealised_gain_percent' => '26.25',
                'holdings_without_a_price' => 1,
                'aud_on_exchanges' => '1520.45',
            ]);
    }

    public function test_holdings_leaves_out_an_asset_with_nothing_held(): void
    {
        Asset::factory()->for($this->user)->create(['name' => 'Empty coin']);

        $this->api('holdings')->assertJsonCount(3, 'data.holdings')->assertDontSee('Empty coin');
    }

    // Parcels

    public function test_parcels_gives_the_open_parcels_of_an_asset_oldest_first(): void
    {
        $bought = $this->gold->transactions()->oldest('occurred_at')->first();

        $this->api("assets/{$this->gold->id}/parcels")
            ->assertOk()
            ->assertExactJson(['data' => [
                'asset' => ['id' => $this->gold->id, 'name' => 'Gold', 'symbol' => 'XAU', 'class' => 'metal', 'unit' => 'oz'],
                'parcels' => [[
                    'acquired_at' => '2022-05-05T12:00:00+10:00',
                    'units_acquired' => '2',
                    'units_remaining' => '1',
                    // Half of the $5,000 parcel is left.
                    'cost_base' => '2500.00',
                    'cost_per_unit' => '2500',
                    'estimated' => false,
                    'held_over_12_months' => true,
                    'transaction_id' => $bought->id,
                ]],
            ]]);
    }

    public function test_parcels_says_which_are_estimated_and_leaves_out_one_used_up(): void
    {
        $this->api("assets/{$this->bitcoin->id}/parcels")
            ->assertJsonCount(1, 'data.parcels')
            ->assertJsonPath('data.parcels.0.estimated', true)
            ->assertJsonPath('data.parcels.0.held_over_12_months', false)
            ->assertJsonPath('data.parcels.0.cost_base', '5025.00');
    }

    public function test_the_parcels_of_another_users_asset_or_of_none_are_not_found(): void
    {
        $theirs = $this->somebodyElse();

        $this->api("assets/{$theirs->id}/parcels")->assertNotFound();
        $this->api('assets/999999/parcels')->assertNotFound();
        $this->api('assets/abc/parcels')->assertNotFound();
    }

    // Transactions

    public function test_transactions_gives_the_ledger_newest_first(): void
    {
        $sale = $this->bitcoin->transactions()->where('external_id', 'trade-77')->sole();

        $this->api('transactions')
            ->assertOk()
            ->assertJsonCount(6, 'data.transactions')
            ->assertJsonPath('data.total', 6)
            ->assertJsonPath('data.page', 1)
            ->assertJsonPath('data.per_page', 50)
            ->assertJsonPath('data.pages', 1)
            ->assertJsonPath('data.transactions.0.asset.name', 'Prospected gold')
            ->assertJsonPath('data.transactions.0.type', 'found')
            ->assertJsonPath('data.transactions.2', [
                'id' => $sale->id,
                'asset' => ['id' => $this->bitcoin->id, 'name' => 'Bitcoin', 'symbol' => 'BTC', 'class' => 'crypto', 'unit' => 'BTC'],
                'type' => 'sell',
                'occurred_at' => '2024-10-01T12:00:00+10:00',
                'units' => '1',
                'units_change' => '-1',
                'unit_price' => '4000',
                'fee' => '0.00',
                'total' => '4000.00',
                'source' => 'exchange',
                'external_id' => 'trade-77',
                'estimated' => false,
                'notes' => null,
            ]);
    }

    public function test_transactions_can_be_filtered_by_asset_type_source_dates_and_estimated(): void
    {
        $names = fn (string $query) => $this->api("transactions?{$query}")->assertOk()->json('data.transactions.*.occurred_at');

        $this->assertCount(3, $names("asset={$this->bitcoin->id}"));
        $this->assertSame(['2025-03-03T13:00:00+11:00', '2024-10-01T12:00:00+10:00'], $names('type=sell'));
        $this->assertSame(['2024-10-01T12:00:00+10:00'], $names('source=exchange'));
        $this->assertSame(['2024-08-01T12:00:00+10:00'], $names('estimated=1'));
        $this->assertCount(5, $names('estimated=0'));
        // Both days are included, by the user's calendar.
        $this->assertSame(['2024-10-01T12:00:00+10:00', '2024-08-01T12:00:00+10:00'], $names('from=2024-08-01&to=2024-10-01'));
        $this->assertSame(['2022-05-05T12:00:00+10:00'], $names('sort=date&dir=asc&to=2022-12-31'));

        $this->api("transactions?asset={$this->bitcoin->id}&type=buy")
            ->assertJsonPath('data.filters', ['asset' => $this->bitcoin->id, 'type' => 'buy'])
            ->assertJsonPath('data.total', 2);
    }

    public function test_a_filter_that_is_not_understood_is_passed_over(): void
    {
        $theirs = $this->somebodyElse();

        $this->api("transactions?asset={$theirs->id}&type=nonsense&from=yesterday&estimated=maybe&sort[]=x")
            ->assertOk()
            ->assertJsonPath('data.total', 6)
            ->assertJsonPath('data.filters', []);
    }

    public function test_transactions_are_paged(): void
    {
        $this->api('transactions?per_page=4')->assertJsonCount(4, 'data.transactions')->assertJsonPath('data.pages', 2)->assertJsonPath('data.total', 6);
        $this->api('transactions?per_page=4&page=2')->assertJsonCount(2, 'data.transactions')->assertJsonPath('data.page', 2);
        $this->api('transactions?per_page=4&page=9')->assertJsonCount(0, 'data.transactions');
        $this->api('transactions?per_page=100000')->assertJsonPath('data.per_page', 200);
        $this->api('transactions?per_page=-3&page=abc')->assertJsonPath('data.per_page', 50)->assertJsonPath('data.page', 1);
    }

    // Tax

    public function test_tax_years_lists_the_years_with_disposals_open_or_closed(): void
    {
        $this->add($this->bitcoin, '2024-03-04', Transaction::factory()->buy('1', '900'));
        $this->add($this->found, '2024-03-10', Transaction::factory()->found('1'));
        $this->add($this->found, '2024-03-11', Transaction::factory()->sell('1', '100'));

        $this->api('tax/years')
            ->assertOk()
            ->assertExactJson(['data' => [
                'first_report_year' => 2025,
                'years' => [
                    ['year' => 2025, 'label' => '2024–25', 'starts_at' => '2024-07-01T00:00:00+10:00', 'ends_before' => '2025-07-01T00:00:00+10:00', 'open' => true, 'disposals' => 2],
                    ['year' => 2024, 'label' => '2023–24', 'starts_at' => '2023-07-01T00:00:00+10:00', 'ends_before' => '2024-07-01T00:00:00+10:00', 'open' => false, 'disposals' => 1],
                ],
            ]]);
    }

    public function test_the_tax_report_is_of_crypto_only_until_the_user_chooses(): void
    {
        $sale = $this->bitcoin->transactions()->where('external_id', 'trade-77')->sole();

        $this->api('tax/2025')
            ->assertOk()
            ->assertJsonPath('data.year', 2025)
            ->assertJsonPath('data.label', '2024–25')
            ->assertJsonPath('data.currency', 'AUD')
            ->assertJsonPath('data.asset_classes', ['crypto'])
            ->assertJsonPath('data.asset_classes_left_out', ['metal', 'other'])
            ->assertJsonPath('data.estimated_disposals', 0)
            ->assertJsonPath('data.warning', null)
            // A long-held gain of $3,000, halved.
            ->assertJsonPath('data.summary', [
                'short_held_gains' => '0.00',
                'long_held_gains' => '3000.00',
                'total_gains' => '3000.00',
                'losses' => '0.00',
                'losses_carried_forward_in' => '0.00',
                'losses_applied' => '0.00',
                'short_held_gains_after_losses' => '0.00',
                'long_held_gains_after_losses' => '3000.00',
                'discount' => '1500.00',
                'net_capital_gain' => '1500.00',
                'losses_carried_forward_out' => '0.00',
            ])
            ->assertJsonPath('data.sections.0.section', 'crypto')
            ->assertJsonPath('data.sections.0.included', true)
            ->assertJsonPath('data.sections.0.long_held_gains', '3000.00')
            ->assertJsonPath('data.sections.0.disposals', [[
                'asset' => ['id' => $this->bitcoin->id, 'name' => 'Bitcoin', 'symbol' => 'BTC', 'class' => 'crypto', 'unit' => 'BTC'],
                'acquired_at' => '2023-01-10T13:00:00+11:00',
                'sold_at' => '2024-10-01T12:00:00+10:00',
                'units' => '1',
                'cost_base' => '1000.00',
                'proceeds' => '4000.00',
                'gain' => '3000.00',
                'held_over_12_months' => true,
                'discount_applies' => true,
                'estimated' => false,
                'sale_transaction_id' => $sale->id,
            ]])
            ->assertJsonPath('data.sections.1.section', 'other')
            ->assertJsonPath('data.sections.1.included', false)
            ->assertJsonPath('data.sections.1.disposals', []);

        $this->assertStringContainsString('not tax advice', $this->api('tax/2025')->json('data.note'));
    }

    public function test_the_tax_report_is_the_one_the_user_saved_for_the_year(): void
    {
        $this->user->taxYears()->create(['year' => 2025, 'losses_carried_forward' => '400', 'asset_classes' => ['crypto', 'metal']]);

        // $3,900 of long-held gains less $400, halved.
        $this->api('tax/2025')
            ->assertJsonPath('data.asset_classes', ['crypto', 'metal'])
            ->assertJsonPath('data.asset_classes_left_out', ['other'])
            ->assertJsonPath('data.summary.losses_carried_forward_in', '400.00')
            ->assertJsonPath('data.summary.net_capital_gain', '1750.00')
            ->assertJsonPath('data.sections.1.included', true)
            ->assertJsonPath('data.sections.1.disposals.0.asset.name', 'Gold')
            ->assertJsonPath('data.sections.1.disposals.0.gain', '900.00');
    }

    public function test_the_tax_report_counts_and_warns_of_estimated_disposals(): void
    {
        $this->add($this->bitcoin, '2025-02-01', Transaction::factory()->sell('1', '7000'));

        $this->api('tax/2025')
            ->assertJsonPath('data.estimated_disposals', 1)
            ->assertJsonPath('data.warning', '1 of the disposals used a parcel with an estimated cost, so these figures are not final.')
            ->assertJsonPath('data.sections.0.estimated_disposals', 1)
            ->assertJsonPath('data.sections.0.disposals.1.estimated', true)
            ->assertJsonPath('data.sections.0.disposals.1.discount_applies', false);
    }

    public function test_a_closed_year_or_one_that_could_not_be_has_no_report(): void
    {
        $this->api('tax/2024')->assertNotFound()->assertJsonPath('message', 'There is no tax report for 2024: the year is closed, or is not one Nugget reports on.');
        $this->api('tax/2028')->assertNotFound();
        $this->api('tax/abc')->assertNotFound();

        // An open year with nothing sold has a report of zeros.
        $this->api('tax/2026')->assertOk()->assertJsonPath('data.summary.net_capital_gain', '0.00');
    }

    // Issues

    public function test_issues_lists_the_balance_mismatches_and_the_parcels_with_no_cost(): void
    {
        $this->user->notify(new BalanceMismatch($this->exchange, app(Holdings::class)->forAsset($this->bitcoin), Decimal::of('1.25')));
        $found = $this->found->transactions()->sole();
        // A deposit of coins bought elsewhere: no cost is known yet.
        $deposit = $this->add($this->bitcoin, '2025-05-01', Transaction::factory()->transferIn('0.5')->estimated());

        $this->api('issues')
            ->assertOk()
            ->assertExactJson(['data' => [
                'balance_mismatches' => [[
                    'exchange' => ['id' => $this->exchange->id, 'label' => 'Main account'],
                    'asset' => ['id' => $this->bitcoin->id, 'name' => 'Bitcoin', 'unit' => 'BTC'],
                    'units_in_ledger' => '1',
                    'units_on_exchange' => '1.25',
                    'difference' => '0.25',
                    'found_at' => '2025-08-01T12:00:00+10:00',
                ]],
                'parcels_without_cost' => [
                    [
                        'asset' => ['id' => $this->found->id, 'name' => 'Prospected gold', 'symbol' => 'XAU', 'class' => 'metal', 'unit' => 'g'],
                        'acquired_at' => '2025-04-12T12:00:00+10:00',
                        'units_remaining' => '3',
                        'acquired_by' => 'found',
                        'estimated' => false,
                        'transaction_id' => $found->id,
                    ],
                    [
                        'asset' => ['id' => $this->bitcoin->id, 'name' => 'Bitcoin', 'symbol' => 'BTC', 'class' => 'crypto', 'unit' => 'BTC'],
                        'acquired_at' => '2025-05-01T12:00:00+10:00',
                        'units_remaining' => '0.5',
                        'acquired_by' => 'transfer_in',
                        'estimated' => true,
                        'transaction_id' => $deposit->id,
                    ],
                ],
            ]]);
    }

    public function test_with_nothing_to_sort_out_the_issues_are_empty(): void
    {
        app(Ledger::class)->remove($this->found->transactions()->sole());

        $this->api('issues')->assertExactJson(['data' => ['balance_mismatches' => [], 'parcels_without_cost' => []]]);
    }

    // Across every endpoint

    public function test_every_endpoint_needs_a_token(): void
    {
        foreach ($this->everyPath() as $path) {
            $this->getJson("/api/v1/{$path}")->assertUnauthorized();
        }
    }

    public function test_no_endpoint_gives_an_exchange_key_or_secret(): void
    {
        $this->somebodyElse();
        $this->user->notify(new BalanceMismatch($this->exchange, app(Holdings::class)->forAsset($this->bitcoin), Decimal::of('1.25')));

        foreach ($this->everyPath() as $path) {
            $body = $this->api($path)->assertOk()->getContent();

            foreach (['EXCHANGE-KEY-ABCDEF', 'EXCHANGE-SECRET-UVWXYZ', 'THEIR-KEY', 'THEIR-SECRET', 'api_key', 'secret', 'password', 'remember_token'] as $never) {
                $this->assertStringNotContainsString($never, $body, "{$path} gives away {$never}");
            }
        }
    }

    public function test_no_endpoint_gives_another_users_data(): void
    {
        $this->somebodyElse();

        foreach ($this->everyPath() as $path) {
            $body = $this->api($path)->assertOk()->getContent();

            $this->assertStringNotContainsString('Their coin', $body, $path);
            $this->assertStringNotContainsString('Their account', $body, $path);
            $this->assertStringNotContainsString('99999', $body, $path);
        }
    }

    public function test_every_amount_is_a_string_and_never_a_json_number_with_a_fraction(): void
    {
        $this->user->notify(new BalanceMismatch($this->exchange, app(Holdings::class)->forAsset($this->bitcoin), Decimal::of('1.25')));

        foreach ($this->everyPath() as $path) {
            $body = $this->api($path)->assertOk()->getContent();

            // A bare number after a colon or in a list that has a decimal point or an exponent.
            $this->assertDoesNotMatchRegularExpression('/[:\[,]\s*-?\d+(\.\d+|[eE][-+]?\d+)\s*[,\]}]/', $body, "{$path} has a float");
        }
    }

    public function test_every_date_is_iso_8601_with_the_users_offset(): void
    {
        foreach (['holdings', "assets/{$this->bitcoin->id}/parcels", 'transactions', 'tax/years', 'tax/2025', 'issues'] as $path) {
            $data = $this->api($path)->json('data');

            array_walk_recursive($data, function (mixed $value, string|int $key) use ($path) {
                if (is_string($key) && (str_ends_with($key, '_at') || $key === 'ends_before') && $value !== null) {
                    $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+1[01]:00$/', $value, "{$path} {$key}");
                }
            });
        }
    }
}
