{{--
    The transaction table and its paging. Sent alone when htmx asks for #transaction-table.
    Its sort and paging links are boosted: they swap this element and push the URL.
--}}
@php
    $columns = ['date' => 'Date', 'asset' => 'Asset', 'type' => 'Type', 'units' => 'Units', 'total' => 'Total'];

    $heading = function (string $sort) use ($search, $columns) {
        $arrow = $search->sort !== $sort ? '' : ($search->descending ? 'ri-arrow-down-s-line' : 'ri-arrow-up-s-line');

        return '<a href="'.e(route('transactions.index', $search->sortedBy($sort))).'" class="text-reset text-nowrap">'
            .e($columns[$sort]).($arrow ? ' <i class="'.$arrow.'"></i>' : '').'</a>';
    };
@endphp

<div id="transaction-table" hx-boost="true" hx-target="#transaction-table" hx-swap="outerHTML" hx-push-url="true">
    {{-- The order, as fields of the filter form, so that a change of filter keeps it. --}}
    @unless ($search->sort === 'date' && $search->descending)
        <input type="hidden" name="sort" value="{{ $search->sort }}" form="transaction-filters">
        <input type="hidden" name="dir" value="{{ $search->descending ? 'desc' : 'asc' }}" form="transaction-filters">
    @endunless

    <div class="table-responsive">
        <table class="table table-hover table-centered mb-0">
            <thead class="table-light">
                <tr>
                    <th>{!! $heading('date') !!}</th>
                    <th>{!! $heading('asset') !!}</th>
                    <th>{!! $heading('type') !!}</th>
                    <th class="text-end">{!! $heading('units') !!}</th>
                    <th class="text-end">Unit price</th>
                    <th class="text-end">Fee</th>
                    <th class="text-end">{!! $heading('total') !!}</th>
                    <th>Source</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($transactions as $transaction)
                    <tr @class(['table-warning' => $transaction->estimated])>
                        <td class="text-nowrap"><x-date :value="$transaction->occurred_at" /></td>
                        <td>{{ $transaction->asset->name }}</td>
                        <td class="text-nowrap">
                            {{ $transaction->type->label() }}
                            @if ($transaction->estimated)
                                <span class="badge bg-warning ms-1" title="The amounts are a best guess">Estimated</span>
                            @endif
                        </td>
                        <td class="text-end text-nowrap">{{ $transaction->signedUnits() }} {{ $transaction->asset->unit }}</td>
                        <td class="text-end">{{ $transaction->type->hasPrice() ? '$'.$transaction->unit_price->formatPrice() : '' }}</td>
                        <td class="text-end">{{ $transaction->type->hasFee() ? '$'.$transaction->fee->format() : '' }}</td>
                        <td class="text-end">{{ $transaction->type->hasTotal() ? '$'.$transaction->total->format() : '' }}</td>
                        <td>{{ $transaction->source->label() }}</td>
                        <td class="text-end text-nowrap">
                            {{-- Full page loads, not swaps of the table. --}}
                            @if ($transaction->type === App\Enums\TransactionType::Sell)
                                <a href="{{ route('transactions.parcels.edit', $transaction) }}" hx-boost="false" class="btn btn-sm btn-light">Parcels</a>
                            @endif
                            <a href="{{ route('transactions.edit', $transaction) }}" hx-boost="false" class="btn btn-sm btn-light">Edit</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="text-muted">{{ $search->isFiltered() ? 'No transactions match.' : 'No transactions yet.' }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($transactions->hasPages())
        <div class="mt-3">{{ $transactions->links() }}</div>
    @endif
</div>
