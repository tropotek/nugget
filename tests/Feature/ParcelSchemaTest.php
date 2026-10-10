<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Disposal;
use App\Models\Parcel;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ParcelSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_parcels_table_has_the_expected_columns(): void
    {
        $this->assertEqualsCanonicalizing(
            [
                'id', 'asset_id', 'transaction_id', 'acquired_at', 'units', 'units_remaining', 'cost_base', 'estimated',
                'created_at', 'updated_at',
            ],
            Schema::getColumnListing('parcels'),
        );
    }

    public function test_a_parcel_belongs_to_the_transaction_that_acquired_it_and_to_its_asset(): void
    {
        $buy = Transaction::factory()->buy('0.5', '100000', '50')->create();
        $parcel = $buy->parcels()->create([
            'acquired_at' => $buy->occurred_at, 'units' => '0.5', 'units_remaining' => '0.5', 'cost_base' => '50050',
        ]);

        $this->assertTrue($parcel->transaction->is($buy));
        $this->assertTrue($parcel->asset->is($buy->asset));
        $this->assertTrue($buy->parcels->first()->is($parcel));
        $this->assertTrue($buy->asset->parcels->first()->is($parcel));
        $this->assertSame($buy->asset->user_id, $parcel->ownerId());
    }

    public function test_a_transaction_can_bring_in_several_parcels(): void
    {
        $transfer = Transaction::factory()->transferIn('3')->create();

        Parcel::factory()->for($transfer)->count(2)->create();

        $this->assertSame(2, $transfer->parcels()->count());
    }

    public function test_the_amounts_are_decimals_stored_at_fixed_scales(): void
    {
        $parcel = Parcel::factory()->create(['units' => '0.5', 'units_remaining' => '0.125', 'cost_base' => '50050.5']);
        $stored = DB::table('parcels')->find($parcel->id);

        $this->assertSame('0.50000000', $stored->units);
        $this->assertSame('0.12500000', $stored->units_remaining);
        $this->assertSame('50050.50', $stored->cost_base);
        $this->assertInstanceOf(Decimal::class, $parcel->fresh()->cost_base);
    }

    public function test_the_acquired_date_and_the_estimated_flag_are_cast(): void
    {
        $parcel = Parcel::factory()->estimated()->create(['acquired_at' => '2024-08-05 01:14:00'])->fresh();

        $this->assertInstanceOf(Carbon::class, $parcel->acquired_at);
        $this->assertSame('2024-08-05 01:14:00', $parcel->acquired_at->toDateTimeString());
        $this->assertTrue($parcel->estimated);
        $this->assertFalse(Parcel::factory()->create()->fresh()->estimated);
    }

    public function test_the_cost_base_left_follows_the_units_left(): void
    {
        $parcel = fn (string $remaining) => Parcel::factory()
            ->make(['units' => '3', 'units_remaining' => $remaining, 'cost_base' => '100']);

        $this->assertSame('100.00', $parcel('3')->remainingCostBase()->toFixed(2));
        $this->assertSame('66.67', $parcel('2')->remainingCostBase()->toFixed(2));
        $this->assertSame('33.33', $parcel('1')->remainingCostBase()->toFixed(2));
        $this->assertSame('0.00', $parcel('0')->remainingCostBase()->toFixed(2));
    }

    public function test_a_parcel_is_open_while_it_has_units_left(): void
    {
        $open = Parcel::factory()->create(['units' => '1', 'units_remaining' => '0.00000001']);
        $used = Parcel::factory()->used()->create();

        $this->assertTrue($open->isOpen());
        $this->assertFalse($used->isOpen());
        $this->assertSame([$open->id], Parcel::open()->pluck('id')->all());
    }

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function badParcels(): array
    {
        return [
            'no units' => [['units' => '0', 'units_remaining' => '0']],
            'negative units' => [['units' => '-1', 'units_remaining' => '-1']],
            'more left than acquired' => [['units' => '1', 'units_remaining' => '1.00000001']],
            'less than nothing left' => [['units' => '1', 'units_remaining' => '-0.00000001']],
            'a negative cost base' => [['cost_base' => '-0.01']],
        ];
    }

    /**
     * @param  array<string, string>  $amounts
     */
    #[DataProvider('badParcels')]
    public function test_a_parcel_that_makes_no_sense_is_refused(array $amounts): void
    {
        $this->expectException(LogicException::class);

        Parcel::factory()->create($amounts);
    }

    public function test_a_parcel_cannot_be_on_a_different_asset_from_its_transaction(): void
    {
        $this->expectException(LogicException::class);

        Parcel::factory()
            ->for(Transaction::factory()->create())
            ->create(['asset_id' => Asset::factory()->create()->id]);
    }

    public function test_the_factory_can_build_the_parcel_of_an_acquisition(): void
    {
        $buy = Transaction::factory()->buy('0.5', '100000', '50')->estimated()->create(['occurred_at' => '2024-08-05 01:14:00']);

        $parcel = Parcel::factory()->acquiredBy($buy)->create()->fresh();

        $this->assertTrue($parcel->transaction->is($buy));
        $this->assertSame('2024-08-05 01:14:00', $parcel->acquired_at->toDateTimeString());
        $this->assertTrue($parcel->units->equals('0.5'));
        $this->assertTrue($parcel->units_remaining->equals('0.5'));
        $this->assertSame('50050.00', $parcel->cost_base->toFixed(2));
        $this->assertTrue($parcel->estimated);
    }

    public function test_the_factory_has_states_for_a_part_used_and_a_used_up_parcel(): void
    {
        $this->assertTrue(Parcel::factory()->remaining('0.25')->create()->units_remaining->equals('0.25'));
        $this->assertTrue(Parcel::factory()->used()->create()->units_remaining->isZero());
    }

    public function test_only_the_owner_of_the_asset_may_work_with_a_parcel(): void
    {
        $parcel = Parcel::factory()->create();

        $this->assertTrue(Gate::forUser($parcel->asset->user)->allows('view', $parcel));
        $this->assertFalse(Gate::forUser(User::factory()->admin()->create())->allows('view', $parcel));
    }

    public function test_a_transaction_with_a_parcel_cannot_be_deleted(): void
    {
        $parcel = Parcel::factory()->create();

        $this->expectException(QueryException::class);

        $parcel->transaction->delete();
    }

    public function test_a_parcel_with_a_disposal_cannot_be_deleted(): void
    {
        $disposal = Disposal::factory()->create();

        $this->expectException(QueryException::class);

        $disposal->parcel->delete();
    }
}
