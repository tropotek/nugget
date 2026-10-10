<?php

namespace Tests\Unit;

use App\Support\FinancialYear;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class FinancialYearTest extends TestCase
{
    public function test_it_is_named_by_the_year_it_ends_in(): void
    {
        $year = new FinancialYear(2025);

        $this->assertSame(2025, $year->year);
        $this->assertSame('2024–25', $year->label());
        $this->assertSame('1999–00', (new FinancialYear(2000))->label());
    }

    public function test_it_runs_from_1_july_to_30_june_in_the_given_timezone(): void
    {
        $year = new FinancialYear(2025);

        $this->assertSame('2024-07-01T00:00:00+10:00', $year->start('Australia/Melbourne')->toIso8601String());
        $this->assertSame('2025-07-01T00:00:00+10:00', $year->end('Australia/Melbourne')->toIso8601String());
        $this->assertSame('2024-07-01T00:00:00+08:00', $year->start('Australia/Perth')->toIso8601String());
    }

    public function test_the_year_of_a_moment_depends_on_the_timezone(): void
    {
        // 15:00 UTC on 30 June is 01:00 on 1 July in Melbourne and 23:00 on 30 June in Perth.
        $moment = CarbonImmutable::parse('2025-06-30 15:00:00', 'UTC');

        $this->assertSame(2026, FinancialYear::containing($moment, 'Australia/Melbourne')->year);
        $this->assertSame(2025, FinancialYear::containing($moment, 'Australia/Perth')->year);
    }

    public function test_the_first_and_last_moments_of_a_year_are_in_it(): void
    {
        $year = new FinancialYear(2025);
        $timezone = 'Australia/Melbourne';

        $this->assertSame(2025, FinancialYear::containing($year->start($timezone), $timezone)->year);
        $this->assertSame(2025, FinancialYear::containing($year->end($timezone)->subSecond(), $timezone)->year);
        $this->assertSame(2026, FinancialYear::containing($year->end($timezone), $timezone)->year);
    }
}
