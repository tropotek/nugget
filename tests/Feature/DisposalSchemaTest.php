<?php

namespace Tests\Feature;

use App\Models\Disposal;
use App\Models\Parcel;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DisposalSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_disposals_table_has_the_expected_columns(): void
    {
        $this->assertEqualsCanonicalizing(
            [
                'id', 'transaction_id', 'parcel_id', 'units', 'cost_base', 'proceeds', 'gain', 'long_held',
                'created_at', 'updated_at',
            ],
            Schema::getColumnListing('disposals'),
        );
    }

    public function test_a_disposal_joins_a_sale_to_a_parcel_it_used(): void
    {
        $parcel = Parcel::factory()->create();
        $sale = Transaction::factory()->for($parcel->asset)->sell('1', '150')->create();

        $disposal = $sale->disposals()->create([
            'parcel_id' => $parcel->id, 'units' => '1', 'cost_base' => '100', 'proceeds' => '150', 'gain' => '50',
        ]);

        $this->assertTrue($disposal->transaction->is($sale));
        $this->assertTrue($disposal->parcel->is($parcel));
        $this->assertTrue($sale->disposals->first()->is($disposal));
        $this->assertTrue($parcel->disposals->first()->is($disposal));
        $this->assertSame($parcel->asset->user_id, $disposal->ownerId());
        $this->assertFalse($disposal->fresh()->long_held);
    }

    public function test_a_sale_can_use_several_parcels(): void
    {
        $sale = Transaction::factory()->sell('3', '150')->create();

        foreach (range(1, 3) as $i) {
            Disposal::factory()->for($sale)->for(Parcel::factory()->for($sale->asset))->create();
        }

        $this->assertSame(3, $sale->disposals()->count());
        $this->assertSame(3, $sale->disposals()->distinct()->count('parcel_id'));
    }

    public function test_the_amounts_are_decimals_stored_at_fixed_scales_and_a_loss_is_negative(): void
    {
        $disposal = Disposal::factory()->amounts('0.25', '5000.5', '4200')->create();
        $stored = DB::table('disposals')->find($disposal->id);

        $this->assertSame('0.25000000', $stored->units);
        $this->assertSame('5000.50', $stored->cost_base);
        $this->assertSame('4200.00', $stored->proceeds);
        $this->assertSame('-800.50', $stored->gain);

        $this->assertInstanceOf(Decimal::class, $disposal->fresh()->gain);
        $this->assertTrue($disposal->fresh()->gain->isNegative());
    }

    public function test_the_long_held_flag_is_cast(): void
    {
        $this->assertTrue(Disposal::factory()->longHeld()->create()->fresh()->long_held);
    }

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function badDisposals(): array
    {
        return [
            'no units' => [['units' => '0']],
            'negative units' => [['units' => '-1']],
            'a negative cost base' => [['cost_base' => '-0.01', 'gain' => '150.01']],
            'negative proceeds' => [['proceeds' => '-0.01', 'gain' => '-100.01']],
            'a gain that is not proceeds less cost' => [['cost_base' => '100', 'proceeds' => '150', 'gain' => '50.01']],
        ];
    }

    /**
     * @param  array<string, string>  $amounts
     */
    #[DataProvider('badDisposals')]
    public function test_a_disposal_that_makes_no_sense_is_refused(array $amounts): void
    {
        $this->expectException(LogicException::class);

        Disposal::factory()->create($amounts);
    }

    public function test_a_disposal_cannot_join_a_sale_and_a_parcel_of_different_assets(): void
    {
        $this->expectException(LogicException::class);

        Disposal::factory()
            ->for(Transaction::factory()->sell('1', '150')->create())
            ->for(Parcel::factory()->create())
            ->create();
    }

    public function test_the_factory_makes_a_sale_of_the_parcels_own_asset(): void
    {
        $disposal = Disposal::factory()->create()->fresh();

        $this->assertTrue($disposal->transaction->asset->is($disposal->parcel->asset));
        $this->assertTrue($disposal->gain->equals($disposal->proceeds->sub($disposal->cost_base)));
    }

    public function test_only_the_owner_of_the_asset_may_work_with_a_disposal(): void
    {
        $disposal = Disposal::factory()->create();

        $this->assertTrue(Gate::forUser($disposal->parcel->asset->user)->allows('view', $disposal));
        $this->assertFalse(Gate::forUser(User::factory()->admin()->create())->allows('view', $disposal));
    }

    public function test_a_sale_with_a_disposal_cannot_be_deleted(): void
    {
        $disposal = Disposal::factory()->create();

        $this->expectException(QueryException::class);

        $disposal->transaction->delete();
    }
}
