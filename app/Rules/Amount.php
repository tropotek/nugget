<?php

namespace App\Rules;

use App\Support\Decimal;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A money or unit amount typed into a form: a plain decimal number with a
 * limit on its decimal places. It is read as a string and never as a float.
 */
class Amount implements ValidationRule
{
    public function __construct(
        private readonly int $places,
        private readonly bool $signed = false,
        private readonly bool $zero = true,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ((! is_string($value) && ! is_int($value)) || ! preg_match('/^-?\d{1,20}(\.\d+)?$/D', (string) $value)) {
            $fail('The :attribute must be a plain number, such as 1234.5.');

            return;
        }

        $value = (string) $value;

        if (strlen((string) strstr($value, '.')) > $this->places + 1) {
            $fail("The :attribute can have up to {$this->places} decimal places.");

            return;
        }

        $amount = Decimal::of($value);

        if ($amount->isNegative() && ! $this->signed) {
            $fail('The :attribute cannot be negative.');
        }

        if ($amount->isZero() && ! $this->zero) {
            $fail('The :attribute cannot be zero.');
        }
    }
}
