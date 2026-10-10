<?php

namespace App\Http\Requests;

use App\Enums\AssetClass;
use App\Enums\PricedBy;
use App\Models\Asset;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        $asset = $this->route('asset');

        return $asset ? $this->user()->can('update', $asset) : $this->user()->can('create', Asset::class);
    }

    /**
     * Symbols are stored in upper case, so the same coin in two places has the same one.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('symbol')) {
            $this->merge(['symbol' => Str::upper(trim((string) $this->input('symbol')))]);
        }
    }

    /**
     * Only the fields of the chosen price source are checked and kept.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $asset = $this->route('asset');
        $fixed = (bool) $asset?->hasTransactions();
        $exchange = 'exclude_unless:priced_by,'.PricedBy::Exchange->value;
        $feed = 'exclude_unless:priced_by,'.PricedBy::Feed->value;

        return [
            'name' => ['required', 'string', 'max:255'],
            'symbol' => [Rule::excludeIf($fixed), 'required', 'string', 'max:32'],
            'class' => ['required', Rule::enum(AssetClass::class)],
            'unit' => [Rule::excludeIf($fixed), 'required', 'string', 'max:16'],
            'priced_by' => ['required', Rule::enum(PricedBy::class)],
            'exchange_id' => [$exchange, 'required', 'integer', Rule::exists('exchanges', 'id')->where('user_id', $this->user()->id)],
            'market_id' => [$exchange, 'required', 'string', 'max:64'],
            'feed_symbol' => [$feed, 'required', 'string', 'max:64'],
            'in_total' => ['boolean'],
            'archived' => [Rule::excludeIf(! $asset), 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'exchange_id' => 'exchange',
            'market_id' => 'market',
        ];
    }

    /**
     * What to save on the asset. The fields of the price sources not chosen are emptied.
     *
     * @return array<string, mixed>
     */
    public function assetAttributes(): array
    {
        return [
            'exchange_id' => null,
            'market_id' => null,
            'feed_symbol' => null,
            ...$this->safe()->except('priced_by'),
        ];
    }
}
