<?php

namespace Tests\Feature\Backfill;

use App\Backfill\BtcMarketsCsv;
use App\Backfill\Entry;
use RuntimeException;
use Tests\TestCase;

/**
 * Reading a BTC Markets transaction report. The fixture is made up, in the
 * shape of a real one: its header has a space before each column but the first.
 */
class BtcMarketsCsvTest extends TestCase
{
    private function fixture(): BtcMarketsCsv
    {
        return BtcMarketsCsv::read(fopen(base_path('tests/Fixtures/btcmarkets/transaction-report.csv'), 'r'));
    }

    /**
     * @param  list<string>  $rows  without the header or the balance column's bookkeeping: each "time,recordType,action,currency,amount,description,balance,ref"
     */
    private function report(array $rows): BtcMarketsCsv
    {
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, "transactionId, creationTime, recordType, action, currency, amount, description, balance, referenceId\n");

        foreach ($rows as $number => $row) {
            fwrite($stream, (1000 + $number).",{$row}\n");
        }

        rewind($stream);

        return BtcMarketsCsv::read($stream);
    }

    /**
     * The entries as "id symbol type units @ price fee total", or "… for pairUnits pairSymbol" for a swap.
     *
     * @return list<string>
     */
    private function lines(BtcMarketsCsv $report): array
    {
        return array_map(fn (Entry $e) => trim("{$e->id} {$e->symbol} {$e->type->value} {$e->units}"
            .($e->total !== null ? " @ {$e->unitPrice} {$e->fee->toFixed(2)} {$e->total->toFixed(2)}" : '')
            .($e->isSwap() ? " for {$e->pairUnits} {$e->pairSymbol}" : '')
            .($e->airdrop ? ' airdrop' : '')), $report->entries);
    }

    public function test_it_reads_the_report_into_entries_oldest_first(): void
    {
        $report = $this->fixture();

        $this->assertSame([
            '900000003 BTC buy 0.1 @ 100000 85.00 10085.00',
            // An order filled in three goes, two of them in the same second: an entry for each moment.
            '900000006 ADA buy 400 @ 0.5 1.70 201.70',
            '900000009 ADA buy 600 @ 0.5 2.55 302.55',
            '900000014 BTC sell 0.02 @ 110000 18.70 2181.30',
            '900000017 XRP transfer_in 500',
            // A coin for a coin: a sell of one and a buy of the other, the fee off what arrived.
            '900000018 XRP sell 200 for 0.001983 BTC',
            '900000019 BTC buy 0.001983 for 200 XRP',
            '900000021 BTC sell 0.001 for 99.15 XRP',
            '900000022 XRP buy 99.15 for 0.001 BTC',
            '900000024 BTC transfer_out 0.03',
            '900000025 BTC transfer_in 0.01',
            '900000026 ALGO reward 2.5',
            '900000027 ETHW reward 0.5 airdrop',
            // No fee row: no fee.
            '900000030 BTC buy 0.05 @ 120000 0.00 6000.00',
        ], $this->lines($report));
    }

    public function test_it_says_what_the_report_covers_and_each_coins_final_balance(): void
    {
        $report = $this->fixture();

        $this->assertSame(30, $report->rows);
        $this->assertSame('2024-07-01 00:00:00', $report->startsAt->toDateTimeString());
        $this->assertSame('2025-04-01 08:00:00', $report->endsAt->toDateTimeString());
        // AUD is not a coin.
        $this->assertSame(
            ['ADA' => '1000', 'ALGO' => '2.5', 'BTC' => '0.110983', 'ETHW' => '0.5', 'XRP' => '399.15'],
            array_map('strval', $report->balances),
        );
        $this->assertSame([], $report->warnings);
    }

    public function test_the_entries_of_a_coin_add_up_to_its_balance(): void
    {
        $report = $this->fixture();
        $net = [];

        foreach ($report->entries as $entry) {
            $net[$entry->symbol] = bcadd($net[$entry->symbol] ?? '0', (string) $entry->signedUnits(), 8);
        }

        foreach ($report->balances as $symbol => $balance) {
            $this->assertSame(0, bccomp($net[$symbol], (string) $balance, 8), $symbol);
        }
    }

    public function test_an_entry_is_at_the_moment_of_its_rows_in_utc(): void
    {
        $this->assertSame('2024-07-02T01:00:00+00:00', $this->fixture()->entries[0]->at->toIso8601String());
    }

    public function test_aud_deposits_and_withdrawals_are_not_entries(): void
    {
        $report = $this->report([
            '2024-07-01T00:00:00Z,Fund Transfer,Deposit,AUD,500,EFT,500,R1',
            '2024-07-02T00:00:00Z,Fund Transfer,Withdraw,AUD,-200,EFT,300,R2',
        ]);

        $this->assertSame([], $report->entries);
        $this->assertSame([], $report->balances);
        $this->assertSame(2, $report->rows);
    }

    public function test_a_fee_in_the_coin_of_an_aud_trade_comes_off_the_units(): void
    {
        $report = $this->report([
            '2024-07-02T01:00:00Z,Trade,Buy Order,AUD,-1000,Buy 10LTC @ AUD 100 Fully matched,-1000,R2',
            '2024-07-02T01:00:00Z,Trade,Buy Order,LTC,10,Buy 10LTC @ AUD 100 Trade settled,10,R2',
            '2024-07-02T01:00:00Z,Trade,Trading Fee,LTC,-0.085,Buy 10LTC @ AUD 100 Trading fee,9.915,R2',
        ]);

        // $1,000 for 10: the price is of what was traded, and 9.915 is what arrived.
        $this->assertSame(['1001 LTC buy 9.915 @ 100 0.00 1000.00'], $this->lines($report));
    }

    public function test_the_same_order_at_two_moments_is_two_entries_and_two_orders_at_one_moment_are_too(): void
    {
        $report = $this->report([
            '2024-07-02T01:00:00Z,Trade,Buy Order,AUD,-100,Buy 2LTC @ AUD 100 Partially matched,-100,R2',
            '2024-07-02T01:00:00Z,Trade,Buy Order,LTC,1,Buy 2LTC @ AUD 100 Trade settled,1,R2',
            '2024-07-02T01:00:00Z,Trade,Buy Order,AUD,-300,Buy 3LTC @ AUD 100 Fully matched,-400,R3',
            '2024-07-02T01:00:00Z,Trade,Buy Order,LTC,3,Buy 3LTC @ AUD 100 Trade settled,4,R3',
            '2024-07-02T09:00:00Z,Trade,Buy Order,AUD,-100,Buy 2LTC @ AUD 100 Fully matched,-500,R2',
            '2024-07-02T09:00:00Z,Trade,Buy Order,LTC,1,Buy 2LTC @ AUD 100 Trade settled,5,R2',
        ]);

        $this->assertSame(
            ['1001 LTC buy 1 @ 100 0.00 100.00', '1003 LTC buy 3 @ 100 0.00 300.00', '1005 LTC buy 1 @ 100 0.00 100.00'],
            $this->lines($report),
        );
    }

    public function test_the_two_sides_of_a_fill_stamped_a_second_apart_are_still_one_fill(): void
    {
        $report = $this->report([
            '2024-07-02T01:00:00Z,Trade,Sell Order,XRP,-200,Sell 200XRP @ BTC 0.00001 Trade settled,-200,R2',
            '2024-07-02T01:00:01Z,Trade,Sell Order,BTC,0.002,Sell 200XRP @ BTC 0.00001 Fully matched,0.002,R2',
            '2024-07-02T01:00:01Z,Trade,Trading Fee,BTC,-0.000017,Sell 200XRP @ BTC 0.00001 Trading fee,0.001983,R2',
            // Hours later, the same order fills again: that is another fill.
            '2024-07-02T05:00:00Z,Trade,Sell Order,XRP,-100,Sell 200XRP @ BTC 0.00001 Trade settled,-300,R2',
            '2024-07-02T05:00:00Z,Trade,Sell Order,BTC,0.001,Sell 200XRP @ BTC 0.00001 Fully matched,0.002983,R2',
        ]);

        $this->assertSame([
            '1000 XRP sell 200 for 0.001983 BTC',
            '1001 BTC buy 0.001983 for 200 XRP',
            '1003 XRP sell 100 for 0.001 BTC',
            '1004 BTC buy 0.001 for 100 XRP',
        ], $this->lines($report));
        $this->assertSame([], $report->warnings);
    }

    public function test_a_crumb_that_leaves_with_nothing_coming_back_is_a_sale_for_nothing(): void
    {
        $report = $this->report([
            '2024-07-02T01:00:00Z,Trade,Sell Order,XRP,-200,Sell 200.000001XRP @ BTC 0.00001 Partially matched,-200,R2',
            '2024-07-02T01:00:00Z,Trade,Sell Order,BTC,0.002,Sell 200.000001XRP @ BTC 0.00001 Trade settled,0.002,R2',
            // Five seconds on, the last millionth of an XRP goes, and buys no bitcoin that can be counted.
            '2024-07-02T01:00:05Z,Trade,Sell Order,XRP,-0.000001,Sell 200.000001XRP @ BTC 0.00001 Fully matched,-200.000001,R2',
        ]);

        $this->assertSame([
            '1000 XRP sell 200 for 0.002 BTC',
            '1001 BTC buy 0.002 for 200 XRP',
            '1002 XRP sell 0.000001 @ 0 0.00 0.00',
        ], $this->lines($report));
        $this->assertSame([], $report->warnings);
        $this->assertSame('-200.000001', (string) $report->balances['XRP']);
    }

    public function test_a_balance_column_that_disagrees_with_the_rows_is_reported_once_a_currency(): void
    {
        $report = $this->report([
            '2024-07-01T00:00:00Z,Fund Transfer,Deposit,BTC,1,BTC Deposit,1,R1',
            // A row is missing here: the balance jumps.
            '2024-07-03T00:00:00Z,Fund Transfer,Deposit,BTC,1,BTC Deposit,2.5,R3',
            '2024-07-04T00:00:00Z,Fund Transfer,Deposit,BTC,1,BTC Deposit,3.5,R4',
        ]);

        $this->assertCount(1, $report->warnings);
        $this->assertStringContainsString('The BTC balance column first disagrees with the rows before it at transaction 1001 on 2024-07-03', $report->warnings[0]);
        $this->assertStringContainsString('the rows add up to 2 and the report says 2.5', $report->warnings[0]);
    }

    public function test_a_row_that_is_not_understood_is_passed_over_with_a_warning(): void
    {
        $report = $this->report([
            '2024-07-01T00:00:00Z,Fund Transfer,Mystery,BTC,1,Something new,1,R1',
            '2024-07-02T00:00:00Z,Fund Transfer,Deposit,BTC,-1,A deposit that takes away,0,R2',
        ]);

        $this->assertSame([], $report->entries);
        $this->assertCount(2, $report->warnings);
        $this->assertStringContainsString('Transaction 1000 (Fund Transfer, Mystery, 1 BTC) was not understood', $report->warnings[0]);
    }

    public function test_a_file_that_is_not_a_transaction_report_is_refused(): void
    {
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, "date,amount\n2024-07-01,5\n");
        rewind($stream);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('This is not a BTC Markets transaction report');

        BtcMarketsCsv::read($stream);
    }

    public function test_an_empty_report_has_no_entries(): void
    {
        $report = $this->report([]);

        $this->assertSame([], $report->entries);
        $this->assertNull($report->endsAt);
    }
}
