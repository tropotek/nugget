{{-- $search: the App\Ledger\TransactionSearch in force. The filters swap #transaction-table by htmx on every change. --}}
<x-layouts.app title="Transactions">
    <x-slot:actions>
        <a href="{{ route('transactions.create', array_filter(['asset' => $search->assetId])) }}" class="btn btn-primary"><i class="ri-add-line me-1"></i>Add transaction</a>
    </x-slot:actions>

    <div class="card">
        <div class="card-body">
            <form method="GET" action="{{ route('transactions.index') }}" class="row g-2 align-items-end mb-3"
                hx-get="{{ route('transactions.index') }}" hx-trigger="change" hx-target="#transaction-table" hx-swap="outerHTML" hx-push-url="true">
                @unless ($search->sort === 'date' && $search->descending)
                    <input type="hidden" name="sort" value="{{ $search->sort }}">
                    <input type="hidden" name="dir" value="{{ $search->descending ? 'desc' : 'asc' }}">
                @endunless

                <div class="col-sm-6 col-lg-3">
                    <label for="asset" class="form-label">Asset</label>
                    <select id="asset" name="asset" class="form-select">
                        <option value="">All assets</option>
                        @foreach ($assets as $asset)
                            <option value="{{ $asset->id }}"{{ $asset->id === $search->assetId ? ' selected' : '' }}>{{ $asset->name }}{{ $asset->archived ? ' (archived)' : '' }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-sm-6 col-lg-2">
                    <label for="type" class="form-label">Type</label>
                    <select id="type" name="type" class="form-select">
                        <option value="">All types</option>
                        @foreach ($types as $type)
                            <option value="{{ $type->value }}"{{ $type === $search->type ? ' selected' : '' }}>{{ $type->label() }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-sm-6 col-lg-2">
                    <label for="source" class="form-label">Source</label>
                    <select id="source" name="source" class="form-select">
                        <option value="">All sources</option>
                        @foreach ($sources as $source)
                            <option value="{{ $source->value }}"{{ $source === $search->source ? ' selected' : '' }}>{{ $source->label() }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-sm-6 col-lg-2">
                    <label for="estimated" class="form-label">Estimated</label>
                    <select id="estimated" name="estimated" class="form-select">
                        <option value="">Either</option>
                        <option value="1"{{ $search->estimated === true ? ' selected' : '' }}>Estimated only</option>
                        <option value="0"{{ $search->estimated === false ? ' selected' : '' }}>Not estimated</option>
                    </select>
                </div>

                <div class="col-sm-6 col-lg-auto">
                    <label for="from" class="form-label">From</label>
                    <input type="date" id="from" name="from" value="{{ $search->from?->toDateString() }}" class="form-control">
                </div>

                <div class="col-sm-6 col-lg-auto">
                    <label for="to" class="form-label">To</label>
                    <input type="date" id="to" name="to" value="{{ $search->to?->toDateString() }}" class="form-control">
                </div>

                <div class="col-auto">
                    <button type="submit" class="btn btn-light">Filter</button>
                    <a href="{{ route('transactions.index') }}" class="btn btn-link">Clear</a>
                </div>
            </form>

            @include('transactions._table')
        </div>
    </div>
</x-layouts.app>
