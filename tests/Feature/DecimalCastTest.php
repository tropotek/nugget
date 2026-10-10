<?php

namespace Tests\Feature;

use App\Casts\DecimalCast;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;
use TypeError;

class DecimalCastTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('ledger_lines', function (Blueprint $table) {
            $table->id();
            $table->string('units')->nullable();
            $table->string('total')->nullable();
            $table->decimal('numeric_column', 20, 8)->nullable();
        });
    }

    /**
     * The row as the database holds it.
     */
    private function stored(LedgerLine $line): object
    {
        return DB::table('ledger_lines')->find($line->id);
    }

    public function test_units_are_stored_to_eight_places_and_aud_to_two(): void
    {
        $line = LedgerLine::create(['units' => '0.1', 'total' => '1234.5']);

        $this->assertSame('0.10000000', $this->stored($line)->units);
        $this->assertSame('1234.50', $this->stored($line)->total);
    }

    public function test_a_value_is_read_back_as_a_decimal(): void
    {
        $line = LedgerLine::create(['units' => '0.1', 'total' => '1234.5'])->fresh();

        $this->assertInstanceOf(Decimal::class, $line->units);
        $this->assertTrue($line->units->equals('0.1'));
        $this->assertSame('1,234.50', $line->total->format());
    }

    public function test_it_takes_a_decimal_a_string_or_an_integer(): void
    {
        $line = LedgerLine::create(['units' => Decimal::of('2.5'), 'total' => 40]);

        $this->assertSame('2.50000000', $this->stored($line)->units);
        $this->assertSame('40.00', $this->stored($line)->total);
    }

    public function test_extra_places_are_rounded_when_stored(): void
    {
        $line = LedgerLine::create(['units' => '0.123456785', 'total' => '1.005']);

        $this->assertSame('0.12345679', $this->stored($line)->units);
        $this->assertSame('1.01', $this->stored($line)->total);
    }

    public function test_a_value_too_precise_for_a_float_survives(): void
    {
        $line = LedgerLine::create(['units' => '123456789012.12345678'])->fresh();

        $this->assertSame('123456789012.12345678', (string) $line->units);
    }

    public function test_nothing_stays_nothing(): void
    {
        $line = LedgerLine::create(['units' => null])->fresh();

        $this->assertNull($line->units);
        $this->assertNull($this->stored($line)->units);
    }

    public function test_a_float_is_refused(): void
    {
        $this->expectException(TypeError::class);

        new LedgerLine(['units' => 0.1]);
    }

    public function test_a_string_that_is_not_a_number_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LedgerLine(['total' => '$1,234.50']);
    }

    public function test_a_numeric_column_is_refused_because_sqlite_would_make_it_a_float(): void
    {
        DB::table('ledger_lines')->insert(['numeric_column' => '0.1']);

        $this->expectException(LogicException::class);

        LedgerLine::first()->numeric_column;
    }

    public function test_the_cast_names_its_scales(): void
    {
        $this->assertSame(DecimalCast::class.':8', DecimalCast::units());
        $this->assertSame(DecimalCast::class.':2', DecimalCast::aud());
        $this->assertSame(DecimalCast::class.':4', DecimalCast::scale(4));
    }
}

class LedgerLine extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'units' => DecimalCast::units(),
            'total' => DecimalCast::aud(),
            'numeric_column' => DecimalCast::units(),
        ];
    }
}
