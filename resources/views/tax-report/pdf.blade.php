{{--
    The capital gains report as dompdf prints it: a summary page, then a page for each section.
    Plain HTML and its own small stylesheet, since dompdf reads neither the site's CSS nor any script.
    $report: the App\Tax\CgtReport. $user: whose it is. $generatedAt: when it was made.
--}}
@php
    use App\Support\Decimal;
    use App\Support\LocalTime;

    $summary = $report->summary;
    $year = $report->year;
    $estimated = $report->estimated();
    $money = fn (Decimal $amount) => ($amount->isNegative() ? '-' : '').'$'.$amount->abs()->format();
    $date = fn ($moment, string $format = 'j M Y') => LocalTime::of($moment, $user)->format($format);
    $columns = count($summary->sections) + 1;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Capital gains {{ $year->label() }}</title>
    <style>
        @page { margin: 36pt 36pt 54pt; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 9pt; color: #222; }
        h1 { font-size: 16pt; margin: 0 0 4pt; }
        h2 { font-size: 12pt; margin: 0 0 8pt; }
        p { margin: 0 0 8pt; }
        .meta { color: #555; margin-bottom: 14pt; }
        .meta td { padding: 1pt 12pt 1pt 0; }
        .note { color: #555; font-size: 8pt; }
        .warning { border: 1pt solid #b58900; background: #fdf6e3; padding: 6pt 8pt; margin-bottom: 12pt; }
        table.figures { width: 100%; border-collapse: collapse; margin-bottom: 12pt; }
        table.figures th, table.figures td { padding: 4pt 5pt; border-bottom: 0.5pt solid #ccc; text-align: left; vertical-align: top; }
        table.figures th { background: #eee; font-weight: bold; }
        table.figures .number { text-align: right; white-space: nowrap; }
        table.figures .whole { white-space: nowrap; }
        table.figures tr.total td { font-weight: bold; border-top: 1pt solid #222; }
        table.figures tr.result td { font-weight: bold; font-size: 11pt; }
        table.figures tr.estimated td { background: #fdf6e3; }
        .section { page-break-before: always; }
    </style>
</head>
<body>
    <h1>Capital gains report {{ $year->label() }}</h1>
    <table class="meta">
        <tr><td>For</td><td>{{ $user->name }} ({{ $user->email }})</td></tr>
        <tr><td>Financial year</td><td>1 July {{ $year->year - 1 }} to 30 June {{ $year->year }}</td></tr>
        <tr><td>Generated</td><td>{{ $date($generatedAt, 'j M Y, g:ia') }} ({{ $user->timezone }})</td></tr>
    </table>

    <p class="warning">
        <strong>A working paper, not tax advice.</strong> It is for you and your accountant to check. It covers capital gains only:
        staking rewards and airdrops are not totalled as income, and metals are not treated as collectables or personal use assets.
    </p>

    @if ($estimated > 0)
        <p class="warning">
            <strong>{{ $estimated }} {{ $estimated === 1 ? 'disposal' : 'disposals' }} used a parcel with an estimated cost</strong>
            and {{ $estimated === 1 ? 'is' : 'are' }} marked "Estimated". Replace the estimates with the real costs before relying on these figures.
        </p>
    @endif

    <h2>Summary</h2>
    <table class="figures">
        <thead>
            <tr>
                <th></th>
                @foreach ($summary->sections as $section)
                    <th class="number">{{ $section->section->label() }}</th>
                @endforeach
                <th class="number">Total</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Gains on assets held 12 months or less</td>
                @foreach ($summary->sections as $section)
                    <td class="number">{{ $money($section->shortGains) }}</td>
                @endforeach
                <td class="number">{{ $money($summary->shortGains) }}</td>
            </tr>
            <tr>
                <td>Gains on assets held longer than 12 months</td>
                @foreach ($summary->sections as $section)
                    <td class="number">{{ $money($section->longGains) }}</td>
                @endforeach
                <td class="number">{{ $money($summary->longGains) }}</td>
            </tr>
            <tr>
                <td>Losses</td>
                @foreach ($summary->sections as $section)
                    <td class="number">{{ $money($section->losses) }}</td>
                @endforeach
                <td class="number">{{ $money($summary->losses) }}</td>
            </tr>
            <tr class="total">
                <td colspan="{{ $columns }}">Total capital gains</td>
                <td class="number">{{ $money($summary->totalGains()) }}</td>
            </tr>
            <tr>
                <td colspan="{{ $columns }}">Losses this year</td>
                <td class="number">{{ $money($summary->losses) }}</td>
            </tr>
            <tr>
                <td colspan="{{ $columns }}">Losses carried forward from earlier years</td>
                <td class="number">{{ $money($summary->carriedForward) }}</td>
            </tr>
            <tr>
                <td colspan="{{ $columns }}">Losses applied (to the gains held 12 months or less first)</td>
                <td class="number">{{ $money($summary->lossesApplied) }}</td>
            </tr>
            <tr>
                <td colspan="{{ $columns }}">50% discount (on {{ $money($summary->longAfterLosses) }} of gains held longer than 12 months)</td>
                <td class="number">{{ $money($summary->discount) }}</td>
            </tr>
            <tr class="result">
                <td colspan="{{ $columns }}">Net capital gain</td>
                <td class="number">{{ $money($summary->netGain) }}</td>
            </tr>
            <tr>
                <td colspan="{{ $columns }}">Losses to carry forward to later years</td>
                <td class="number">{{ $money($summary->lossCarriedForward) }}</td>
            </tr>
        </tbody>
    </table>

    <p class="note">
        Each line of the sections that follow is one parcel's part of one sale. A parcel is held longer than 12 months when it is sold
        after the anniversary of the day it was acquired, by the calendar of {{ $user->timezone }}.
    </p>

    @foreach ($summary->sections as $section)
        @php
            $disposals = $report->disposals($section->section);
        @endphp
        <div class="section">
            <h2>{{ $section->section->label() }}: disposals in {{ $year->label() }}</h2>

            @if ($disposals->isEmpty())
                <p>Nothing was sold in this section in {{ $year->label() }}.</p>
            @else
                <table class="figures">
                    <thead>
                        <tr>
                            <th>Asset</th>
                            <th>Acquired</th>
                            <th>Sold</th>
                            <th class="number">Units</th>
                            <th class="number">Cost base</th>
                            <th class="number">Proceeds</th>
                            <th class="number">Gain or loss</th>
                            <th>Held</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($disposals as $disposal)
                            <tr @class(['estimated' => $disposal->parcel->estimated])>
                                <td>{{ $disposal->parcel->asset->name }}</td>
                                <td class="whole">{{ $date($disposal->parcel->acquired_at) }}</td>
                                <td class="whole">{{ $date($disposal->transaction->occurred_at) }}</td>
                                <td class="number">{{ $disposal->units }} {{ $disposal->parcel->asset->unit }}</td>
                                <td class="number">{{ $money($disposal->cost_base) }}</td>
                                <td class="number">{{ $money($disposal->proceeds) }}</td>
                                <td class="number">{{ $money($disposal->gain) }}</td>
                                <td class="whole">{{ $disposal->long_held ? 'Over 12 months' : '12 months or less' }}{{ $disposal->parcel->estimated ? ' · Estimated' : '' }}</td>
                            </tr>
                        @endforeach
                        <tr class="total">
                            <td colspan="4">{{ $section->disposals }} {{ $section->disposals === 1 ? 'disposal' : 'disposals' }}</td>
                            <td class="number">{{ $money(Decimal::sum($disposals->map->cost_base)) }}</td>
                            <td class="number">{{ $money(Decimal::sum($disposals->map->proceeds)) }}</td>
                            <td class="number">{{ $money($section->shortGains->add($section->longGains)->sub($section->losses)) }}</td>
                            <td></td>
                        </tr>
                    </tbody>
                </table>
            @endif
        </div>
    @endforeach
</body>
</html>
