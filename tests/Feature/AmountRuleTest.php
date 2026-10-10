<?php

namespace Tests\Feature;

use App\Rules\Amount;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AmountRuleTest extends TestCase
{
    private function passes(mixed $value, Amount $rule): bool
    {
        return Validator::make(['amount' => $value], ['amount' => [$rule]])->passes();
    }

    /**
     * @return array<string, array{mixed, bool}>
     */
    public static function amounts(): array
    {
        return [
            'a whole number' => ['12', true],
            'an integer' => [12, true],
            'two places' => ['12.34', true],
            'fewer places' => ['12.3', true],
            'zero' => ['0', true],
            'too many places' => ['12.345', false],
            'too many places, all zero' => ['12.340', false],
            'negative' => ['-1', false],
            'a float' => [12.34, false],
            'thousands separators' => ['1,234.50', false],
            'a dollar sign' => ['$12', false],
            'an exponent' => ['1e3', false],
            'no digits before the point' => ['.5', false],
            'no digits after the point' => ['5.', false],
            'words' => ['twelve', false],
            'an array' => [['12'], false],
        ];
    }

    #[DataProvider('amounts')]
    public function test_it_takes_a_plain_decimal_within_its_places(mixed $value, bool $passes): void
    {
        $this->assertSame($passes, $this->passes($value, new Amount(2)));
    }

    public function test_a_signed_amount_may_be_negative(): void
    {
        $this->assertTrue($this->passes('-0.5', new Amount(8, signed: true)));
        $this->assertFalse($this->passes('-0.5', new Amount(8)));
    }

    public function test_zero_can_be_refused(): void
    {
        $this->assertFalse($this->passes('0', new Amount(8, zero: false)));
        $this->assertFalse($this->passes('0.00', new Amount(8, zero: false)));
        $this->assertFalse($this->passes('-0', new Amount(8, signed: true, zero: false)));
        $this->assertTrue($this->passes('0.00000001', new Amount(8, zero: false)));
    }

    public function test_the_messages_name_the_field(): void
    {
        $errors = fn (string $value) => Validator::make(['fee' => $value], ['fee' => [new Amount(2)]])->errors()->first('fee');

        $this->assertSame('The fee must be a plain number, such as 1234.5.', $errors('1,000'));
        $this->assertSame('The fee can have up to 2 decimal places.', $errors('1.234'));
        $this->assertSame('The fee cannot be negative.', $errors('-1'));
    }
}
