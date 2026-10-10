<?php

namespace App\Backfill;

use App\Enums\TransactionType;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Reads a BTC Markets transaction report (the CSV from Account, Transaction
 * history) into ledger entries.
 *
 * The report is the account's own ledger, not a list of trades: every change
 * to a balance is a row, with the currency, the amount (negative when it
 * leaves) and the balance afterwards. A trade is the rows that share an
 * order's `referenceId` and a moment: the coin, what it was traded for, and
 * usually a "Trading Fee". An order filled in several goes has several such
 * moments, and each is one entry.
 *
 * What a row is worth is read from its amount alone, never from the wording
 * of its description, so the entries' units add up to the balance column.
 *
 * - A trade for AUD is a buy or a sell with its price, fee and total.
 * - A coin traded for another coin is two entries, a sell of what left and a
 *   buy of what arrived, with any fee in either coin taken off the units.
 *   Neither has an AUD total: the backfill values them.
 * - A coin deposit is a transfer in and a withdrawal a transfer out. AUD
 *   deposits and withdrawals are not ledger transactions.
 * - A reward and an airdrop are rewards.
 */
class BtcMarketsCsv
{
    /** Rows of one order this close together in time are one fill. */
    private const SAME_FILL_SECONDS = 2;

    public const COLUMNS = ['transactionId', 'creationTime', 'recordType', 'action', 'currency', 'amount', 'description', 'balance', 'referenceId'];

    /** @var list<Entry> oldest first */
    public array $entries = [];

    /** @var array<string, Decimal> the balance of each coin after the last row */
    public array $balances = [];

    /** @var list<string> what was odd about the file */
    public array $warnings = [];

    public int $rows = 0;

    public ?CarbonImmutable $startsAt = null;

    /** The moment of the last row: the report covers the account up to here. */
    public ?CarbonImmutable $endsAt = null;

    /**
     * @param  resource  $stream  the open CSV
     *
     * @throws RuntimeException when it is not a BTC Markets transaction report
     */
    public static function read($stream): self
    {
        $report = new self;
        $report->parse($stream);

        return $report;
    }

    /**
     * @param  resource  $stream
     */
    private function parse($stream): void
    {
        $header = fgetcsv($stream, null, ',', '"', '');
        $columns = array_map(fn (?string $column) => trim((string) $column, " \t\u{FEFF}"), $header ?: []);

        if (array_diff(self::COLUMNS, $columns) !== []) {
            throw new RuntimeException('This is not a BTC Markets transaction report: it has no '.implode(', ', array_diff(self::COLUMNS, $columns)).' column.');
        }

        /** @var array<string, array{at: CarbonImmutable, sides: array<string, Decimal>, fees: array<string, Decimal>, ids: array<string, string>, quote: ?string}> $trades by order and moment */
        $trades = [];
        /** @var array<string, list<string>> $byOrder the trade keys of each order, in the order they came */
        $byOrder = [];
        $running = [];
        $disagreed = [];
        $transfers = [];

        while (($line = fgetcsv($stream, null, ',', '"', '')) !== false) {
            if ($line === [null] || count($line) < count($columns)) {
                continue;
            }

            $row = array_map(fn (?string $value) => trim((string) $value), array_combine($columns, array_slice($line, 0, count($columns))));
            $this->rows++;

            $at = CarbonImmutable::parse($row['creationTime'])->utc();
            $currency = strtoupper($row['currency']);
            $amount = Decimal::of($row['amount']);

            $this->startsAt ??= $at;
            $this->endsAt = $at;

            // The balance column is the exchange's own running total: it says whether a row is missing.
            $running[$currency] = ($running[$currency] ?? Decimal::zero())->add($amount);

            if (! isset($disagreed[$currency]) && $running[$currency]->sub(Decimal::of($row['balance']))->abs()->greaterThan('0.00000001')) {
                $disagreed[$currency] = true;
                $this->warnings[] = "The {$currency} balance column first disagrees with the rows before it at transaction {$row['transactionId']} on {$at->toDateString()}: "
                    ."the rows add up to {$running[$currency]} and the report says {$row['balance']}. A row may be missing from the file.";
            }

            if ($row['recordType'] === 'Trade') {
                $key = $row['referenceId'].'|'.$at->toIso8601String();

                if (! isset($trades[$key])) {
                    $latest = isset($byOrder[$row['referenceId']]) ? end($byOrder[$row['referenceId']]) : null;

                    // A fee is sometimes stamped a moment after its fill, and so, now and then, is one side of
                    // a fill after the other. A fee goes with the latest fill of its order, and a side with
                    // that fill when it is no more than a couple of seconds old.
                    if ($latest !== null && ($row['action'] === 'Trading Fee' || $trades[$latest]['at']->diffInSeconds($at, true) <= self::SAME_FILL_SECONDS)) {
                        $key = $latest;
                    }
                }

                if (! isset($trades[$key])) {
                    $trades[$key] = ['at' => $at, 'sides' => [], 'fees' => [], 'ids' => [], 'quote' => null];
                    $byOrder[$row['referenceId']][] = $key;
                }

                $part = $row['action'] === 'Trading Fee' ? 'fees' : 'sides';
                $trades[$key][$part][$currency] = ($trades[$key][$part][$currency] ?? Decimal::zero())->add($amount);
                $trades[$key]['ids'][$currency] ??= $row['transactionId'];
                $trades[$key]['quote'] ??= preg_match('/@ ([A-Za-z0-9]+) /', $row['description'], $match) ? strtoupper($match[1]) : null;

                continue;
            }

            if ($currency !== 'AUD' && ! $amount->isZero()) {
                $transfers[] = $this->transfer($row, $currency, $amount, $at);
            }
        }

        foreach ($trades as $trade) {
            array_push($this->entries, ...$this->trade($trade));
        }

        array_push($this->entries, ...array_filter($transfers));

        usort($this->entries, fn (Entry $a, Entry $b) => [$a->at->getTimestamp(), (int) $a->id] <=> [$b->at->getTimestamp(), (int) $b->id]);

        unset($running['AUD']);
        ksort($running);
        $this->balances = $running;
    }

