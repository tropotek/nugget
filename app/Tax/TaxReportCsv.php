<?php

namespace App\Tax;

use App\Enums\TaxSection;
use App\Models\User;
use App\Support\Decimal;
use App\Support\LocalTime;
use Generator;

/**
 * A capital gains report's disposal lines as CSV, for a spreadsheet or an
 * accountant's own working. It lists the lines of the CgtReport the page
 * shows, in the same order, and works nothing out.
 *
 * Dates are the user's own, as 2025-03-03. Amounts are plain numbers with no
 * dollar sign or thousands separator, a loss negative.
 */
class TaxReportCsv
{
    public const HEADINGS = [
        'Financial year', 'Section', 'Asset', 'Symbol', 'Class', 'Acquired', 'Sold', 'Units', 'Unit',
        'Cost base', 'Proceeds', 'Gain or loss', 'Held over 12 months', 'Discount applies', 'Estimated cost',
    ];

    /**
     * The heading row, then a row for each disposal: the crypto section first.
     *
     * @return Generator<int, list<string>>
     */
    public function rows(User $user, CgtReport $report): Generator
    {
        yield self::HEADINGS;

        $date = fn ($moment) => LocalTime::of($moment, $user)->format('Y-m-d');

        foreach (TaxSection::cases() as $section) {
            foreach ($report->disposals($section) as $disposal) {
                $asset = $disposal->parcel->asset;

                yield [
                    $report->year->label(),
                    $section->label(),
                    self::text($asset->name),
                    self::text($asset->symbol),
                    $asset->class->label(),
                    $date($disposal->parcel->acquired_at),
                    $date($disposal->transaction->occurred_at),
                    (string) $disposal->units,
                    self::text($asset->unit),
                    $disposal->cost_base->toFixed(Decimal::AUD_SCALE),
                    $disposal->proceeds->toFixed(Decimal::AUD_SCALE),
                    $disposal->gain->toFixed(Decimal::AUD_SCALE),
                    $disposal->long_held ? 'Yes' : 'No',
                    $disposal->long_held && $disposal->gain->isPositive() ? 'Yes' : 'No',
                    $disposal->parcel->estimated ? 'Yes' : 'No',
                ];
            }
        }
    }

    /**
     * Write the rows to an open stream, a row at a time.
     *
     * @param  resource  $stream
     */
    public function write($stream, User $user, CgtReport $report): void
    {
        // A byte order mark, so that Excel reads the file as UTF-8: the year's label has a dash that is not ASCII.
        fwrite($stream, "\u{FEFF}");

        foreach ($this->rows($user, $report) as $row) {
            fputcsv($stream, $row, ',', '"', '');
        }
    }

    /**
     * A name the user typed, made safe for a spreadsheet: one that starts
     * like a formula is given a leading apostrophe, so it is read as text.
     */
    private static function text(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }
}
