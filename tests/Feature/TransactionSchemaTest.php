<?php

namespace Tests\Feature;

use App\Enums\TransactionSource;
use App\Enums\TransactionType;
use App\Models\Asset;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TransactionSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_transactions_table_has_the_expected_columns(): void
    {
        $this->assertEqualsCanonicalizing(
            [
                'id', 'asset_id', 'type', 'occurred_at', 'units', 'unit_price', 'fee', 'total', 'source',
                'external_id', 'estimated', 'notes', 'created_at', 'updated_at',
            ],
            Schema::getColumnListing('transactions'),
        );
    }

    public function test_a_transaction_belongs_to_an_asset_and_through_it_to_a_user(): void
    {
        $asset = Asset::factory()->create();
        $transaction = $asset->transactions()->create([
            'type' => 'buy', 'occurred_at' => '2025-03-01 02:00:00', 'units' => '0.5', 'unit_price' => '100000', 'total' => '50000',
        ]);

        $this->assertTrue($transaction->asset->is($asset));
        $this->assertTrue($asset->transactions->first()->is($transaction));
        $this->assertSame($asset->user_id, $transaction->ownerId());
    }

    public function test_a_new_transaction_is_manual_not_estimated_and_has_no_fee(): void
    {
        $transaction = Asset::factory()->create()->transactions()
            ->create(['type' => 'buy', 'occurred_at' => now(), 'units' => '1', 'unit_price' => '10', 'total' => '10'])
            ->fresh();

        $this->assertSame(TransactionSource::Manual, $transaction->source);
        $this->assertFalse($transaction->estimated);
        $this->assertTrue($transaction->fee->isZero());
        $this->assertNull($transaction->external_id);
        $this->assertNull($transaction->notes);
    }

    public function test_the_amounts_are_decimals_stored_at_fixed_scales(): void
    {
        $transaction = Transaction::factory()->create([
            'units' => '0.12345678', 'unit_price' => '0.00004321', 'fee' => '1.5', 'total' => '1234.5',
        ]);
        $stored = DB::table('transactions')->find($transaction->id);

        $this->assertSame('0.12345678', $stored->units);
        $this->assertSame('0.00004321', $stored->unit_price);
        $this->assertSame('1.50', $stored->fee);
        $this->assertSame('1234.50', $stored->total);

        $transaction->refresh();
        $this->assertInstanceOf(Decimal::class, $transaction->units);
        $this->assertTrue($transaction->unit_price->equals('0.00004321'));
        $this->assertSame('1,234.50', $transaction->total->format());
    }

    public function test_the_type_source_date_and_flag_are_cast(): void
    {
        $transaction = Transaction::factory()->create([
            'type' => TransactionType::TransferOut,
            'source' => TransactionSource::Import,
            'occurred_at' => '2025-06-30 14:05:00',
            'estimated' => true,
        ])->fresh();

        $this->assertDatabaseHas('transactions', ['id' => $transaction->id, 'type' => 'transfer_out', 'source' => 'import']);
        $this->assertSame(TransactionType::TransferOut, $transaction->type);
        $this->assertSame(TransactionSource::Import, $transaction->source);
        $this->assertInstanceOf(Carbon::class, $transaction->occurred_at);
        $this->assertSame('2025-06-30 14:05:00', $transaction->occurred_at->toDateTimeString());
        $this->assertTrue($transaction->estimated);
    }

    public function test_the_types_are_the_seven_in_the_proposal(): void
    {
        $this->assertSame(
            ['buy', 'sell', 'found', 'reward', 'transfer_in', 'transfer_out', 'adjust'],
            array_column(TransactionType::cases(), 'value'),
        );
        $this->assertSame('Transfer in', TransactionType::TransferIn->label());
        $this->assertSame('Buy', TransactionType::Buy->label());
    }

    public function test_a_type_adds_units_removes_them_or_does_either(): void
    {
        $adds = fn (TransactionType $type) => $type->addsUnits();
        $removes = fn (TransactionType $type) => $type->removesUnits();

        $this->assertSame(
            [TransactionType::Buy, TransactionType::Found, TransactionType::Reward, TransactionType::TransferIn],
            array_values(array_filter(TransactionType::cases(), $adds)),
        );
        $this->assertSame(
            [TransactionType::Sell, TransactionType::TransferOut],
            array_values(array_filter(TransactionType::cases(), $removes)),
        );
        $this->assertFalse(TransactionType::Adjust->addsUnits());
        $this->assertFalse(TransactionType::Adjust->removesUnits());
    }

    public function test_the_signed_units_say_which_way_the_holding_moves(): void
    {
        $this->assertSame('0.5', (string) Transaction::factory()->buy('0.5', '100')->make()->signedUnits());
        $this->assertSame('-0.5', (string) Transaction::factory()->sell('0.5', '100')->make()->signedUnits());
        $this->assertSame('-0.5', (string) Transaction::factory()->transferOut('0.5')->make()->signedUnits());
        $this->assertSame('2', (string) Transaction::factory()->adjust('2')->make()->signedUnits());
        $this->assertSame('-2', (string) Transaction::factory()->adjust('-2')->make()->signedUnits());
    }

    public function test_the_sources_are_manual_exchange_and_import(): void
    {
        $this->assertSame(['manual', 'exchange', 'import'], array_column(TransactionSource::cases(), 'value'));
    }

    public function test_an_exchange_trade_id_can_be_stored_only_once_for_an_asset(): void
    {
        $asset = Asset::factory()->create();
        Transaction::factory()->for($asset)->fromExchange('trade-1')->create();

        $this->expectException(UniqueConstraintViolationException::class);

        Transaction::factory()->for($asset)->fromExchange('trade-1')->create();
    }

    public function test_the_same_trade_id_is_fine_on_another_asset_or_from_another_source(): void
    {
        $asset = Asset::factory()->create();
        Transaction::factory()->for($asset)->fromExchange('trade-1')->create();

        Transaction::factory()->for(Asset::factory())->fromExchange('trade-1')->create();
        Transaction::factory()->for($asset)->create(['source' => 'import', 'external_id' => 'trade-1']);

        $this->assertSame(3, Transaction::count());
    }

    public function test_any_number_of_transactions_can_have_no_trade_id(): void
    {
        $asset = Asset::factory()->create();

        Transaction::factory()->for($asset)->count(3)->create();

        $this->assertSame(3, $asset->transactions()->whereNull('external_id')->count());
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function badAmounts(): array
    {
        return [
            'no units' => [['units' => '0']],
            'negative units on a buy' => [['units' => '-1']],
            'a negative price' => [['unit_price' => '-0.01']],
            'a negative fee' => [['fee' => '-0.01']],
            'a negative total' => [['total' => '-0.01']],
            'an adjustment of nothing' => [['type' => 'adjust', 'units' => '0']],
        ];
    }

    /**
     * @param  array<string, mixed>  $amounts
     */
    #[DataProvider('badAmounts')]
    public function test_amounts_that_make_no_sense_are_refused(array $amounts): void
    {
        $this->expectException(LogicException::class);

        Transaction::factory()->create($amounts);
    }

    public function test_only_an_adjustment_may_have_negative_units(): void
    {
        $transaction = Transaction::factory()->adjust('-0.00000001')->create()->fresh();

        $this->assertTrue($transaction->units->isNegative());
    }

    public function test_the_asset_cannot_be_mass_assigned(): void
    {
        $this->assertNull((new Transaction(['type' => 'buy', 'asset_id' => 99]))->asset_id);
    }

    public function test_the_factory_has_a_state_for_each_type_and_works_out_the_total(): void
    {
        $buy = Transaction::factory()->buy('0.5', '100000', '42.50')->create()->fresh();
        $this->assertSame(TransactionType::Buy, $buy->type);
        $this->assertSame('50042.50', $buy->total->toFixed(2));

        $sell = Transaction::factory()->sell('0.5', '100000', '42.50')->create()->fresh();
        $this->assertSame(TransactionType::Sell, $sell->type);
        $this->assertSame('49957.50', $sell->total->toFixed(2));

        $found = Transaction::factory()->found('2.35')->create()->fresh();
        $this->assertSame(TransactionType::Found, $found->type);
        $this->assertTrue($found->unit_price->isZero());
        $this->assertTrue($found->total->isZero());

        $reward = Transaction::factory()->reward('12.5', '0.80')->create()->fresh();
        $this->assertSame(TransactionType::Reward, $reward->type);
        $this->assertSame('10.00', $reward->total->toFixed(2));

        $this->assertSame(TransactionType::TransferIn, Transaction::factory()->transferIn('1')->create()->type);
        $this->assertSame(TransactionType::TransferOut, Transaction::factory()->transferOut('1')->create()->type);
        $this->assertSame(TransactionType::Adjust, Transaction::factory()->adjust('1')->create()->type);
    }

    public function test_the_factory_marks_a_trade_from_an_exchange_and_an_estimate(): void
    {
        $trade = Transaction::factory()->fromExchange('abc-123')->create()->fresh();
        $this->assertSame(TransactionSource::Exchange, $trade->source);
        $this->assertSame('abc-123', $trade->external_id);

        $this->assertTrue(Transaction::factory()->estimated()->create()->estimated);
    }

    public function test_only_the_owner_of_the_asset_may_work_with_a_transaction(): void
    {
        $transaction = Transaction::factory()->create();

        $this->assertTrue(Gate::forUser($transaction->asset->user)->allows('update', $transaction));
        $this->assertFalse(Gate::forUser(User::factory()->admin()->create())->allows('view', $transaction));
    }

    public function test_an_asset_with_transactions_cannot_be_deleted(): void
    {
        $transaction = Transaction::factory()->create();

        $this->expectException(QueryException::class);

        $transaction->asset->delete();
    }
}