    /**
     * A row that is not a trade: a deposit, a withdrawal, a reward or an airdrop of a coin.
     *
     * @param  array<string, string>  $row
     */
    private function transfer(array $row, string $currency, Decimal $amount, CarbonImmutable $at): ?Entry
    {
        $type = match ($row['action']) {
            'Deposit' => TransactionType::TransferIn,
            'Withdraw' => TransactionType::TransferOut,
            'Reward', 'Airdrop' => TransactionType::Reward,
            default => null,
        };

        if ($type === null || $amount->isNegative() !== $type->removesUnits()) {
            $this->warnings[] = "Transaction {$row['transactionId']} ({$row['recordType']}, {$row['action']}, {$amount} {$currency}) was not understood and was passed over.";

            return null;
        }

        return new Entry($row['transactionId'], $currency, $type, $at, $amount->abs(), airdrop: $row['action'] === 'Airdrop');
    }

    /**
     * The entries of one fill of an order.
     *
     * @param  array{at: CarbonImmutable, sides: array<string, Decimal>, fees: array<string, Decimal>, ids: array<string, string>, quote: ?string}  $trade
     * @return list<Entry>
     */
    private function trade(array $trade): array
    {
        if (isset($trade['sides']['AUD'])) {
            return $this->forAud($trade);
        }

        // What each coin changed by, the fee in it included.
        $net = $trade['sides'];

        foreach ($trade['fees'] as $currency => $fee) {
            $net[$currency] = ($net[$currency] ?? Decimal::zero())->add($fee);
        }

        $out = array_filter($net, fn (Decimal $amount) => $amount->isNegative());
        $in = array_filter($net, fn (Decimal $amount) => $amount->isPositive());

        // A last crumb of an order can leave with nothing coming back: what it fetched rounds to no units at all.
        // It is a sale, or a purchase, for nothing.
        if (count($out) + count($in) === 1) {
            $symbol = array_key_first($out + $in);
            $units = ($out + $in)[$symbol];

            return [new Entry(
                $trade['ids'][$symbol], $symbol, $units->isNegative() ? TransactionType::Sell : TransactionType::Buy, $trade['at'], $units->abs(),
                unitPrice: Decimal::zero(), fee: Decimal::zero(), total: Decimal::zero(),
            )];
        }

        if (count($out) !== 1 || count($in) !== 1) {
            $this->warnings[] = 'The trade at '.$trade['at']->toDateTimeString().' in '.implode(' and ', array_keys($net)).' is not one coin for another and was passed over.';

            return [];
        }

        [$sold, $bought] = [array_key_first($out), array_key_first($in)];

        return [
            new Entry($trade['ids'][$sold], $sold, TransactionType::Sell, $trade['at'], $out[$sold]->abs(), pairSymbol: $bought, pairUnits: $in[$bought]),
            new Entry($trade['ids'][$bought], $bought, TransactionType::Buy, $trade['at'], $in[$bought], pairSymbol: $sold, pairUnits: $out[$sold]->abs()),
        ];
    }

    /**
     * A fill on an AUD market: one coin against AUD, with the fee in AUD.
     *
     * @param  array{at: CarbonImmutable, sides: array<string, Decimal>, fees: array<string, Decimal>, ids: array<string, string>, quote: ?string}  $trade
     * @return list<Entry>
     */
    private function forAud(array $trade): array
    {
        $coins = array_diff(array_keys($trade['sides']), ['AUD']);
        $coin = reset($coins);

        if (count($coins) !== 1 || $trade['sides'][$coin]->isZero()) {
            $this->warnings[] = 'The AUD trade at '.$trade['at']->toDateTimeString().' does not have one coin on its other side and was passed over.';

            return [];
        }

        $traded = $trade['sides'][$coin];
        $type = $traded->isPositive() ? TransactionType::Buy : TransactionType::Sell;
        $gross = $trade['sides']['AUD']->abs();
        $fee = ($trade['fees']['AUD'] ?? Decimal::zero())->abs()->round(Decimal::AUD_SCALE);
        // A fee charged in the coin itself comes off the units.
        $units = $traded->add($trade['fees'][$coin] ?? Decimal::zero())->abs();

        return [new Entry(
            $trade['ids'][$coin],
            $coin,
            $type,
            $trade['at'],
            $units,
            unitPrice: $gross->div($traded->abs(), Decimal::PRICE_SCALE),
            fee: $fee,
            total: ($type === TransactionType::Buy ? $gross->add($fee) : $gross->sub($fee))->round(Decimal::AUD_SCALE),
        )];
    }
}
