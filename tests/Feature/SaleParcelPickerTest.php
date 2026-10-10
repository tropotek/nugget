<?php

namespace Tests\Feature;

use App\Enums\ParcelMatching;
use App\Ledger\ParcelChoice;
use App\Ledger\ParcelMatcher;
use App\Models\Asset;
use App\Models\Disposal;
use App\Models\Transaction;
use App\Models\User;
use Database\Factories\TransactionFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Matching a sale to parcels by hand. The user is in Melbourne.
 *
 * The ledger here: 1 BTC bought for $100 in January 2023, 1 for $300 in
 * February 2024 and 1 for $200 in March 2024, then 1.5 sold for $600 in June
 * 2024. First in, first out uses the $100 parcel and half the $300 one.
 */
class SaleParcelPickerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Asset $asset;

    private Transaction $a;

    private Transaction $b;

    private Transaction $c;

    private Transaction $sale;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['timezone' => 'Australia/Melbourne']);
        $this->asset = Asset::factory()->for($this->user)->create(['name' => 'Bitcoin', 'symbol' => 'BTC', 'unit' => 'BTC']);
        $this->a = $this->add('2023-01-10', Transaction::factory()->buy('1', '100'));
        $this->b = $this->add('2024-02-10', Transaction::factory()->buy('1', '300'));
        $this->c = $this->add('2024-03-10', Transaction::factory()->buy('1', '200'));
        $this->sale = $this->add('2024-06-01', Transaction::factory()->sell('1.5', '400'));
        app(ParcelMatcher::class)->rematch($this->asset);
        $this->actingAs($this->user);
    }

    private function add(string $day, TransactionFactory $transaction, ?Asset $asset = null): Transaction
    {
        return $transaction->for($asset ?? $this->asset)->create(['occurred_at' => "{$day} 02:00:00"]);
    }

    /**
     * The form key of the parcel a buy opened.
     */
    private function key(Transaction $buy): string
    {
        return $buy->id.'-'.$buy->occurred_at->getTimestamp();
    }

    private function url(?Transaction $sale = null): string
    {
        return '/transactions/'.($sale ?? $this->sale)->id.'/parcels';
    }

    /**
     * The sale's disposals as [parcel cost base, units], oldest parcel first.
     *
     * @return list<array{string, string}>
     */
    private function used(): array
    {
        return $this->sale->disposals()->get()
            ->sortBy(fn (Disposal $d) => $d->parcel->acquired_at)
            ->map(fn (Disposal $d) => [$d->parcel->cost_base->toFixed(2), (string) $d->units])
            ->values()->all();
    }

    public function test_a_guest_is_sent_to_the_login_screen(): void
    {
        auth()->logout();

        $this->get($this->url())->assertRedirect(route('login'));
        $this->put($this->url(), [])->assertRedirect(route('login'));
    }

    public function test_another_users_sale_cannot_be_reached(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get($this->url())->assertForbidden();
        $this->put($this->url(), ['picks' => [$this->key($this->c) => '1.5']])->assertForbidden();
        $this->delete($this->url())->assertForbidden();

        $this->assertSame([['100.00', '1'], ['300.00', '0.5']], $this->used());
    }

    public function test_only_a_sale_has_parcels_to_pick(): void
    {
        $this->get($this->url($this->a))->assertNotFound();
        $this->put($this->url($this->a), [])->assertNotFound();
        $this->delete($this->url($this->a))->assertNotFound();
    }

    public function test_it_lists_the_parcels_open_at_the_sale_with_what_it_uses_now(): void
    {
        // A later buy and a later sale do not show, and do not change what was open.
        $this->add('2024-07-01', Transaction::factory()->buy('9', '500'));
        $this->add('2024-08-01', Transaction::factory()->sell('1', '500'));
        app(ParcelMatcher::class)->rematch($this->asset);

        $response = $this->get($this->url())->assertOk();

        /** @var Collection<string, ParcelChoice> $choices */
        $choices = $response->viewData('choices');
        $this->assertSame(
            [
                [$this->key($this->a), '1', '100.00', '1', true],
                [$this->key($this->b), '1', '300.00', '0.5', false],
                [$this->key($this->c), '1', '200.00', '0', false],
            ],
            $choices->values()->map(fn (ParcelChoice $c) => [$c->key, (string) $c->units, $c->costBase->toFixed(2), (string) $c->picked, $c->longHeld])->all(),
        );

        $response
            ->assertSee('<h4 class="page-title">Parcels for a sale</h4>', false)
            ->assertSeeInOrder(['1.5 BTC of Bitcoin on', '1 Jun 2024', '$600.00', 'First in, first out'])
            // Acquired, units left, cost base, gain per unit, the units used now and the gain on them.
            ->assertSeeInOrder(['10 Jan 2023', '1 BTC', '$100.00', '$300.00', '12 months', 'value="1"', '$300.00'])
            ->assertSeeInOrder(['10 Feb 2024', '1 BTC', '$300.00', '$100.00', 'value="0.5"', '$50.00'])
            ->assertSeeInOrder(['10 Mar 2024', '1 BTC', '$200.00', '$200.00', 'value=""'])
            ->assertSeeInOrder(['Picked', '1.5', 'of 1.5 BTC', '$350.00'])
            ->assertDontSee('Use default')
            ->assertDontSee('$500.00');
    }

    public function test_a_parcel_part_used_before_the_sale_shows_what_was_left(): void
    {
        $this->add('2024-04-01', Transaction::factory()->sell('0.75', '400'));
        app(ParcelMatcher::class)->rematch($this->asset);

        $choices = $this->get($this->url())->viewData('choices');

        $this->assertSame(
            [['0.25', '25.00'], ['1', '300.00'], ['1', '200.00']],
            $choices->values()->map(fn (ParcelChoice $c) => [(string) $c->units, $c->costBase->toFixed(2)])->all(),
        );
    }

    public function test_the_sale_is_matched_to_the_parcels_picked(): void
    {
        $this->put($this->url(), ['picks' => [$this->key($this->a) => '', $this->key($this->b) => '1', $this->key($this->c) => '0.5']])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('transactions.parcels.edit', $this->sale))
            ->assertSessionHas('success');

        $this->assertSame([['300.00', '1'], ['200.00', '0.5']], $this->used());
        $this->assertTrue($this->sale->fresh()->isHandMatched());

        $this->get($this->url())
            ->assertSeeInOrder(['Matched', 'By hand'])
            ->assertSeeInOrder(['10 Jan 2023', 'value=""', '10 Feb 2024', 'value="1"', '10 Mar 2024', 'value="0.5"'])
            ->assertSee('Use default');
    }

    public function test_a_zero_is_the_same_as_leaving_a_parcel_out(): void
    {
        $this->put($this->url(), ['picks' => [$this->key($this->a) => '0', $this->key($this->b) => '0.50', $this->key($this->c) => '1.0']])
            ->assertSessionHasNoErrors();

        $this->assertSame([['300.00', '0.5'], ['200.00', '1']], $this->used());
        $this->assertSame(2, $this->sale->picks()->count());
    }

    public function test_the_units_picked_must_add_up_to_the_units_sold(): void
    {
        $this->put($this->url(), ['picks' => [$this->key($this->b) => '1', $this->key($this->c) => '0.25']])
            ->assertSessionHasErrors(['picks' => 'The parcels picked add up to 1.25 BTC, but the sale is of 1.5 BTC.']);

        $this->put($this->url(), ['picks' => [$this->key($this->b) => '1', $this->key($this->c) => '1']])
            ->assertSessionHasErrors(['picks']);
        $this->put($this->url(), [])->assertSessionHasErrors(['picks']);

        $this->assertSame([['100.00', '1'], ['300.00', '0.5']], $this->used());
        $this->assertFalse($this->sale->fresh()->isHandMatched());
    }

    public function test_no_more_can_be_picked_from_a_parcel_than_it_has(): void
    {
        $key = $this->key($this->c);

        $this->put($this->url(), ['picks' => [$key => '1.5']])
            ->assertSessionHasErrors(["picks.{$key}" => 'This parcel has 1 BTC left.']);

        $this->assertFalse($this->sale->fresh()->isHandMatched());
    }

    public function test_the_units_typed_are_checked(): void
    {
        $key = $this->key($this->c);

        foreach (['-1', 'half', '0.123456789', '1,5'] as $units) {
            $this->put($this->url(), ['picks' => [$key => $units]])->assertSessionHasErrors(["picks.{$key}"]);
        }

        $this->put($this->url(), ['picks' => 'all'])->assertSessionHasErrors(['picks']);
        $this->assertFalse($this->sale->fresh()->isHandMatched());
    }

    public function test_a_parcel_that_is_not_one_of_the_choices_is_passed_over(): void
    {
        $later = $this->add('2024-07-01', Transaction::factory()->buy('9', '500'));
        app(ParcelMatcher::class)->rematch($this->asset);

        $this->put($this->url(), ['picks' => [$this->key($later) => '1.5']])->assertSessionHasErrors(['picks']);
        $this->put($this->url(), ['picks' => [$this->key($later) => '1', $this->key($this->c) => '1', $this->key($this->b) => '0.5']])
            ->assertSessionHasNoErrors();

        $this->assertSame([['300.00', '0.5'], ['200.00', '1']], $this->used());
    }

    public function test_a_refused_pick_shows_what_was_typed_and_why(): void
    {
        $this->followingRedirects()->from($this->url())
            ->put($this->url(), ['picks' => [$this->key($this->a) => '', $this->key($this->b) => '1', $this->key($this->c) => '0.25']])
            ->assertSee('The parcels picked add up to 1.25 BTC, but the sale is of 1.5 BTC.')
            ->assertSeeInOrder(['10 Jan 2023', 'value=""', '10 Feb 2024', 'value="1"', '10 Mar 2024', 'value="0.25"'])
            ->assertSeeInOrder(['Picked', '1.25', 'of 1.5 BTC']);
    }

    public function test_a_pick_that_leaves_a_later_hand_matched_sale_short_is_refused(): void
    {
        $later = $this->add('2024-08-01', Transaction::factory()->sell('1', '500'));
        app(ParcelMatcher::class)->rematch($this->asset);
        $this->put($this->url($later), ['picks' => [$this->key($this->c) => '1']])->assertSessionHasNoErrors();

        $this->put($this->url(), ['picks' => [$this->key($this->b) => '0.5', $this->key($this->c) => '1']])
            ->assertSessionHasErrors(['picks']);

        $this->assertSame([['100.00', '1'], ['300.00', '0.5']], $this->used());
        $this->assertFalse($this->sale->fresh()->isHandMatched());
    }

    public function test_use_default_returns_the_sale_to_the_configured_method(): void
    {
        $this->put($this->url(), ['picks' => [$this->key($this->b) => '1', $this->key($this->c) => '0.5']]);

        $this->delete($this->url())
            ->assertRedirect(route('transactions.parcels.edit', $this->sale))
            ->assertSessionHas('success', 'The sale is matched by the default method: First in, first out.');

        $sale = $this->sale->fresh();
        $this->assertFalse($sale->isHandMatched());
        $this->assertSame(ParcelMatching::Fifo, $sale->matching);
        $this->assertSame([['100.00', '1'], ['300.00', '0.5']], $this->used());
    }

    public function test_a_sale_from_an_exchange_can_be_matched_by_hand(): void
    {
        $this->sale->forceFill(['source' => 'exchange', 'external_id' => 'trade-9'])->save();

        $this->put($this->url(), ['picks' => [$this->key($this->b) => '1', $this->key($this->c) => '0.5']])->assertSessionHasNoErrors();

        $this->assertTrue($this->sale->fresh()->isHandMatched());
    }

    public function test_the_list_and_the_edit_form_link_to_a_sales_parcels(): void
    {
        $link = 'href="'.route('transactions.parcels.edit', $this->sale).'"';

        $this->get('/transactions')->assertSee($link, false)
            ->assertDontSee('href="'.route('transactions.parcels.edit', $this->a).'"', false);
        $this->get("/transactions/{$this->sale->id}/edit")->assertSee($link, false)->assertSee('Parcels matched by: First in, first out.');
        $this->get("/transactions/{$this->a->id}/edit")->assertDontSee('Choose parcels');
    }
}
