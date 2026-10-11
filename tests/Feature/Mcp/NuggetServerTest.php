<?php

namespace Tests\Feature\Mcp;

use App\Enums\AssetClass;
use App\Facades\Settings;
use App\Ledger\Holdings;
use App\Ledger\ParcelMatcher;
use App\Mcp\NuggetServer;
use App\Mcp\Tools\HoldingsTool;
use App\Mcp\Tools\IssuesTool;
use App\Mcp\Tools\ParcelsTool;
use App\Mcp\Tools\TaxReportTool;
use App\Mcp\Tools\TaxYearsTool;
use App\Mcp\Tools\TransactionsTool;
use App\Models\Asset;
use App\Models\Exchange;
use App\Models\Price;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\BalanceMismatch;
use App\Support\Decimal;
use Database\Factories\TransactionFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\Fluent\AssertableJson;
use Illuminate\Testing\TestResponse as HttpResponse;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

/**
 * The MCP server's tools, for a user in Melbourne. "Now" is midday there on
 * 1 August 2025, and the first year reported on is 2024–25. The ledger is
 * the one the API's endpoint tests use, and each tool is held to its endpoint.
 */
class NuggetServerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Exchange $exchange;

    private Asset $bitcoin;

    private Asset $gold;

    private string $token;

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

        $this->add($this->bitcoin, '2023-01-10', Transaction::factory()->buy('1', '1000'));
        $this->add($this->bitcoin, '2024-08-01', Transaction::factory()->buy('1', '5000', '25')->estimated());
        $this->add($this->bitcoin, '2024-10-01', Transaction::factory()->sell('1', '4000'));
        $this->add($this->gold, '2022-05-05', Transaction::factory()->buy('2', '2500'));
        $this->add($this->gold, '2025-03-03', Transaction::factory()->sell('1', '3400'));

        Price::factory()->for($this->bitcoin)->day('2025-08-01')->quoted('6000', '6010')->create();

        $this->token = $this->user->createToken('Claude', ['read'])->plainTextToken;
    }

    private function add(Asset $asset, string $day, TransactionFactory $transaction): Transaction
    {
        $added = $transaction->for($asset)->create(['occurred_at' => "{$day} 02:00:00"]);
        app(ParcelMatcher::class)->rematch($asset);

        return $added;
    }

    /**
     * Call a tool as the user.
     *
     * @param  class-string  $tool
     * @param  array<string, mixed>  $arguments
     */
    private function tool(string $tool, array $arguments = [], ?User $user = null): TestResponse
    {
        return NuggetServer::actingAs($user ?? $this->user)->tool($tool, $arguments);
    }

    /**
     * What the endpoint of the same name returns.
     *
     * @return array<string, mixed>
     */
    private function endpoint(string $path): array
    {
        // A fresh request: the tool call before it left its user on the guards.
        auth()->forgetGuards();
        $this->app['auth']->shouldUse('web');

        return $this->getJson("/api/v1/{$path}", ['Authorization' => "Bearer {$this->token}"])->assertOk()->json('data');
    }

    /**
     * A JSON-RPC message posted to /mcp, as a client sends it.
     *
     * @param  array<string, mixed>  $params
     */
    private function rpc(string $method, array $params = [], ?string $token = null): HttpResponse
    {
        return $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params], array_filter([
            'Authorization' => $token === null ? null : "Bearer {$token}",
            'Accept' => 'application/json, text/event-stream',
        ]));
    }

    // The server

    public function test_the_server_offers_the_six_read_only_tools(): void
    {
        $tools = collect($this->rpc('tools/list', token: $this->token)->assertOk()->json('result.tools'));

        $this->assertSame(['holdings', 'parcels', 'transactions', 'tax_years', 'tax_report', 'issues'], $tools->pluck('name')->all());

        foreach ($tools as $tool) {
            $this->assertTrue($tool['annotations']['readOnlyHint'], "{$tool['name']} is not marked read-only");
            $this->assertGreaterThan(80, strlen($tool['description']), "{$tool['name']} does not describe itself");
        }
    }

    public function test_each_tool_describes_its_arguments_and_units(): void
    {
        $tools = collect($this->rpc('tools/list', token: $this->token)->json('result.tools'))->keyBy('name');

        $this->assertSame(['asset_id'], $tools['parcels']['inputSchema']['required']);
        $this->assertSame(['year'], $tools['tax_report']['inputSchema']['required']);
        $this->assertStringContainsString('2025 for 2024–25', $tools['tax_report']['inputSchema']['properties']['year']['description']);
        $this->assertSame(
            ['asset_id', 'type', 'source', 'from', 'to', 'estimated', 'page', 'per_page'],
            array_keys($tools['transactions']['inputSchema']['properties']),
        );
        $this->assertContains('transfer_in', $tools['transactions']['inputSchema']['properties']['type']['enum']);
        $this->assertStringContainsString('AUD', $tools['holdings']['description']);
        $this->assertStringContainsString('not tax advice', $tools['tax_report']['description']);
    }

    public function test_the_server_says_how_to_read_its_figures(): void
    {
        $instructions = $this->rpc('initialize', [
            'protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'test', 'version' => '1'],
        ], $this->token)->assertOk()->json('result');

        $this->assertSame('Nugget', $instructions['serverInfo']['name']);
        $this->assertStringContainsString('Amounts are decimal strings', $instructions['instructions']);
        $this->assertStringContainsString('2025 is 2024–25', $instructions['instructions']);
    }

    // Who may call it

    public function test_the_server_takes_the_same_token_as_the_api(): void
    {
        $this->rpc('tools/call', ['name' => 'holdings', 'arguments' => (object) []], $this->token)
            ->assertOk()
            ->assertJsonPath('result.structuredContent.totals.aud_on_exchanges', '1520.45')
            ->assertJsonPath('result.isError', false);

        $this->assertNotNull($this->user->tokens()->sole()->last_used_at);
    }

    public function test_the_server_refuses_a_request_with_no_token_a_bad_one_or_a_revoked_one(): void
    {
        $this->rpc('tools/list')->assertUnauthorized()->assertHeader('WWW-Authenticate');
        $this->rpc('tools/list', token: 'nonsense')->assertUnauthorized();

        $this->user->tokens()->delete();

        $this->rpc('tools/list', token: $this->token)->assertUnauthorized();
    }

    public function test_the_server_refuses_a_token_without_read_and_one_of_a_deactivated_user(): void
    {
        $this->rpc('tools/list', token: $this->user->createToken('No read', ['write'])->plainTextToken)->assertForbidden();

        $this->user->forceFill(['active' => false])->save();
        auth()->forgetGuards();

        $this->rpc('tools/list', token: $this->token)->assertUnauthorized();
    }

    public function test_a_signed_in_browser_session_is_not_a_token(): void
    {
        $this->actingAs($this->user)->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertUnauthorized();
    }

    // The tools

    public function test_holdings_returns_what_its_endpoint_does(): void
    {
        $this->tool(HoldingsTool::class)
            ->assertOk()
            ->assertHasNoErrors()
            ->assertStructuredContent($this->endpoint('holdings'))
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('holdings.0.asset.name', 'Bitcoin')
                ->where('holdings.0.value', '6000.00')
                ->where('totals.value', '6000.00')
                ->etc());
    }

    public function test_parcels_returns_what_its_endpoint_does(): void
    {
        $this->tool(ParcelsTool::class, ['asset_id' => $this->gold->id])
            ->assertHasNoErrors()
            ->assertStructuredContent($this->endpoint("assets/{$this->gold->id}/parcels"))
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('parcels.0.units_remaining', '1')->where('parcels.0.cost_base', '2500.00')->etc());
    }

    public function test_parcels_needs_an_asset_of_the_users_own(): void
    {
        $theirs = Asset::factory()->create();

        $this->tool(ParcelsTool::class)->assertHasErrors();
        $this->tool(ParcelsTool::class, ['asset_id' => $theirs->id])->assertHasErrors(["There is no asset with the id {$theirs->id}. Call holdings for the ids."]);
        $this->tool(ParcelsTool::class, ['asset_id' => 'bitcoin'])->assertHasErrors();
    }

    public function test_transactions_returns_what_its_endpoint_does(): void
    {
        $this->tool(TransactionsTool::class)
            ->assertHasNoErrors()
            ->assertStructuredContent($this->endpoint('transactions'))
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('total', 5)->where('per_page', 50)->etc());
    }

    public function test_transactions_takes_the_endpoints_filters_as_arguments(): void
    {
        $this->tool(TransactionsTool::class, ['asset_id' => $this->bitcoin->id, 'type' => 'buy', 'estimated' => true])
            ->assertStructuredContent($this->endpoint("transactions?asset={$this->bitcoin->id}&type=buy&estimated=1"))
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('total', 1)->where('transactions.0.estimated', true)->etc());

        $this->tool(TransactionsTool::class, ['estimated' => false, 'from' => '2024-08-01', 'to' => '2024-10-01', 'source' => 'manual'])
            ->assertStructuredContent($this->endpoint('transactions?estimated=0&from=2024-08-01&to=2024-10-01&source=manual'))
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('total', 1)->where('transactions.0.type', 'sell')->etc());

        $this->tool(TransactionsTool::class, ['per_page' => 2, 'page' => 3])
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('page', 3)->where('pages', 3)->has('transactions', 1)->etc());
    }

    public function test_transactions_refuses_an_argument_it_cannot_use(): void
    {
        $this->tool(TransactionsTool::class, ['type' => 'gift'])->assertHasErrors();
        $this->tool(TransactionsTool::class, ['from' => 'last week'])->assertHasErrors();
        $this->tool(TransactionsTool::class, ['per_page' => 5000])->assertHasErrors();
    }

    public function test_tax_years_returns_what_its_endpoint_does(): void
    {
        $this->tool(TaxYearsTool::class)
            ->assertHasNoErrors()
            ->assertStructuredContent($this->endpoint('tax/years'))
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('years.0.year', 2026)->where('years.0.disposals', 0)->where('years.1.year', 2025)->where('years.1.open', true)->etc());
    }

    public function test_tax_report_returns_what_its_endpoint_does(): void
    {
        $this->user->taxYears()->create(['year' => 2025, 'losses_carried_forward' => '400', 'asset_classes' => ['crypto', 'metal']]);

        $this->tool(TaxReportTool::class, ['year' => 2025])
            ->assertHasNoErrors()
            ->assertStructuredContent($this->endpoint('tax/2025'))
            // $3,900 of long-held gains less $400, halved.
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('summary.net_capital_gain', '1750.00')
                ->where('asset_classes', ['crypto', 'metal'])
                ->where('warning', null)
                ->etc())
            ->assertSee('not tax advice');
    }

    public function test_tax_report_says_when_estimated_rows_exist(): void
    {
        $this->add($this->bitcoin, '2025-02-01', Transaction::factory()->sell('1', '7000'));

        $this->tool(TaxReportTool::class, ['year' => 2025])
            ->assertSee('1 of the disposals used a parcel with an estimated cost, so these figures are not final.')
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('estimated_disposals', 1)->etc());

        $this->tool(TaxReportTool::class, ['year' => 2026])->assertDontSee('estimated cost, so these figures');
    }

    public function test_tax_report_needs_an_open_year(): void
    {
        $this->tool(TaxReportTool::class)->assertHasErrors();
        $this->tool(TaxReportTool::class, ['year' => 2024])
            ->assertHasErrors(['There is no tax report for 2024: the year is closed, or is not one Nugget reports on. Call tax_years for the open years.']);
        $this->tool(TaxReportTool::class, ['year' => 2031])->assertHasErrors();
    }

    public function test_issues_returns_what_its_endpoint_does(): void
    {
        $this->user->notify(new BalanceMismatch($this->exchange, app(Holdings::class)->forAsset($this->bitcoin), Decimal::of('1.25')));
        $this->add($this->bitcoin, '2025-05-01', Transaction::factory()->transferIn('0.5')->estimated());

        $this->tool(IssuesTool::class)
            ->assertHasNoErrors()
            ->assertStructuredContent($this->endpoint('issues'))
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('balance_mismatches.0.difference', '0.25')
                ->where('parcels_without_cost.0.acquired_by', 'transfer_in')
                ->etc());
    }

    // Across every tool

    public function test_no_tool_gives_another_users_data_or_an_exchange_key_or_secret(): void
    {
        $other = Exchange::factory()->create(['label' => 'Their account', 'api_key' => 'THEIR-KEY', 'secret' => 'THEIR-SECRET']);
        $theirs = Asset::factory()->onExchange($other, 'BTC-AUD')->create(['name' => 'Their coin', 'class' => AssetClass::Crypto]);
        $this->add($theirs, '2024-08-01', Transaction::factory()->buy('5', '100'));
        $this->add($theirs, '2024-11-01', Transaction::factory()->sell('1', '99999'));
        $other->user->notify(new BalanceMismatch($other, app(Holdings::class)->forAsset($theirs), Decimal::of('9')));

        $calls = [
            [HoldingsTool::class, []], [ParcelsTool::class, ['asset_id' => $this->bitcoin->id]], [TransactionsTool::class, []],
            [TaxYearsTool::class, []], [TaxReportTool::class, ['year' => 2025]], [IssuesTool::class, []],
        ];

        foreach ($calls as [$tool, $arguments]) {
            $this->tool($tool, $arguments)
                ->assertHasNoErrors()
                ->assertDontSee(['Their coin', 'Their account', '99999', 'THEIR-KEY', 'THEIR-SECRET', 'EXCHANGE-KEY-ABCDEF', 'EXCHANGE-SECRET-UVWXYZ', 'api_key', 'secret']);
        }

        // And the other user sees their own.
        $this->tool(HoldingsTool::class, user: $other->user)->assertSee('Their coin')->assertDontSee('Bitcoin');
    }

    public function test_the_tools_change_nothing(): void
    {
        $before = [Transaction::count(), $this->user->notifications()->count(), $this->user->taxYears()->count()];

        foreach ([[HoldingsTool::class, []], [ParcelsTool::class, ['asset_id' => $this->bitcoin->id]], [TransactionsTool::class, []],
            [TaxYearsTool::class, []], [TaxReportTool::class, ['year' => 2025]], [IssuesTool::class, []]] as [$tool, $arguments]) {
            $this->tool($tool, $arguments)->assertOk();
        }

        $this->assertSame($before, [Transaction::count(), $this->user->notifications()->count(), $this->user->taxYears()->count()]);
    }
}
