{{--
    The parcels a sale uses. $choices: an App\Ledger\ParcelChoice per parcel the sale can be matched to.
    resources/js/parcel-picker.js keeps the gains and the footer in step with what is typed.
--}}
@php
    $unit = $sale->asset->unit;
    $money = fn (App\Support\Decimal $amount) => ($amount->isNegative() ? '-$' : '$').$amount->abs()->format();
    // After a refused save every field shows what was typed, an emptied one included. Otherwise, what the sale uses now.
    $typed = fn (App\Ledger\ParcelChoice $choice) => is_array(old('picks'))
        ? (is_string($was = old("picks.{$choice->key}")) ? $was : '')
        : ($choice->picked->isZero() ? '' : (string) $choice->picked);
    $isAmount = fn (mixed $value) => is_string($value) && preg_match('/^\d+(\.\d+)?$/D', $value);
@endphp

<x-layouts.app title="Parcels for a sale">
    <x-slot:actions>
        <a href="{{ route('transactions.edit', $sale) }}" class="btn btn-light">Edit the sale</a>
        <a href="{{ route('transactions.index') }}" class="btn btn-light">Transactions</a>
    </x-slot:actions>

    <div class="card">
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-3 col-lg-2">Sale</dt>
                <dd class="col-sm-9 col-lg-10">{{ $sale->units }} {{ $unit }} of {{ $sale->asset->name }} on <x-date :value="$sale->occurred_at" /></dd>
                <dt class="col-sm-3 col-lg-2">Proceeds</dt>
                <dd class="col-sm-9 col-lg-10">{{ $money($sale->total) }}</dd>
                <dt class="col-sm-3 col-lg-2">Matched</dt>
                <dd class="col-sm-9 col-lg-10 mb-0">
                    @if ($sale->isHandMatched())
                        By hand
                    @else
                        {{ $sale->matching?->label() ?? 'Not yet' }}
                    @endif
                </dd>
            </dl>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            @error('picks')
                <div class="alert alert-danger" role="alert">{{ $message }}</div>
            @enderror

            <form method="POST" action="{{ route('transactions.parcels.update', $sale) }}" id="parcel-picker"
                data-parcel-picker data-sale-units="{{ $sale->units }}" data-sale-total="{{ $sale->total->toFixed(2) }}">
                @csrf
                @method('PUT')

                <div class="table-responsive">
                    <table class="table table-centered mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Acquired</th>
                                <th class="text-end">Units left</th>
                                <th class="text-end">Cost base</th>
                                <th class="text-end">Gain per unit</th>
                                <th></th>
                                <th style="width: 14rem">Units to use</th>
                                <th class="text-end">Gain</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($choices as $choice)
                                @php($value = $typed($choice))
                                <tr data-choice data-units="{{ $choice->units }}" data-cost="{{ $choice->costBase->toFixed(2) }}">
                                    <td class="text-nowrap"><x-date :value="$choice->acquiredAt" format="j M Y" /></td>
                                    <td class="text-end text-nowrap">{{ $choice->units }} {{ $unit }}</td>
                                    <td class="text-end">{{ $money($choice->costBase) }}</td>
                                    <td class="text-end">{{ $money($choice->gainPerUnit($sale)) }}</td>
                                    <td class="text-nowrap">
                                        @if ($choice->longHeld)
                                            <span class="badge bg-success" title="Held longer than 12 months at the sale, so the discount applies">12 months</span>
                                        @endif
                                        @if ($choice->estimated)
                                            <span class="badge bg-warning" title="The cost is a best guess">Estimated</span>
                                        @endif
                                    </td>
                                    <td>
                                        <input type="text" inputmode="decimal" name="picks[{{ $choice->key }}]" value="{{ $value }}" placeholder="0"
                                            aria-label="Units to use of the parcel acquired {{ App\Support\LocalTime::of($choice->acquiredAt)->format('j M Y') }}"
                                            @class(['form-control', 'is-invalid' => $errors->has("picks.{$choice->key}")])>
                                        @error("picks.{$choice->key}")
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </td>
                                    <td class="text-end" data-gain>{{ $isAmount($value) ? $money($choice->gainOn(App\Support\Decimal::of($value), $sale)) : '' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-muted">Nothing was held at the time of this sale.</td>
                                </tr>
                            @endforelse
                        </tbody>
                        <tfoot>
                            <tr>
                                <th colspan="5" class="text-end">Picked</th>
                                <th><span data-picked>{{ App\Support\Decimal::sum($choices->map(fn ($choice) => $isAmount($typed($choice)) ? $typed($choice) : '0')) }}</span> of {{ $sale->units }} {{ $unit }}</th>
                                <th class="text-end" data-total-gain>{{ $money(App\Support\Decimal::sum($choices->map(fn ($choice) => $isAmount($typed($choice)) ? $choice->gainOn(App\Support\Decimal::of($typed($choice)), $sale) : '0'))) }}</th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </form>

            <div class="d-flex gap-2 mt-3">
                <button type="submit" form="parcel-picker" class="btn btn-primary">Use these parcels</button>

                @if ($sale->isHandMatched())
                    <form method="POST" action="{{ route('transactions.parcels.destroy', $sale) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-light">Use default</button>
                    </form>
                @endif
            </div>

            <div class="form-text mt-2">
                The units picked must add up to the units sold. The gains shown are a guide: the saved split can differ by a cent.
                @if ($sale->isHandMatched())
                    "Use default" matches the sale by the method in the settings.
                @endif
            </div>
        </div>
    </div>
</x-layouts.app>
