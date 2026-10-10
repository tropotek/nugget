{{--
    The add and edit form. The saved key and secret are never put on the page: on an edit the
    fields are empty, and a blank one keeps what is saved.
    $drivers: App\Exchanges\ExchangeDrivers. $balances: what the exchange held at its last sync. $mismatches: the BalanceMismatch notifications it left. $usedByAssets: an asset is priced by it, so it cannot be deleted.
--}}
@php
    $names = $drivers->names();
    $driver = old('driver', $exchange->driver ?? array_key_first($names));
@endphp

<x-layouts.app :title="$exchange->exists ? 'Edit exchange' : 'Add exchange'">
    <div class="row">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-body">
                    <form method="POST" action="{{ $exchange->exists ? route('exchanges.update', $exchange) : route('exchanges.store') }}" autocomplete="off">
                        @csrf
                        @if ($exchange->exists)
                            @method('PUT')
                        @endif

                        <div class="mb-3">
                            <label for="driver" class="form-label">Exchange</label>
                            <select id="driver" name="driver"{{ $exchange->exists ? ' disabled' : ' required' }}
                                @class(['form-select', 'is-invalid' => $errors->has('driver')])>
                                @if ($exchange->exists && ! isset($names[$exchange->driver]))
                                    <option selected>{{ $exchange->driver }}</option>
                                @endif
                                @foreach ($names as $name => $label)
                                    <option value="{{ $name }}"{{ $name === $driver ? ' selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('driver')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @else
                                @if ($exchange->exists)
                                    <div class="form-text">The exchange of an account cannot be changed.</div>
                                @endif
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="label" class="form-label">Label</label>
                            <input type="text" id="label" name="label" value="{{ old('label', $exchange->label) }}" required
                                @class(['form-control', 'is-invalid' => $errors->has('label')])>
                            @error('label')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @else
                                <div class="form-text">Your own name for the account.</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="api_key" class="form-label">API key</label>
                            <input type="text" id="api_key" name="api_key" value="" autocomplete="off"{{ $exchange->exists ? '' : ' required' }}
                                @class(['form-control', 'is-invalid' => $errors->has('api_key')])>
                            @error('api_key')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @else
                                @if ($exchange->exists)
                                    <div class="form-text">{{ $exchange->api_key ? 'A key is saved. Leave this blank to keep it.' : 'No key is saved.' }}</div>
                                @else
                                    <div class="form-text">A read-only key is all Nugget needs: it never places an order.</div>
                                @endif
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="secret" class="form-label">Secret</label>
                            <input type="password" id="secret" name="secret" value="" autocomplete="new-password"{{ $exchange->exists ? '' : ' required' }}
                                @class(['form-control', 'is-invalid' => $errors->has('secret')])>
                            @error('secret')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @else
                                @if ($exchange->exists)
                                    <div class="form-text">{{ $exchange->secret ? 'A secret is saved and is not shown again. Leave this blank to keep it.' : 'No secret is saved.' }}</div>
                                @else
                                    <div class="form-text">Stored encrypted, and not shown again once saved.</div>
                                @endif
                            @enderror
                        </div>

                        <div class="mb-3">
                            <input type="hidden" name="active" value="0">
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="active" name="active" value="1"{{ old('active', $exchange->active ?? true) ? ' checked' : '' }}>
                                <label class="form-check-label" for="active">Active</label>
                            </div>
                            <div class="form-text">An inactive exchange is not synced. Its markets still price its assets.</div>
                        </div>

                        <button type="submit" class="btn btn-primary">{{ $exchange->exists ? 'Save' : 'Add exchange' }}</button>
                        <a href="{{ route('exchanges.index') }}" class="btn btn-light">Cancel</a>
                    </form>
                </div>
            </div>

            @if ($exchange->exists && ! $usedByAssets)
                <div class="card">
                    <div class="card-body d-flex align-items-center justify-content-between">
                        <span class="text-muted">No asset is priced by this exchange, so it can be deleted.</span>
                        <form method="POST" action="{{ route('exchanges.destroy', $exchange) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-outline-danger">Delete exchange</button>
                        </form>
                    </div>
                </div>
            @endif
        </div>

        @if ($exchange->exists)
            @php
                $capabilities = $drivers->capabilities($exchange->driver);
            @endphp
            <div class="col-lg-6">
                <div class="card" id="exchange-connection">
                    <div class="card-body">
                        <h4 class="header-title mb-3">Connection</h4>
                        <dl class="row mb-0">
                            <dt class="col-5">Nugget can read</dt>
                            <dd class="col-7">
                                @forelse ($capabilities as $capability)
                                    <span class="badge bg-light text-dark">{{ $capability }}</span>
                                @empty
                                    <span class="text-muted">Nothing: there is no driver called {{ $exchange->driver }}</span>
                                @endforelse
                            </dd>
                            <dt class="col-5">Last sync</dt>
                            <dd class="col-7">
                                @if ($exchange->last_synced_at)
                                    <x-date :value="$exchange->last_synced_at" />
                                @else
                                    <span class="text-muted">Never</span>
                                @endif
                            </dd>
                            <dt class="col-5">Last error</dt>
                            <dd class="col-7 {{ $exchange->last_error ? 'text-danger' : 'text-muted' }}">{{ $exchange->last_error ?? 'None' }}</dd>
                            @if ($exchange->last_sync_note)
                                <dt class="col-5">Skipped</dt>
                                <dd class="col-7">{{ $exchange->last_sync_note }}</dd>
                            @endif
                        </dl>

                        <div class="d-flex flex-wrap gap-2 mt-3">
                            <form method="POST" action="{{ route('exchanges.sync', $exchange) }}">
                                @csrf
                                <button type="submit" class="btn btn-primary"><i class="ri-refresh-line me-1"></i>Sync now</button>
                            </form>
                            @if ($drivers->can($exchange->driver, App\Exchanges\Contracts\ChecksConnection::class))
                                <form method="POST" action="{{ route('exchanges.test', $exchange) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-light"><i class="ri-plug-line me-1"></i>Test connection</button>
                                </form>
                            @endif
                        </div>
                        <div class="form-text">
                            Both use the saved key and secret: save a change to them first.
                            {{ $exchange->active ? 'An active exchange is also synced every 5 minutes.' : 'This exchange is inactive, so it is synced only from here.' }}
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <h4 class="header-title mb-3">Balances at the last sync</h4>
                        <div class="table-responsive">
                            <table class="table table-centered mb-0" id="exchange-balances">
                                <thead class="table-light">
                                    <tr>
                                        <th>Symbol</th>
                                        <th class="text-end">Balance</th>
                                        <th class="text-end">Available</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($balances as $balance)
                                        <tr>
                                            <td>{{ $balance->symbol }}</td>
                                            <td class="text-end">{{ $balance->units }}</td>
                                            <td class="text-end">{{ $balance->available }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="3" class="text-muted">{{ $exchange->last_synced_at ? 'The exchange held nothing.' : 'Not synced yet.' }}</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                        <div class="form-text mt-2">What the exchange said the account held. The ledger is not changed to match it.</div>
                    </div>
                </div>

                @if ($mismatches->isNotEmpty())
                    <div class="card border-warning" id="balance-mismatches">
                        <div class="card-body">
                            <h4 class="header-title mb-3">Not what the ledger says</h4>
                            <div class="table-responsive">
                                <table class="table table-centered mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Asset</th>
                                            <th class="text-end">Ledger</th>
                                            <th class="text-end">Exchange</th>
                                            <th class="text-end">Difference</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($mismatches as $mismatch)
                                            @php
                                                $data = $mismatch->data;
                                                $links = App\Notifications\BalanceMismatch::links($data);
                                            @endphp
                                            <tr>
                                                <td><a href="{{ route('assets.show', $data['asset_id']) }}">{{ $data['asset'] }}</a></td>
                                                <td class="text-end">{{ $data['ledger'] }}</td>
                                                <td class="text-end">{{ $data['on_exchange'] }}</td>
                                                <td class="text-end text-nowrap">{{ $data['difference'] }} {{ $data['unit'] }}</td>
                                                <td class="text-end text-nowrap">
                                                    <a href="{{ $links['transfer'] }}" class="btn btn-sm btn-light">{{ $links['transferLabel'] }}</a>
                                                    <a href="{{ $links['adjust'] }}" class="btn btn-sm btn-light">Adjust</a>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            <div class="form-text mt-2">Found at the last sync. Enter a transfer if the units came from or went to another holding, or an adjustment to correct the ledger.</div>
                        </div>
                    </div>
                @endif
            </div>
        @endif
    </div>
</x-layouts.app>
