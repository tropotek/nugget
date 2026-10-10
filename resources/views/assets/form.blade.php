{{--
    The add and edit form. $fixed: the asset has transactions, so its symbol and unit cannot change and it cannot be deleted.
    resources/js/asset-form.js shows only the fields of the chosen price source.
--}}
@php($pricedBy = old('priced_by', $asset->pricedBy()->value))

<x-layouts.app :title="$asset->exists ? 'Edit asset' : 'Add asset'">
    <div class="row">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-body">
                    <form method="POST" action="{{ $asset->exists ? route('assets.update', $asset) : route('assets.store') }}">
                        @csrf
                        @if ($asset->exists)
                            @method('PUT')
                        @endif

                        <div class="mb-3">
                            <label for="name" class="form-label">Name</label>
                            <input type="text" id="name" name="name" value="{{ old('name', $asset->name) }}" required
                                @class(['form-control', 'is-invalid' => $errors->has('name')])>
                            @error('name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="row">
                            <div class="col-sm-6 mb-3">
                                <label for="symbol" class="form-label">Symbol</label>
                                <input type="text" id="symbol" name="symbol" value="{{ old('symbol', $asset->symbol) }}"{{ $fixed ? ' disabled' : ' required' }}
                                    maxlength="32" @class(['form-control', 'is-invalid' => $errors->has('symbol')])>
                                @error('symbol')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-sm-6 mb-3">
                                <label for="unit" class="form-label">Unit</label>
                                <input type="text" id="unit" name="unit" value="{{ old('unit', $asset->unit) }}"{{ $fixed ? ' disabled' : ' required' }}
                                    maxlength="16" placeholder="BTC, oz, g" @class(['form-control', 'is-invalid' => $errors->has('unit')])>
                                @error('unit')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-12 form-text mt-n2 mb-3">
                                @if ($fixed)
                                    The symbol and the unit cannot change once the asset has transactions.
                                @else
                                    Assets with the same symbol and unit are the same thing held in two places, and a transfer moves it between them.
                                @endif
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="class" class="form-label">Class</label>
                            <select id="class" name="class" required @class(['form-select', 'is-invalid' => $errors->has('class')])>
                                @foreach ($classes as $class)
                                    <option value="{{ $class->value }}"{{ $class->value === old('class', $asset->class?->value) ? ' selected' : '' }}>{{ $class->label() }}</option>
                                @endforeach
                            </select>
                            @error('class')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @else
                                <div class="form-text">The tax report has a section for crypto and one for everything else.</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="priced_by" class="form-label">Priced by</label>
                            <select id="priced_by" name="priced_by" required data-priced-by-select
                                @class(['form-select', 'is-invalid' => $errors->has('priced_by')])>
                                @foreach ($sources as $source)
                                    <option value="{{ $source->value }}"{{ $source->value === $pricedBy ? ' selected' : '' }}>{{ $source->label() }}</option>
                                @endforeach
                            </select>
                            @error('priced_by')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="row" data-priced-by="exchange">
                            <div class="col-sm-6 mb-3">
                                <label for="exchange_id" class="form-label">Exchange</label>
                                <select id="exchange_id" name="exchange_id" @class(['form-select', 'is-invalid' => $errors->has('exchange_id')])>
                                    <option value="">{{ $exchanges->isEmpty() ? 'You have no exchanges' : 'Choose an exchange' }}</option>
                                    @foreach ($exchanges as $exchange)
                                        <option value="{{ $exchange->id }}"{{ $exchange->id === (int) old('exchange_id', $asset->exchange_id) ? ' selected' : '' }}>{{ $exchange->label }}</option>
                                    @endforeach
                                </select>
                                @error('exchange_id')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-sm-6 mb-3">
                                <label for="market_id" class="form-label">Market</label>
                                <input type="text" id="market_id" name="market_id" value="{{ old('market_id', $asset->market_id) }}"
                                    maxlength="64" placeholder="BTC-AUD" @class(['form-control', 'is-invalid' => $errors->has('market_id')])>
                                @error('market_id')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        <div class="mb-3" data-priced-by="feed">
                            <label for="feed_symbol" class="form-label">Feed symbol</label>
                            <input type="text" id="feed_symbol" name="feed_symbol" value="{{ old('feed_symbol', $asset->feed_symbol) }}"
                                maxlength="64" placeholder="XAU/AUD" @class(['form-control', 'is-invalid' => $errors->has('feed_symbol')])>
                            @error('feed_symbol')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <input type="hidden" name="in_total" value="0">
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="in_total" name="in_total" value="1"{{ old('in_total', $asset->in_total) ? ' checked' : '' }}>
                                <label class="form-check-label" for="in_total">Count toward the portfolio total</label>
                            </div>
                        </div>

                        @if ($asset->exists)
                            <div class="mb-3">
                                <input type="hidden" name="archived" value="0">
                                <div class="form-check form-switch">
                                    <input type="checkbox" class="form-check-input" id="archived" name="archived" value="1"{{ old('archived', $asset->archived) ? ' checked' : '' }}>
                                    <label class="form-check-label" for="archived">Archived</label>
                                </div>
                                <div class="form-text">An archived asset is left off the lists. Its transactions are kept.</div>
                            </div>
                        @endif

                        <div class="mb-3">
                            <label for="notes" class="form-label">Notes</label>
                            <textarea id="notes" name="notes" rows="3" @class(['form-control', 'is-invalid' => $errors->has('notes')])>{{ old('notes', $asset->notes) }}</textarea>
                            @error('notes')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <button type="submit" class="btn btn-primary">{{ $asset->exists ? 'Save' : 'Add asset' }}</button>
                        <a href="{{ route('assets.index') }}" class="btn btn-light">Cancel</a>
                    </form>
                </div>
            </div>

            @if ($asset->exists && ! $fixed)
                <div class="card">
                    <div class="card-body d-flex align-items-center justify-content-between">
                        <span class="text-muted">This asset has no transactions, so it can be deleted.</span>
                        <form method="POST" action="{{ route('assets.destroy', $asset) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-outline-danger">Delete asset</button>
                        </form>
                    </div>
                </div>
            @endif
        </div>
    </div>
</x-layouts.app>
