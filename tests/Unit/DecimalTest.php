<?php

namespace Tests\Unit;

use App\Support\Decimal;
use DivisionByZeroError;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TypeError;

class DecimalTest extends TestCase
{
    public function test_it_is_made_from_a_string_an_integer_or_another_decimal(): void
    {
        $this->assertSame('12.5', (string) Decimal::of('12.5'));
        $this->assertSame('-7', (string) Decimal::of(-7));
        $this->assertSame('12.5', (string) Decimal::of(Decimal::of('12.5')));
        $this->assertSame('0', (string) Decimal::zero());
    }

    public function test_it_prints_without_padding(): void
    {
        $this->assertSame('1.5', (string) Decimal::of('1.50000000'));
        $this->assertSame('100', (string) Decimal::of('100.00'));
        $this->assertSame('0', (string) Decimal::of('-0.00'));
        $this->assertSame('0.00000001', (string) Decimal::of('0.00000001'));
    }

    public function test_a_float_is_refused(): void
    {
        $this->expectException(TypeError::class);

        Decimal::of(0.1);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badStrings(): array
    {
        return [
            'empty' => [''],
            'words' => ['twelve'],
            'exponent' => ['1e5'],
            'thousands separator' => ['1,234.50'],
            'two points' => ['1.2.3'],
            'no leading digit' => ['.5'],
            'no trailing digit' => ['5.'],
            'spaces' => [' 5 '],
            'currency sign' => ['$5'],
            'plus sign' => ['+5'],
        ];
    }

    #[DataProvider('badStrings')]
    public function test_a_string_that_is_not_a_plain_number_is_refused(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        Decimal::of($value);
    }

    public function test_it_adds_what_floats_cannot(): void
    {
        $this->assertNotSame(0.3, 0.1 + 0.2);

        $this->assertSame('0.3', (string) Decimal::of('0.1')->add('0.2'));
    }

    public function test_adding_a_tenth_ten_times_makes_one(): void
    {
        $sum = Decimal::zero();
        $float = 0.0;

        foreach (range(1, 10) as $i) {
            $sum = $sum->add('0.1');
            $float += 0.1;
        }

        $this->assertNotSame(1.0, $float);
        $this->assertTrue($sum->equals(1));
    }

    public function test_it_keeps_integers_a_float_cannot_hold(): void
    {
        $this->assertSame(9007199254740992.0, 9007199254740992.0 + 1);

        $this->assertSame('9007199254740993', (string) Decimal::of('9007199254740992')->add(1));
    }

    public function test_it_subtracts(): void
    {
        $this->assertNotSame(0.1, 0.3 - 0.2);

        $this->assertSame('0.1', (string) Decimal::of('0.3')->sub('0.2'));
        $this->assertSame('-0.5', (string) Decimal::of('1')->sub('1.5'));
    }

    public function test_it_multiplies_without_losing_places(): void
    {
        $this->assertNotSame(0.3, 0.1 * 3);

        $this->assertSame('0.3', (string) Decimal::of('0.1')->mul(3));
        $this->assertSame('1.2345678912345678', (string) Decimal::of('123456789.12345678')->mul('0.00000001'));
        $this->assertSame('-6', (string) Decimal::of('-2')->mul('3'));
    }

    public function test_it_divides_to_a_number_of_places_and_rounds_the_last(): void
    {
        $this->assertSame('0.33333333', (string) Decimal::of(1)->div(3, 8));
        $this->assertSame('0.66666667', (string) Decimal::of(2)->div(3, 8));
        $this->assertSame('-0.66666667', (string) Decimal::of(-2)->div(3, 8));
        $this->assertSame('0.67', (string) Decimal::of(2)->div(3, 2));
        $this->assertSame('2.5', (string) Decimal::of(10)->div(4, 8));
        $this->assertSame('1', (string) Decimal::of(2)->div(3, 0));
    }

    public function test_dividing_by_zero_throws(): void
    {
        $this->expectException(DivisionByZeroError::class);

        Decimal::of(1)->div('0.00', 8);
    }

    public function test_it_does_not_change(): void
    {
        $a = Decimal::of('1.5');
        $a->add(1);
        $a->mul(2);
        $a->round(0);
        $a->negate();

        $this->assertSame('1.5', (string) $a);
    }

    public function test_it_compares_by_value(): void
    {
        $this->assertSame(0, Decimal::of('1.50')->compare('1.5'));
        $this->assertSame(-1, Decimal::of('1.49999999')->compare('1.5'));
        $this->assertSame(1, Decimal::of('0.00000001')->compare(0));

        $this->assertTrue(Decimal::of('1.50')->equals('1.5'));
        $this->assertFalse(Decimal::of('1.5')->equals('1.50000001'));
        $this->assertTrue(Decimal::of('2')->greaterThan('1.99999999'));
        $this->assertFalse(Decimal::of('2')->greaterThan(2));
        $this->assertTrue(Decimal::of('-2')->lessThan('-1.99999999'));
        $this->assertFalse(Decimal::of('2')->lessThan(2));
    }

    public function test_it_knows_its_sign(): void
    {
        $this->assertTrue(Decimal::of('0.00')->isZero());
        $this->assertFalse(Decimal::of('0.00000001')->isZero());
        $this->assertTrue(Decimal::of('0.00000001')->isPositive());
        $this->assertFalse(Decimal::zero()->isPositive());
        $this->assertTrue(Decimal::of('-0.00000001')->isNegative());
        $this->assertFalse(Decimal::zero()->isNegative());
    }

    public function test_it_negates_and_takes_the_absolute_value(): void
    {
        $this->assertSame('-1.5', (string) Decimal::of('1.5')->negate());
        $this->assertSame('1.5', (string) Decimal::of('-1.5')->negate());
        $this->assertSame('0', (string) Decimal::zero()->negate());
        $this->assertSame('1.5', (string) Decimal::of('-1.5')->abs());
        $this->assertSame('1.5', (string) Decimal::of('1.5')->abs());
    }

    public function test_it_rounds_a_half_away_from_zero_where_floats_round_wrongly(): void
    {
        $this->assertSame(1.0, round(1.005 * 100) / 100);

        $this->assertSame('1.01', (string) Decimal::of('1.005')->round(2));
        $this->assertSame('2.68', (string) Decimal::of('2.675')->round(2));
        $this->assertSame('-1.01', (string) Decimal::of('-1.005')->round(2));
        $this->assertSame('1', (string) Decimal::of('1.004')->round(2));
        $this->assertSame('2', (string) Decimal::of('1.5')->round(0));
        $this->assertSame('-2', (string) Decimal::of('-1.5')->round(0));
        $this->assertSame('0.12345679', (string) Decimal::of('0.123456785')->round(8));
    }

    public function test_it_gives_a_fixed_number_of_places_for_storage(): void
    {
        $this->assertSame('1.50', Decimal::of('1.5')->toFixed(2));
        $this->assertSame('1.01', Decimal::of('1.005')->toFixed(2));
        $this->assertSame('0.10000000', Decimal::of('0.1')->toFixed(8));
        $this->assertSame('12', Decimal::of('12.4')->toFixed(0));
        $this->assertSame('-0.01', Decimal::of('-0.005')->toFixed(2));
    }

    public function test_a_negative_that_rounds_to_zero_has_no_sign(): void
    {
        $this->assertSame('0.00', Decimal::of('-0.004')->toFixed(2));
        $this->assertSame('0', (string) Decimal::of('-0.004')->round(2));
        $this->assertSame('0.00', Decimal::of('-0.004')->format(2));
        $this->assertTrue(Decimal::of('-0.004')->round(2)->isZero());
    }

    public function test_it_formats_for_reading(): void
    {
        $this->assertSame('1,234,567.89', Decimal::of('1234567.891')->format());
        $this->assertSame('-1,234.50', Decimal::of('-1234.5')->format(2));
        $this->assertSame('0.50', Decimal::of('0.5')->format(2));
        $this->assertSame('999.00', Decimal::of('999')->format(2));
        $this->assertSame('1,000', Decimal::of('999.5')->format(0));
        $this->assertSame('0.12345678', Decimal::of('0.12345678')->format(8));
        $this->assertSame('12,345,678,901,234,567,890.10', Decimal::of('12345678901234567890.1')->format(2));
    }

    public function test_it_formats_a_unit_price_with_the_places_a_small_one_needs(): void
    {
        $this->assertSame('151,300.00', Decimal::of('151300')->formatPrice());
        $this->assertSame('1.00', Decimal::of('1')->formatPrice());
        $this->assertSame('1.23', Decimal::of('1.23456')->formatPrice());
        $this->assertSame('0.52', Decimal::of('0.52')->formatPrice());
        $this->assertSame('0.50', Decimal::of('0.5')->formatPrice());
        $this->assertSame('0.00', Decimal::of('0')->formatPrice());
        $this->assertSame('0.666667', Decimal::of('0.666667')->formatPrice());
        $this->assertSame('0.00001234', Decimal::of('0.00001234')->formatPrice());
        $this->assertSame('0.33333333', Decimal::of('0.333333333333')->formatPrice());
        $this->assertSame('-0.125', Decimal::of('-0.125')->formatPrice());
    }

    public function test_it_is_a_string_in_json(): void
    {
        $this->assertSame('{"total":"1234.5"}', json_encode(['total' => Decimal::of('1234.50')]));
    }

    public function test_it_sums_a_list(): void
    {
        $this->assertSame('0.6', (string) Decimal::sum(['0.1', Decimal::of('0.2'), '0.3']));
        $this->assertSame('0', (string) Decimal::sum([]));
    }
}
