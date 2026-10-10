<?php

namespace App\Http\Requests;

use App\Exchanges\ExchangeDrivers;
use App\Models\Exchange;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExchangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $exchange = $this->route('exchange');

        return $exchange ? $this->user()->can('update', $exchange) : $this->user()->can('create', Exchange::class);
    }

    /**
     * The driver is chosen once: an asset's market id belongs to it. A new
     * exchange needs its key and secret; on an edit a blank one keeps what
     * is saved.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $adding = ! $this->route('exchange');
        $needed = $adding ? 'required' : 'nullable';

        return [
            'driver' => [Rule::excludeIf(! $adding), 'required', 'string', Rule::in(array_keys(app(ExchangeDrivers::class)->names()))],
            'label' => ['required', 'string', 'max:255'],
            'api_key' => [$needed, 'string', 'max:255'],
            'secret' => [$needed, 'string', 'max:1000'],
            'active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'driver' => 'exchange',
            'api_key' => 'API key',
        ];
    }

    /**
     * What to save on the exchange. A key or a secret left blank is not in it.
     *
     * @return array<string, mixed>
     */
    public function exchangeAttributes(): array
    {
        return array_filter(
            $this->safe()->all(),
            fn (mixed $value, string $field) => $value !== null || ! in_array($field, ['api_key', 'secret'], true),
            ARRAY_FILTER_USE_BOTH,
        );
    }
}
