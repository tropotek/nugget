<?php

namespace App\Http\Requests;

use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Rules\Amount;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class TransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $transaction = $this->route('transaction');

        return $transaction ? $this->user()->can('update', $transaction) : $this->user()->can('create', Transaction::class);
    }

    /**
     * Only the amounts the type carries are checked and kept. A transaction
     * from an exchange has nothing checked but its notes.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $notes = ['notes' => ['nullable', 'string', 'max:5000']];

        if ($this->route('transaction')?->isLocked()) {
            return $notes;
        }

        $type = $this->type();

        return [
            'asset_id' => [
                Rule::excludeIf((bool) $this->route('transaction')),
                'required', 'integer', Rule::exists('assets', 'id')->where('user_id', $this->user()->id),
            ],
            'type' => ['required', Rule::enum(TransactionType::class)],
            'occurred_at' => ['required', 'date_format:Y-m-d\TH:i:s,Y-m-d\TH:i'],
            'units' => ['required', new Amount(Decimal::UNITS_SCALE, signed: $type === TransactionType::Adjust, zero: false)],
            'unit_price' => [Rule::excludeIf(! $type?->hasPrice()), 'required', new Amount(Decimal::PRICE_SCALE)],
            'fee' => [Rule::excludeIf(! $type?->hasFee()), 'nullable', new Amount(Decimal::AUD_SCALE)],
            'total' => [Rule::excludeIf(! $type?->hasTotal()), 'nullable', new Amount(Decimal::AUD_SCALE)],
            'estimated' => ['boolean'],
            ...$notes,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'asset_id' => 'asset',
            'occurred_at' => 'date and time',
            'unit_price' => 'unit price',
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty() || $this->route('transaction')?->isLocked()) {
                return;
            }

            // Both are read before either error is added: the validated input cannot be read after.
            $inFuture = $this->occurredAt()->isFuture();
            $feeTooBig = $this->amounts()['total']->isNegative();

            if ($inFuture) {
                $validator->errors()->add('occurred_at', 'The date and time cannot be in the future.');
            }

            if ($feeTooBig) {
                $validator->errors()->add('fee', 'The fee is more than the proceeds.');
            }
        }];
    }

    /**
     * What to save on the transaction.
     *
     * @return array<string, mixed>
     */
    public function transactionAttributes(): array
    {
        if ($this->route('transaction')?->isLocked()) {
            return $this->safe()->only('notes');
        }

        return [
            'type' => $this->type(),
            'occurred_at' => $this->occurredAt(),
            ...$this->amounts(),
            'estimated' => $this->boolean('estimated'),
            'notes' => $this->validated('notes'),
        ];
    }

    private function type(): ?TransactionType
    {
        return TransactionType::tryFrom((string) $this->input('type'));
    }

    /**
     * The time typed in the user's timezone, in UTC.
     */
    private function occurredAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->validated('occurred_at'), $this->user()->timezone)->utc();
    }

    /**
     * The amounts the type carries, and zero for the rest. A total left empty
     * is worked out from the others.
     *
     * @return array{units: Decimal, unit_price: Decimal, fee: Decimal, total: Decimal}
     */
    private function amounts(): array
    {
        $type = $this->type();
        $given = $this->safe()->all();

        $units = Decimal::of($given['units']);
        $unitPrice = Decimal::of($given['unit_price'] ?? '0');
        $fee = Decimal::of($given['fee'] ?? '0');

        $total = match (true) {
            ! $type->hasTotal(), $units->isNegative() => Decimal::zero(),
            isset($given['total']) => Decimal::of($given['total']),
            default => Transaction::totalOf($type, $units, $unitPrice, $fee),
        };

        return ['units' => $units, 'unit_price' => $unitPrice, 'fee' => $fee, 'total' => $total];
    }
}
