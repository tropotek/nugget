{{-- $holdings: an App\Ledger\Holding per asset, in name order. --}}
<x-layouts.app title="Assets">
    <x-slot:actions>
        <a href="{{ route('assets.create') }}" class="btn btn-primary"><i class="ri-add-line me-1"></i>Add asset</a>
    </x-slot:actions>

    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover table-centered mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Asset</th>
                            <th>Class</th>
                            <th class="text-end">Units held</th>
                            <th class="text-end">Cost base</th>
                            <th>Priced by</th>
                            <th></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($holdings as $holding)
                            @php($asset = $holding->asset)
                            <tr>
                                <td>
                                    <a href="{{ route('assets.show', $asset) }}">{{ $asset->name }}</a>
                                    <span class="text-muted ms-1">{{ $asset->symbol }}</span>
                                </td>
                                <td>{{ $asset->class->label() }}</td>
                                <td class="text-end">{{ $holding->units }} {{ $asset->unit }}</td>
                                <td class="text-end">
                                    @if ($holding->estimated)
                                        <span class="badge bg-warning me-1" title="An open parcel has an estimated cost">Estimated</span>
                                    @endif
                                    ${{ $holding->costBase->format() }}
                                </td>
                                <td>
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
                                </td>
                                <td>
                                    @if ($asset->archived)
                                        <span class="badge bg-secondary">Archived</span>
                                    @endif
                                    @unless ($asset->in_total)
                                        <span class="badge bg-light text-dark">Not in total</span>
                                    @endunless
                                </td>
                                <td class="text-end text-nowrap">
                                    <a href="{{ route('transactions.create', ['asset' => $asset->id]) }}" class="btn btn-sm btn-light">Add transaction</a>
                                    <a href="{{ route('assets.edit', $asset) }}" class="btn btn-sm btn-light">Edit</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-muted">No assets yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($showArchived)
                <div class="mt-3"><a href="{{ route('assets.index') }}">Hide archived assets</a></div>
            @elseif ($archivedCount > 0)
                <div class="mt-3"><a href="{{ route('assets.index', ['archived' => 1]) }}">Show archived assets ({{ $archivedCount }})</a></div>
            @endif
        </div>
    </div>
</x-layouts.app>
