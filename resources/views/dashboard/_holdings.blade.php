{{--
    What is held, by class, each class with its subtotal. Sent alone when htmx asks for
    #dashboard-holdings, which it does every minute, as the totals do.
    $holdings: the App\Ledger\Valuation of each asset with units held, by class value.
    $history: each asset's bids of the last 30 days, oldest first, by asset id.
    $hidden: how many holdings their owner has taken off the dashboard.
--}}
<div class="card" id="dashboard-holdings" hx-get="{{ route('dashboard') }}" hx-trigger="every 60s" hx-target="#dashboard-holdings" hx-swap="outerHTML">
    <div class="card-body">
        <h4 class="header-title mb-3">Holdings</h4>
        <div class="table-responsive">
            <table class="table table-hover table-centered mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Asset</th>
                        <th class="text-end">Units</th>
                        <th class="text-end">Price</th>
                        <th class="text-end">Value</th>
                        <th class="text-end">Cost base</th>
                        <th class="text-end">Gain</th>
                        <th class="text-end">30 days</th>
                    </tr>
                </thead>
                @forelse ($holdings as $class => $valuations)
                    @php
                        $subtotal = App\Ledger\PortfolioValue::of($valuations);
                    @endphp
                    <tbody>
                        <tr class="table-light">
                            <th colspan="7">{{ App\Enums\AssetClass::from($class)->label() }}</th>
                        </tr>
                        @foreach ($valuations as $valuation)
                            @php
                                $asset = $valuation->holding->asset;
                            @endphp
                            <tr>
                                <td>
                                    <a href="{{ route('assets.show', $asset) }}">{{ $asset->name }}</a>
                                    @unless ($asset->in_total)
                                        <span class="badge bg-light text-dark ms-1" title="Left out of the subtotal and the totals">Not in total</span>
                                    @endunless
                                </td>
                                <td class="text-end text-nowrap">{{ $valuation->holding->units }} {{ $asset->unit }}</td>
                                @if ($valuation->isPriced())
                                    <td class="text-end">${{ $valuation->price->bid->formatPrice() }}</td>
                                    <td class="text-end">${{ $valuation->value()->format() }}</td>
                                @else
                                    <td class="text-end text-muted" colspan="2">No price</td>
                                @endif
                                <td class="text-end">${{ $valuation->holding->costBase->format() }}</td>
                                <td class="text-end text-nowrap"><x-gain :value="$valuation->gain()" :percent="$valuation->gainPercent()" /></td>
                                <td class="text-end">
                                    <x-sparkline :amounts="$history->get($asset->id, [])" label="The price of {{ $asset->name }} over 30 days" />
                                </td>
                            </tr>
                        @endforeach
                        <tr class="fw-semibold">
                            <td colspan="3">{{ App\Enums\AssetClass::from($class)->label() }} subtotal</td>
                            <td class="text-end">${{ $subtotal->value()->format() }}</td>
                            <td class="text-end">${{ $subtotal->costBase->format() }}</td>
                            <td class="text-end text-nowrap"><x-gain :value="$subtotal->gain()" :percent="$subtotal->gainPercent()" /></td>
                            <td></td>
                        </tr>
                    </tbody>
                @empty
                    <tbody>
                        <tr>
                            <td colspan="7" class="text-muted">{{ $hidden > 0 ? 'Every holding is hidden from the dashboard.' : 'Nothing is held yet.' }}</td>
                        </tr>
                    </tbody>
                @endforelse
            </table>
        </div>

        @if ($hidden > 0)
            <div class="form-text mt-2">
                {{ $hidden === 1 ? '1 holding is' : $hidden.' holdings are' }} hidden from this list.
                {{ $hidden === 1 ? 'It still counts' : 'They still count' }} toward the totals where set to.
                <a href="{{ route('assets.index') }}">Change which are shown</a>.
            </div>
        @endif
    </div>
</div>
