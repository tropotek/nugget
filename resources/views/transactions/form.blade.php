{{--
    The add and edit form.
    $locked: the transaction came from an exchange, so only its notes can change.
    $totalIsWorkedOut: the total is what the other amounts give, so its field is left empty.
    resources/js/transaction-form.js shows the fields of the chosen type (data-for-types) and previews the total.
--}}
@php
    $editing = $transaction->exists;
    $off = $locked ? ' disabled' : '';
    $forTypes = fn (string $has) => collect($types)->filter(fn ($type) => $type->{$has}())->map->value->implode(' ');
    $occurredAt = App\Support\LocalTime::of($transaction->occurred_at)->format('Y-m-d\TH:i:s');
@endphp

<x-layouts.app :title="$editing ? 'Edit transaction' : 'Add transaction'">
    <div class="row">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-body">
                    @error('ledger')
                        <div class="alert alert-danger" role="alert">{{ $message }}</div>
                    @enderror

                    @if ($locked)
                        <div class="alert alert-info" role="alert">This came from {{ $transaction->asset->exchange?->label ?? 'an exchange' }}, so only its notes can be changed.</div>
                    @endif

                    <form method="POST" action="{{ $editing ? route('transactions.update', $transaction) : route('transactions.store') }}" data-transaction-form>
                        @csrf
                        @if ($editing)
                            @method('PUT')
                        @endif

                        <div class="mb-3">
                            <label for="asset_id" class="form-label">Asset</label>
                            <select id="asset_id" name="asset_id"{{ $editing ? ' disabled' : ' required' }}
                                @class(['form-select', 'is-invalid' => $errors->has('asset_id')])>
                                @unless ($editing)
                                    <option value="">{{ $assets->isEmpty() ? 'You have no assets' : 'Choose an asset' }}</option>
                                @endunless
                                @foreach ($assets as $asset)
                                    <option value="{{ $asset->id }}"{{ $asset->id === (int) old('asset_id', $transaction->asset_id) ? ' selected' : '' }}>{{ $asset->name }} ({{ $asset->unit }})</option>
                                @endforeach
                            </select>
                            @error('asset_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="row">
                            <div class="col-sm-5 mb-3">
                                <label for="type" class="form-label">Type</label>
                                <select id="type" name="type" required{{ $off }} @class(['form-select', 'is-invalid' => $errors->has('type')])>
                                    @foreach ($types as $type)
                                        <option value="{{ $type->value }}"{{ $type->value === old('type', $transaction->type->value) ? ' selected' : '' }}>{{ $type->label() }}</option>
                                    @endforeach
                                </select>
                                @error('type')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-sm-7 mb-3">
                                <label for="occurred_at" class="form-label">Date and time</label>
                                <input type="datetime-local" id="occurred_at" name="occurred_at" value="{{ old('occurred_at', $occurredAt) }}" step="1" required{{ $off }}
                                    @class(['form-control', 'is-invalid' => $errors->has('occurred_at')])>
                                @error('occurred_at')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="units" class="form-label">Units</label>
                            <input type="text" inputmode="decimal" id="units" name="units" value="{{ old('units', $transaction->units) }}" required{{ $off }}
                                @class(['form-control', 'is-invalid' => $errors->has('units')])>
                            @error('units')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @else
                                <div class="form-text" data-for-types="adjust">A negative number takes units away.</div>
                            @enderror
                        </div>

                        <div class="row">
                            <div class="col-sm-6 mb-3" data-for-types="{{ $forTypes('hasPrice') }}">
                                <label for="unit_price" class="form-label">Unit price (AUD)</label>
                                <input type="text" inputmode="decimal" id="unit_price" name="unit_price" value="{{ old('unit_price', $editing ? $transaction->unit_price : '') }}"{{ $off }}
                                    @class(['form-control', 'is-invalid' => $errors->has('unit_price')])>
                                @error('unit_price')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @else
                                    <div class="form-text" data-for-types="reward">The market price on the day, or 0 for an airdrop.</div>
                                @enderror
                            </div>

                            <div class="col-sm-6 mb-3" data-for-types="{{ $forTypes('hasFee') }}">
                                <label for="fee" class="form-label">Fee (AUD)</label>
                                <input type="text" inputmode="decimal" id="fee" name="fee" value="{{ old('fee', $editing ? $transaction->fee->toFixed(2) : '') }}" placeholder="0.00"{{ $off }}
                                    @class(['form-control', 'is-invalid' => $errors->has('fee')])>
                                @error('fee')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        <div class="mb-3" data-for-types="{{ $forTypes('hasTotal') }}">
                            <label for="total" class="form-label">Total (AUD)</label>
                            <input type="text" inputmode="decimal" id="total" name="total" value="{{ old('total', $totalIsWorkedOut ? '' : $transaction->total->toFixed(2)) }}"{{ $off }}
                                @class(['form-control', 'is-invalid' => $errors->has('total')])>
                            @error('total')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @else
                                <div class="form-text">
                                    Leave it empty to have it worked out.
                                    <span data-for-types="buy">The cost with the fee.</span>
                                    <span data-for-types="sell">The proceeds less the fee.</span>
                                    <span data-for-types="reward">The market value on the day.</span>
                                    <span data-for-types="found adjust">The cost base of the units, if they had a cost.</span>
                                    <span data-for-types="transfer_in">The cost base of the units, if they did not come from another of your assets.</span>
                                </div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <input type="hidden" name="estimated" value="0"{{ $off }}>
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="estimated" name="estimated" value="1"{{ old('estimated', $transaction->estimated) ? ' checked' : '' }}{{ $off }}>
                                <label class="form-check-label" for="estimated">Estimated</label>
                            </div>
                            <div class="form-text">The amounts are a best guess. The tax report marks what rests on them.</div>
                        </div>

                        <div class="mb-3">
                            <label for="notes" class="form-label">Notes</label>
                            <textarea id="notes" name="notes" rows="3" @class(['form-control', 'is-invalid' => $errors->has('notes')])>{{ old('notes', $transaction->notes) }}</textarea>
                            @error('notes')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <button type="submit" class="btn btn-primary">{{ $editing ? 'Save' : 'Add transaction' }}</button>
                        <a href="{{ route('transactions.index') }}" class="btn btn-light">Cancel</a>
                    </form>
                </div>
            </div>

            @if ($editing && $transaction->type === App\Enums\TransactionType::Sell)
                <div class="card">
                    <div class="card-body d-flex align-items-center justify-content-between">
                        <span class="text-muted">Parcels matched {{ $transaction->isHandMatched() ? 'by hand' : 'by: '.($transaction->matching?->label() ?? 'nothing yet') }}.</span>
                        <a href="{{ route('transactions.parcels.edit', $transaction) }}" class="btn btn-light">Choose parcels</a>
                    </div>
                </div>
            @endif

            @if ($editing && ! $locked)
                <div class="card">
                    <div class="card-body d-flex align-items-center justify-content-between">
                        <span class="text-muted">Deleting it rebuilds the asset's parcels without it.</span>
                        <form method="POST" action="{{ route('transactions.destroy', $transaction) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-outline-danger">Delete transaction</button>
                        </form>
                    </div>
                </div>
            @endif
        </div>
    </div>
</x-layouts.app>
