{{--
    One asset: what is held of it and what that is worth, its price chart, the parcels still
    open and its latest transactions.
    $valuation: its App\Ledger\Valuation, and $holding the App\Ledger\Holding in it.
    $history: the App\Ledger\TransactionSearch behind $transactions.
--}}
@php
    $user = auth()->user();
    $averageCost = $holding->averageCost();
@endphp

<x-layouts.app :title="$asset->name">
    <x-slot:actions>
        <a href="{{ route('transactions.create', ['asset' => $asset->id]) }}" class="btn btn-primary"><i class="ri-add-line me-1"></i>Add transaction</a>
        <a href="{{ route('transactions.index', $history->toQuery()) }}" class="btn btn-light">All transactions</a>
        <a href="{{ route('assets.edit', $asset) }}" class="btn btn-light">Edit</a>
    </x-slot:actions>

    <div class="row">
        <div class="col-sm-6 col-xl-3">
            <div class="card">
                <div class="card-body">
                    <h5 class="text-muted fw-normal mt-0">Units held</h5>
                    <h3 class="mb-0">{{ $holding->units }} <small class="text-muted">{{ $asset->unit }}</small></h3>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card">
                <div class="card-body">
                    <h5 class="text-muted fw-normal mt-0">Cost base</h5>
                    <h3 class="mb-0">
                        ${{ $holding->costBase->format() }}
                        @if ($holding->estimated)
                            <span class="badge bg-warning fs-6 align-middle" title="An open parcel has an estimated cost">Estimated</span>
                        @endif
                    </h3>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card">
                <div class="card-body">
                    <h5 class="text-muted fw-normal mt-0">Average cost</h5>
                    <h3 class="mb-0">
                        @if ($averageCost)
                            ${{ $averageCost->formatPrice() }} <small class="text-muted">per {{ $asset->unit }}</small>
                        @else
                            <span class="text-muted">None held</span>
                        @endif
                    </h3>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card">
                <div class="card-body">
                    <h5 class="text-muted fw-normal mt-0">Open parcels</h5>
                    <h3 class="mb-0">{{ $holding->openParcels }}</h3>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-sm-6 col-xl-4">
            <div class="card">
                <div class="card-body">
                    <h5 class="text-muted fw-normal mt-0">
                        Price
                        @if ($valuation->isPriced())
                            {{-- The day row is replaced at each fetch, so this is when the price was read. --}}
                            <small class="float-end">as at <x-date :value="$valuation->price->updated_at" /></small>
                        @endif
                    </h5>
                    <h3 class="mb-0">
                        @if ($valuation->isPriced())
                            ${{ $valuation->price->bid->formatPrice() }} <small class="text-muted">per {{ $asset->unit }}</small>
                        @else
                            <span class="text-muted">No price</span>
                        @endif
                    </h3>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-4">
            <div class="card">
                <div class="card-body">
                    <h5 class="text-muted fw-normal mt-0">Market value</h5>
                    <h3 class="mb-0">
                        @if ($valuation->isPriced())
                            ${{ $valuation->value()->format() }}
                        @else
                            <span class="text-muted">No price</span>
                        @endif
                    </h3>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-4">
            <div class="card">
                <div class="card-body">
                    <h5 class="text-muted fw-normal mt-0">Unrealised gain</h5>
                    <h3 class="mb-0">
                        @if ($valuation->isPriced())
                            <x-gain :value="$valuation->gain()" :percent="$valuation->gainPercent()" />
                        @else
                            <span class="text-muted">No price</span>
                        @endif
                    </h3>
                </div>
            </div>
        </div>
    </div>

    @if ($valuation->isPriced())
        @include('assets._chart')
    @endif

    <div class="row">
        <div class="col-xl-4">
            <div class="card">
                <div class="card-body">
                    <h4 class="header-title mb-3">Details</h4>
                    <dl class="row mb-0">
                        <dt class="col-5">Symbol</dt>
                        <dd class="col-7">{{ $asset->symbol }}</dd>
                        <dt class="col-5">Class</dt>
                        <dd class="col-7">{{ $asset->class->label() }}</dd>
                        <dt class="col-5">Priced by</dt>
                        <dd class="col-7">
                            @switch($asset->pricedBy())
                                @case(App\Enums\PricedBy::Exchange)
                                    {{ $asset->exchange->label }} <span class="text-muted ms-1">{{ $asset->market_id }}</span>
                                    @break
                                @case(App\Enums\PricedBy::Feed)
                                    Feed <span class="text-muted ms-1">{{ $asset->feed_symbol }}</span>
                                    @break
                                @default
                                    <span class="text-muted">No price</span>
                            @endswitch
                        </dd>
                        <dt class="col-5">In the total</dt>
                        <dd @class(['col-7', 'mb-0' => ! $asset->archived && ! $asset->notes])>{{ $asset->in_total ? 'Yes' : 'No' }}</dd>
                        @if ($asset->archived)
                            <dt class="col-5">Status</dt>
                            <dd @class(['col-7', 'mb-0' => ! $asset->notes])><span class="badge bg-secondary">Archived</span></dd>
                        @endif
                        @if ($asset->notes)
                            <dt class="col-5">Notes</dt>
                            <dd class="col-7 mb-0">{!! nl2br(e($asset->notes)) !!}</dd>
                        @endif
                    </dl>
                </div>
            </div>
        </div>

        <div class="col-xl-8">
            <div class="card">
                <div class="card-body">
                    <h4 class="header-title mb-3">Open parcels</h4>
                    <div class="table-responsive">
                        <table class="table table-centered mb-0" id="open-parcels">
                            <thead class="table-light">
                                <tr>
                                    <th>Acquired</th>
                                    <th class="text-end">Units</th>
                                    <th class="text-end">Units left</th>
                                    <th class="text-end">Cost base</th>
                                    <th class="text-end">Per {{ $asset->unit }}</th>
                                    <th></th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($parcels as $parcel)
                                    @php($cost = $parcel->remainingCostBase())
                                    <tr>
                                        <td class="text-nowrap"><x-date :value="$parcel->acquired_at" format="j M Y" /></td>
                                        <td class="text-end">{{ $parcel->units }}</td>
                                        <td class="text-end">{{ $parcel->units_remaining }}</td>
                                        <td class="text-end">${{ $cost->format() }}</td>
                                        <td class="text-end">${{ $cost->div($parcel->units_remaining, App\Support\Decimal::PRICE_SCALE)->formatPrice() }}</td>
                                        <td class="text-nowrap">
                                            @if (App\Ledger\HoldingPeriod::isLongHeld($parcel->acquired_at, now(), $user))
                                                <span class="badge bg-success" title="Held longer than 12 months, so the discount would apply to a sale today">12 months</span>
                                            @endif
                                            @if ($parcel->estimated)
                                                <span class="badge bg-warning" title="The cost is a best guess">Estimated</span>
                                            @endif
                                        </td>
                                        <td class="text-end">
                                            <a href="{{ route('transactions.edit', $parcel->transaction_id) }}" class="btn btn-sm btn-light">Source</a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="text-muted">No open parcels: nothing is held.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <h4 class="header-title mb-3">Transactions</h4>
            <div class="table-responsive">
                <table class="table table-hover table-centered mb-0" id="asset-history">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Type</th>
                            <th class="text-end">Units</th>
                            <th class="text-end">Unit price</th>
                            <th class="text-end">Fee</th>
                            <th class="text-end">Total</th>
                            <th>Source</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($transactions as $transaction)
                            <tr @class(['table-warning' => $transaction->estimated])>
                                <td class="text-nowrap"><x-date :value="$transaction->occurred_at" /></td>
                                <td class="text-nowrap">
                                    {{ $transaction->type->label() }}
                                    @if ($transaction->estimated)
                                        <span class="badge bg-warning ms-1" title="The amounts are a best guess">Estimated</span>
                                    @endif
                                </td>
                                <td class="text-end text-nowrap">{{ $transaction->signedUnits() }} {{ $asset->unit }}</td>
                                <td class="text-end">{{ $transaction->type->hasPrice() ? '$'.$transaction->unit_price->formatPrice() : '' }}</td>
                                <td class="text-end">{{ $transaction->type->hasFee() ? '$'.$transaction->fee->format() : '' }}</td>
                                <td class="text-end">{{ $transaction->type->hasTotal() ? '$'.$transaction->total->format() : '' }}</td>
                                <td>{{ $transaction->source->label() }}</td>
                                <td class="text-end text-nowrap">
                                    @if ($transaction->type === App\Enums\TransactionType::Sell)
                                        <a href="{{ route('transactions.parcels.edit', $transaction) }}" class="btn btn-sm btn-light">Parcels</a>
                                    @endif
                                    <a href="{{ route('transactions.edit', $transaction) }}" class="btn btn-sm btn-light">Edit</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-muted">No transactions yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($transactionCount > $transactions->count())
                <div class="mt-3">
                    <a href="{{ route('transactions.index', $history->toQuery()) }}">All {{ $transactionCount }} transactions</a>
                </div>
            @endif
        </div>
    </div>
</x-layouts.app>
