{{--
    The capital gains report for one financial year.
    $report: the App\Tax\CgtReport. $years: the open App\Ledger\ReportYears, for the picker.
    $closed: the labels of the closed years with sales. $broughtForward: what the year before leaves
    to carry forward, or null when that year is closed.
--}}
@php
    $summary = $report->summary;
    $estimated = $report->estimated();
    $leftOut = collect($report->leftOut())->map->label();
    $money = fn (App\Support\Decimal $amount) => ($amount->isNegative() ? '-' : '').'$'.$amount->abs()->format();
@endphp

<x-layouts.app :title="'Tax report '.$year->label()">
    <x-slot:actions>
        <a href="{{ route('tax-report.pdf', $year->year) }}" class="btn btn-light"><i class="ri-file-pdf-line me-1"></i>PDF</a>
        <div class="btn-group" role="group" aria-label="Financial year">
            @foreach ($years as $choice)
                <a href="{{ route('tax-report.show', $choice->year->year) }}" @class(['btn', 'btn-primary' => $choice->year->year === $year->year, 'btn-light' => $choice->year->year !== $year->year])>{{ $choice->year->label() }}</a>
            @endforeach
        </div>
    </x-slot:actions>

    @if ($leftOut->isNotEmpty())
        <div class="alert alert-info" role="alert" id="left-out-notice">
            <strong>{{ $leftOut->join(', ', ' and ') }} {{ $leftOut->count() === 1 ? 'is' : 'are' }} left out of this report.</strong>
            {{ $leftOut->count() === 1 ? 'Its' : 'Their' }} sales are in neither the sections nor the summary. Change that under "Report options".
        </div>
    @endif

    @if ($estimated > 0)
        <div class="alert alert-warning" role="alert" id="estimated-warning">
            <strong>{{ $estimated }} {{ $estimated === 1 ? 'disposal' : 'disposals' }} in this report used a parcel with an estimated cost.</strong>
            {{ $estimated === 1 ? 'It is' : 'They are' }} marked below. Replace the estimates with the real costs before relying on these figures.
        </div>
    @endif

    <div class="row">
        <div class="col-xl-7">
            <div class="card">
                <div class="card-body">
                    <h4 class="header-title mb-3">Summary</h4>
                    <div class="table-responsive">
                        <table class="table table-centered mb-0" id="tax-summary">
                            <thead class="table-light">
                                <tr>
                                    <th></th>
                                    @foreach ($summary->sections as $section)
                                        <th class="text-end">{{ $section->section->label() }}</th>
                                    @endforeach
                                    <th class="text-end">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>Gains on assets held 12 months or less</td>
                                    @foreach ($summary->sections as $section)
                                        <td class="text-end">{{ $money($section->shortGains) }}</td>
                                    @endforeach
                                    <td class="text-end">{{ $money($summary->shortGains) }}</td>
                                </tr>
                                <tr>
                                    <td>Gains on assets held longer than 12 months</td>
                                    @foreach ($summary->sections as $section)
                                        <td class="text-end">{{ $money($section->longGains) }}</td>
                                    @endforeach
                                    <td class="text-end">{{ $money($summary->longGains) }}</td>
                                </tr>
                                <tr>
                                    <td>Losses</td>
                                    @foreach ($summary->sections as $section)
                                        <td class="text-end">{{ $money($section->losses) }}</td>
                                    @endforeach
                                    <td class="text-end">{{ $money($summary->losses) }}</td>
                                </tr>
                            </tbody>
                            <tbody>
                                <tr>
                                    <td colspan="{{ count($summary->sections) + 1 }}">Total capital gains</td>
                                    <td class="text-end">{{ $money($summary->totalGains()) }}</td>
                                </tr>
                                <tr>
                                    <td colspan="{{ count($summary->sections) + 1 }}">Losses this year</td>
                                    <td class="text-end">{{ $money($summary->losses) }}</td>
                                </tr>
                                <tr>
                                    <td colspan="{{ count($summary->sections) + 1 }}">Losses carried forward from earlier years</td>
                                    <td class="text-end">{{ $money($summary->carriedForward) }}</td>
                                </tr>
                                <tr>
                                    <td colspan="{{ count($summary->sections) + 1 }}">Losses applied <span class="text-muted">(to the gains held 12 months or less first)</span></td>
                                    <td class="text-end">{{ $money($summary->lossesApplied) }}</td>
                                </tr>
                                <tr>
                                    <td colspan="{{ count($summary->sections) + 1 }}">50% discount <span class="text-muted">(on {{ $money($summary->longAfterLosses) }} of gains held longer than 12 months)</span></td>
                                    <td class="text-end">{{ $money($summary->discount) }}</td>
                                </tr>
                                <tr class="fw-semibold fs-5">
                                    <td colspan="{{ count($summary->sections) + 1 }}">Net capital gain</td>
                                    <td class="text-end">{{ $money($summary->netGain) }}</td>
                                </tr>
                                <tr @class(['fw-semibold' => $summary->lossCarriedForward->isPositive()])>
                                    <td colspan="{{ count($summary->sections) + 1 }}">Losses to carry forward to later years</td>
                                    <td class="text-end">{{ $money($summary->lossCarriedForward) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-5">
            <div class="card">
                <div class="card-body">
                    <h4 class="header-title mb-3">Report options</h4>
                    <form method="POST" action="{{ route('tax-report.update', $year->year) }}" id="report-options">
                        @csrf
                        @method('PUT')

                        <div class="mb-3">
                            <label for="losses_carried_forward" class="form-label">Capital losses carried forward from earlier years, in AUD</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="text" inputmode="decimal" id="losses_carried_forward" name="losses_carried_forward" required
                                    value="{{ old('losses_carried_forward', $summary->carriedForward->toFixed(2)) }}"
                                    @class(['form-control', 'is-invalid' => $errors->has('losses_carried_forward')])>
                                @error('losses_carried_forward')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="form-text">
                                Net capital losses not yet used, as at the start of {{ $year->label() }}.
                                @if ($broughtForward !== null)
                                    The {{ $before->label() }} report leaves {{ $money($broughtForward) }} to carry forward.
                                @else
                                    {{ $before->label() }} was lodged outside Nugget: take the figure from that return.
                                @endif
                            </div>
                        </div>

                        <fieldset class="mb-3">
                            <legend class="form-label fs-6">Asset classes in the report</legend>
                            {{-- A blank goes with the boxes, so that none ticked is told from not sent. --}}
                            <input type="hidden" name="asset_classes[]" value="">
                            @php
                                $ticked = old('asset_classes', array_map(fn ($class) => $class->value, $report->classes));
                            @endphp
                            @foreach ($classes as $class)
                                <div class="form-check form-check-inline">
                                    <input type="checkbox" class="form-check-input" id="asset_class_{{ $class->value }}" name="asset_classes[]"
                                        value="{{ $class->value }}"{{ in_array($class->value, $ticked, true) ? ' checked' : '' }}>
                                    <label class="form-check-label" for="asset_class_{{ $class->value }}">{{ $class->label() }}</label>
                                </div>
                            @endforeach
                            @error('asset_classes')
                                <div class="text-danger small">{{ $message }}</div>
                            @enderror
                            @error('asset_classes.*')
                                <div class="text-danger small">{{ $message }}</div>
                            @enderror
                            <div class="form-text">
                                The sales of a class left out are in neither the sections nor the net capital gain. Metal and Other make up "Other assets".
                                A report takes in {{ collect(App\Models\TaxYear::defaultClasses())->map->label()->join(', ', ' and ') }} until you choose.
                            </div>
                        </fieldset>

                        <button type="submit" class="btn btn-primary">Save</button>
                        <span class="form-text ms-2">Saved for {{ $year->label() }} only. The PDF follows it.</span>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <p class="text-muted mb-0">
                        A working paper for you and your accountant, not tax advice. It covers capital gains only: staking rewards and airdrops are not
                        totalled as income, and metals are not treated as collectables or personal use assets.
                        @if ($closed)
                            {{ implode(', ', $closed) }} {{ count($closed) === 1 ? 'is' : 'are' }} closed and {{ count($closed) === 1 ? 'has' : 'have' }} no report.
                        @endif
                    </p>
                </div>
            </div>
        </div>
    </div>

    @foreach ($summary->sections as $section)
        @php
            $disposals = $report->disposals($section->section);
        @endphp
        <div class="card">
            <div class="card-body">
                @php
                    $ofSection = collect($report->classes)->filter(fn ($class) => App\Enums\TaxSection::of($class) === $section->section);
                    $possible = collect($classes)->filter(fn ($class) => App\Enums\TaxSection::of($class) === $section->section);
                @endphp
                <h4 class="header-title mb-3">
                    {{ $section->section->label() }}
                    @if ($ofSection->isNotEmpty() && $ofSection->count() < $possible->count())
                        <small class="text-muted fw-normal">{{ $ofSection->map->label()->join(', ') }} only</small>
                    @endif
                </h4>
                <div class="table-responsive">
                    <table class="table table-hover table-centered mb-0" id="disposals-{{ $section->section->value }}">
                        <thead class="table-light">
                            <tr>
                                <th>Asset</th>
                                <th>Acquired</th>
                                <th>Sold</th>
                                <th class="text-end">Units</th>
                                <th class="text-end">Cost base</th>
                                <th class="text-end">Proceeds</th>
                                <th class="text-end">Gain or loss</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($disposals as $disposal)
                                @php
                                    $asset = $disposal->parcel->asset;
                                @endphp
                                <tr @class(['table-warning' => $disposal->parcel->estimated])>
                                    <td><a href="{{ route('assets.show', $asset) }}">{{ $asset->name }}</a></td>
                                    <td class="text-nowrap"><x-date :value="$disposal->parcel->acquired_at" format="j M Y" /></td>
                                    <td class="text-nowrap">
                                        <a href="{{ route('transactions.parcels.edit', $disposal->transaction_id) }}" title="The parcels this sale used"><x-date :value="$disposal->transaction->occurred_at" format="j M Y" /></a>
                                    </td>
                                    <td class="text-end text-nowrap">{{ $disposal->units }} {{ $asset->unit }}</td>
                                    <td class="text-end">${{ $disposal->cost_base->format() }}</td>
                                    <td class="text-end">${{ $disposal->proceeds->format() }}</td>
                                    <td class="text-end text-nowrap"><x-gain :value="$disposal->gain" /></td>
                                    <td class="text-nowrap">
                                        @if ($disposal->long_held && $disposal->gain->isPositive())
                                            <span class="badge bg-success" title="Held longer than 12 months, so the discount applies">Discount</span>
                                        @endif
                                        @if ($disposal->parcel->estimated)
                                            <span class="badge bg-warning" title="The cost of the parcel is a best guess">Estimated</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="text-muted">
                                        {{ $report->takesIn($section->section) ? "Nothing was sold in this section in {$year->label()}." : 'This section is left out of the report.' }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                        @if ($disposals->isNotEmpty())
                            <tfoot>
                                <tr class="fw-semibold">
                                    <td colspan="4">{{ $section->disposals }} {{ $section->disposals === 1 ? 'disposal' : 'disposals' }}</td>
                                    <td class="text-end">${{ App\Support\Decimal::sum($disposals->map->cost_base)->format() }}</td>
                                    <td class="text-end">${{ App\Support\Decimal::sum($disposals->map->proceeds)->format() }}</td>
                                    <td class="text-end text-nowrap"><x-gain :value="$section->shortGains->add($section->longGains)->sub($section->losses)" /></td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
            </div>
        </div>
    @endforeach
</x-layouts.app>
