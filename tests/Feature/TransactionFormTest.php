<?php

namespace Tests\Feature;

use App\Enums\TransactionSource;
use App\Enums\TransactionType;
use App\Ledger\Ledger;
use App\Ledger\ParcelMatcher;
use App\Models\Asset;
use App\Models\Exchange;
use App\Models\Transaction;
use App\Models\User;
use Database\Factories\TransactionFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Adding and editing a transaction by hand. The user is in Melbourne, so
 * 1pm on a January day is 02:00 UTC.
 */
class TransactionFormTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['timezone' => 'Australia/Melbourne']);
        $this->asset = Asset::factory()->for($this->user)->create(['name' => 'Bitcoin', 'symbol' => 'BTC', 'unit' => 'BTC']);
        $this->actingAs($this->user);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function form(array $changes = []): array
    {
        return $changes + [
            'asset_id' => $this->asset->id,
            'type' => 'buy',
            'occurred_at' => '2024-01-10T13:00:00',
            'units' => '0.5',
            'unit_price' => '100000',
            'fee' => '10',
            'total' => '',
            'estimated' => '0',
            'notes' => '',
        ];
    }

    private function add(string $day, TransactionFactory $transaction, ?Asset $asset = null): Transaction
    {
        $transaction = $transaction->for($asset ?? $this->asset)->create(['occurred_at' => "{$day} 02:00:00"]);
        app(ParcelMatcher::class)->rematch($transaction->asset);

        return $transaction;
    }

    public function test_a_guest_is_sent_to_the_login_screen(): void
    {
        auth()->logout();

        $this->get('/transactions/create')->assertRedirect(route('login'));
        $this->post('/transactions', $this->form())->assertRedirect(route('login'));
    }

    public function test_the_asset_list_links_to_the_add_form(): void
    {
        $this->get('/assets')->assertSee('href="'.route('transactions.create', ['asset' => $this->asset->id]).'"', false);
    }

    // Adding

    public function test_the_add_form_offers_the_users_assets_that_are_not_archived(): void
    {
        Asset::factory()->for($this->user)->create(['name' => 'Gold bullion']);
        Asset::factory()->for($this->user)->archived()->create(['name' => 'Litecoin']);
        Asset::factory()->create(['name' => 'Somebody elses']);

        $this->get('/transactions/create')
            ->assertOk()
            ->assertSee('<h4 class="page-title">Add transaction</h4>', false)
            ->assertSeeInOrder(['Bitcoin (BTC)', 'Gold bullion'])
            ->assertDontSee('Litecoin')
            ->assertDontSee('Somebody elses')
            ->assertDontSee('Delete transaction');
    }

    public function test_the_add_form_starts_on_the_asset_asked_for(): void
    {
        $archived = Asset::factory()->for($this->user)->archived()->create(['name' => 'Litecoin']);
        $theirs = Asset::factory()->create(['name' => 'Somebody elses']);

        $this->get("/transactions/create?asset={$this->asset->id}")
            ->assertSee('<option value="'.$this->asset->id.'" selected>', false);
        $this->get("/transactions/create?asset={$archived->id}")
            ->assertSee('<option value="'.$archived->id.'" selected>', false);
        $this->get("/transactions/create?asset={$theirs->id}")
            ->assertOk()->assertDontSee('Somebody elses')->assertDontSee(' selected>Bitcoin', false);
    }

    public function test_the_form_marks_the_fields_each_type_uses(): void
    {
        $this->get('/transactions/create')
            ->assertSee('data-for-types="buy sell reward"', false)
            ->assertSee('data-for-types="buy sell"', false)
            ->assertSee('data-for-types="buy sell found reward transfer_in adjust"', false);
    }

    public function test_a_buy_is_recorded_with_its_total_worked_out(): void
    {
        $this->post('/transactions', $this->form(['notes' => 'First one.']))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('transactions.index'))
            ->assertSessionHas('success', 'Bitcoin: Buy of 0.5 BTC recorded.');

        $buy = $this->asset->transactions()->sole();
        $this->assertSame(TransactionType::Buy, $buy->type);
        $this->assertSame('2024-01-10 02:00:00', $buy->occurred_at->toDateTimeString());
        $this->assertSame('0.5', (string) $buy->units);
        $this->assertSame('100000', (string) $buy->unit_price);
        $this->assertSame('10.00', $buy->fee->toFixed(2));
        $this->assertSame('50010.00', $buy->total->toFixed(2));
        $this->assertSame(TransactionSource::Manual, $buy->source);
        $this->assertFalse($buy->estimated);
        $this->assertSame('First one.', $buy->notes);

        // It went through the ledger, so its parcel is there.
        $this->assertSame('50010.00', $buy->parcels()->sole()->cost_base->toFixed(2));
    }

    public function test_a_sale_is_recorded_with_the_fee_taken_off(): void
    {
        $this->add('2024-01-01', Transaction::factory()->buy('1', '90000'));

        $this->post('/transactions', $this->form(['type' => 'sell', 'estimated' => '1']))->assertSessionHasNoErrors();

        $sale = $this->asset->transactions()->where('type', 'sell')->sole();
        $this->assertSame('49990.00', $sale->total->toFixed(2));
        $this->assertTrue($sale->estimated);
        $this->assertSame('4990.00', $sale->disposals()->sole()->gain->toFixed(2));
    }

    public function test_a_total_that_is_typed_in_is_kept(): void
    {
        $this->post('/transactions', $this->form(['total' => '50012.34']))->assertSessionHasNoErrors();

        $this->assertSame('50012.34', $this->asset->transactions()->sole()->total->toFixed(2));
    }

    public function test_the_total_is_rounded_to_the_cent(): void
    {
        $this->post('/transactions', $this->form(['units' => '0.33333333', 'unit_price' => '0.125', 'fee' => '']))
            ->assertSessionHasNoErrors();

        // 0.04166666625 rounds to 4 cents.
        $this->assertSame('0.04', $this->asset->transactions()->sole()->total->toFixed(2));
    }

    public function test_a_find_has_no_price_or_fee_and_costs_nothing(): void
    {
        $this->post('/transactions', $this->form(['type' => 'found', 'units' => '2.35']))->assertSessionHasNoErrors();

        $found = $this->asset->transactions()->sole();
        $this->assertSame(TransactionType::Found, $found->type);
        $this->assertTrue($found->unit_price->isZero());
        $this->assertTrue($found->fee->isZero());
        $this->assertTrue($found->total->isZero());
    }

    public function test_a_find_can_be_given_a_cost(): void
    {
        $this->post('/transactions', $this->form(['type' => 'found', 'total' => '120']))->assertSessionHasNoErrors();

        $this->assertSame('120.00', $this->asset->transactions()->sole()->parcels()->sole()->cost_base->toFixed(2));
    }

    public function test_a_reward_has_a_price_and_no_fee(): void
    {
        $this->post('/transactions', $this->form(['type' => 'reward', 'units' => '18.5', 'unit_price' => '0.86']))
            ->assertSessionHasNoErrors();

        $reward = $this->asset->transactions()->sole();
        $this->assertTrue($reward->fee->isZero());
        $this->assertSame('15.91', $reward->total->toFixed(2));
    }

    public function test_a_transfer_out_has_no_amounts_but_its_units(): void
    {
        $this->add('2024-01-01', Transaction::factory()->buy('1', '90000'));

        $this->post('/transactions', $this->form(['type' => 'transfer_out', 'total' => '500']))->assertSessionHasNoErrors();

        $transfer = $this->asset->transactions()->where('type', 'transfer_out')->sole();
        $this->assertTrue($transfer->unit_price->isZero());
        $this->assertTrue($transfer->fee->isZero());
        $this->assertTrue($transfer->total->isZero());
    }

    public function test_a_transfer_in_from_outside_can_be_given_a_cost(): void
    {
        $this->post('/transactions', $this->form(['type' => 'transfer_in', 'unit_price' => '', 'fee' => '', 'total' => '45000']))
            ->assertSessionHasNoErrors();

        $this->assertSame('45000.00', $this->asset->transactions()->sole()->parcels()->sole()->cost_base->toFixed(2));
    }

    public function test_an_adjustment_can_take_units_away(): void
    {
        $this->add('2024-01-01', Transaction::factory()->buy('1', '90000'));

        $this->post('/transactions', $this->form(['type' => 'adjust', 'units' => '-0.25', 'total' => '99']))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Bitcoin: Adjust of -0.25 BTC recorded.');

        $adjustment = $this->asset->transactions()->where('type', 'adjust')->sole();
        $this->assertSame('-0.25', (string) $adjustment->units);
        $this->assertTrue($adjustment->total->isZero());
        $this->assertSame('0.75', (string) $this->asset->parcels()->sole()->units_remaining);
    }

    public function test_only_an_adjustment_may_have_negative_units(): void
    {
        $this->post('/transactions', $this->form(['units' => '-0.5']))->assertSessionHasErrors(['units']);

        $this->assertSame(0, Transaction::count());
    }

    public function test_the_fields_are_checked(): void
    {
        $this->post('/transactions', ['type' => 'gift'])
            ->assertSessionHasErrors(['asset_id', 'type', 'occurred_at', 'units']);

        $this->post('/transactions', $this->form(['units' => '0', 'unit_price' => '', 'fee' => '-1', 'total' => '1.234']))
            ->assertSessionHasErrors(['units', 'unit_price', 'fee', 'total']);

        $this->post('/transactions', $this->form(['units' => '0.123456789', 'unit_price' => '1,000', 'occurred_at' => '10/01/2024']))
            ->assertSessionHasErrors(['units', 'unit_price', 'occurred_at']);

        $this->assertSame(0, Transaction::count());
    }

    public function test_a_time_in_the_future_is_refused(): void
    {
        $tomorrow = now('Australia/Melbourne')->addDay()->format('Y-m-d\TH:i');

        $this->post('/transactions', $this->form(['occurred_at' => $tomorrow]))->assertSessionHasErrors(['occurred_at']);

        $this->assertSame(0, Transaction::count());
    }

    public function test_a_time_without_seconds_is_taken(): void
    {
        $this->post('/transactions', $this->form(['occurred_at' => '2024-07-10T09:30']))->assertSessionHasNoErrors();

        // 9:30am in Melbourne in July is 23:30 UTC the day before.
        $this->assertSame('2024-07-09 23:30:00', $this->asset->transactions()->sole()->occurred_at->toDateTimeString());
    }

    public function test_a_sale_whose_fee_is_more_than_its_proceeds_is_refused(): void
    {
        $this->add('2024-01-01', Transaction::factory()->buy('1', '90000'));

        $this->post('/transactions', $this->form(['type' => 'sell', 'units' => '0.001', 'unit_price' => '100', 'fee' => '5']))
            ->assertSessionHasErrors(['fee']);

        $this->assertSame(1, Transaction::count());
    }

    public function test_another_users_asset_is_refused(): void
    {
        $theirs = Asset::factory()->create();

        $this->post('/transactions', $this->form(['asset_id' => $theirs->id]))->assertSessionHasErrors(['asset_id']);

        $this->assertSame(0, Transaction::count());
    }

    public function test_selling_more_than_is_held_shows_on_the_form(): void
    {
        $this->add('2024-01-01', Transaction::factory()->buy('0.2', '90000'));

        $this->from('/transactions/create')
            ->post('/transactions', $this->form(['type' => 'sell']))
            ->assertRedirect('/transactions/create')
            ->assertSessionHasErrors(['ledger'])
            ->assertSessionHasInput('units', '0.5');

        $this->assertSame(1, Transaction::count());
        $this->assertSame('0.2', (string) $this->asset->parcels()->sole()->units_remaining);

        $this->followingRedirects()->from('/transactions/create')->post('/transactions', $this->form(['type' => 'sell']))
            ->assertSee('is more than the 0.2 BTC held')->assertSee('name="units" value="0.5"', false);
    }

    // Editing

    public function test_another_users_transaction_cannot_be_reached(): void
    {
        $theirs = $this->add('2024-01-01', Transaction::factory()->buy('1', '100'), Asset::factory()->create());

        $this->get("/transactions/{$theirs->id}/edit")->assertForbidden();
        $this->put("/transactions/{$theirs->id}", $this->form())->assertForbidden();
        $this->delete("/transactions/{$theirs->id}")->assertForbidden();

        $this->assertSame('1', (string) $theirs->fresh()->units);
    }

    public function test_the_edit_form_shows_the_transaction_in_the_users_time(): void
    {
        $buy = $this->add('2024-01-10', Transaction::factory()->buy('0.5', '100000', '10'));

        $this->get("/transactions/{$buy->id}/edit")
            ->assertOk()
            ->assertSee('<h4 class="page-title">Edit transaction</h4>', false)
            ->assertSee('<option value="buy" selected>', false)
            ->assertSee('name="occurred_at" value="2024-01-10T13:00:00"', false)
            ->assertSee('name="units" value="0.5"', false)
            ->assertSee('name="unit_price" value="100000"', false)
            ->assertSee('name="fee" value="10.00"', false)
            ->assertSee('name="total" value=""', false)
            ->assertSee('Delete transaction');
    }

    public function test_the_edit_form_shows_a_total_that_was_typed_in(): void
    {
        $buy = $this->add('2024-01-10', Transaction::factory()->buy('0.5', '100000', '10')->state(['total' => '50012.34']));

        $this->get("/transactions/{$buy->id}/edit")->assertSee('name="total" value="50012.34"', false);
    }

    public function test_a_transaction_is_amended_and_the_parcels_rebuilt(): void
    {
        $other = Asset::factory()->for($this->user)->create(['name' => 'Gold']);
        $buy = $this->add('2024-01-10', Transaction::factory()->buy('0.5', '100000', '10'));

        $this->put("/transactions/{$buy->id}", $this->form(['asset_id' => $other->id, 'units' => '0.75', 'occurred_at' => '2024-02-01T08:00:00']))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('transactions.index'))
            ->assertSessionHas('success', 'Bitcoin: Buy of 0.75 BTC saved.');

        $buy->refresh();
        $this->assertSame($this->asset->id, $buy->asset_id);
        $this->assertSame('0.75', (string) $buy->units);
        $this->assertSame('75010.00', $buy->total->toFixed(2));
        $this->assertSame('2024-01-31 21:00:00', $buy->occurred_at->toDateTimeString());
        $this->assertSame('0.75', (string) $buy->parcels()->sole()->units);
    }

    public function test_an_amendment_the_ledger_cannot_support_shows_on_the_form(): void
    {
        $buy = $this->add('2024-01-10', Transaction::factory()->buy('0.5', '100000'));
        $this->add('2024-03-10', Transaction::factory()->sell('0.5', '120000'));

        $this->from("/transactions/{$buy->id}/edit")
            ->put("/transactions/{$buy->id}", $this->form(['units' => '0.4']))
            ->assertRedirect("/transactions/{$buy->id}/edit")
            ->assertSessionHasErrors(['ledger']);

        $this->assertSame('0.5', (string) $buy->fresh()->units);
        $this->assertSame(1, $this->asset->parcels()->count());
    }

    public function test_a_transaction_from_an_exchange_changes_only_its_notes(): void
    {
        $exchange = Exchange::factory()->for($this->user)->create(['label' => 'BTC Markets']);
        $asset = Asset::factory()->onExchange($exchange, 'BTC/AUD')->create();
        $trade = $this->add('2024-01-10', Transaction::factory()->buy('0.5', '100000', '10')->fromExchange('trade-1'), $asset);

        $this->get("/transactions/{$trade->id}/edit")
            ->assertSee('This came from BTC Markets')
            ->assertSee('name="units" value="0.5" required disabled', false)
            ->assertSee('name="total" value="" disabled', false)
            ->assertDontSee('Delete transaction');

        $this->put("/transactions/{$trade->id}", ['type' => 'sell', 'units' => '9', 'notes' => 'Bought on the dip.'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('transactions.index'));

        $trade->refresh();
        $this->assertSame(TransactionType::Buy, $trade->type);
        $this->assertSame('0.5', (string) $trade->units);
        $this->assertSame('50010.00', $trade->total->toFixed(2));
        $this->assertSame(TransactionSource::Exchange, $trade->source);
        $this->assertSame('Bought on the dip.', $trade->notes);
    }

    public function test_an_imported_transaction_can_be_corrected(): void
    {
        $imported = $this->add('2024-01-10', Transaction::factory()->buy('0.5', '100000')->estimated()->state(['source' => TransactionSource::Import]));

        $this->put("/transactions/{$imported->id}", $this->form(['unit_price' => '98000', 'fee' => '']))->assertSessionHasNoErrors();

        $imported->refresh();
        $this->assertSame('49000.00', $imported->total->toFixed(2));
        $this->assertFalse($imported->estimated);
        $this->assertSame(TransactionSource::Import, $imported->source);
    }

    // Deleting

    public function test_a_transaction_is_deleted_and_the_parcels_rebuilt(): void
    {
        $this->add('2024-01-10', Transaction::factory()->buy('0.5', '100000'));
        $second = $this->add('2024-02-10', Transaction::factory()->buy('0.25', '110000'));

        $this->delete("/transactions/{$second->id}")
            ->assertRedirect(route('transactions.index'))
            ->assertSessionHas('success', 'Bitcoin: Buy of 0.25 BTC deleted.');

        $this->assertNull($second->fresh());
        $this->assertSame('0.5', (string) $this->asset->parcels()->sole()->units);
    }

    public function test_a_transaction_a_later_sale_needs_is_not_deleted(): void
    {
        $buy = $this->add('2024-01-10', Transaction::factory()->buy('0.5', '100000'));
        $this->add('2024-03-10', Transaction::factory()->sell('0.5', '120000'));

        $this->delete("/transactions/{$buy->id}")
            ->assertRedirect(route('transactions.edit', $buy))
            ->assertSessionHas('error');

        $this->assertNotNull($buy->fresh());
        $this->assertSame(1, $this->asset->parcels()->count());
    }

    public function test_a_transaction_from_an_exchange_is_not_deleted(): void
    {
        $trade = $this->add('2024-01-10', Transaction::factory()->buy('0.5', '100000')->fromExchange('trade-1'));

        $this->delete("/transactions/{$trade->id}")->assertForbidden();

        $this->assertNotNull($trade->fresh());
    }

    public function test_amending_a_hand_matched_sale_into_a_buy_drops_its_picks(): void
    {
        $this->add('2024-01-10', Transaction::factory()->buy('1', '100'));
        $second = $this->add('2024-02-10', Transaction::factory()->buy('1', '200'));
        $sale = $this->add('2024-03-10', Transaction::factory()->sell('1', '300'));
        app(Ledger::class)->pick($sale, [['parcel' => $second->parcels()->sole(), 'units' => '1']]);

        $this->put("/transactions/{$sale->id}", $this->form(['occurred_at' => '2024-03-10T13:00:00', 'units' => '1', 'unit_price' => '300', 'fee' => '']))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $sale->picks()->count());
        $this->assertSame(3, $this->asset->parcels()->count());
    }
}
