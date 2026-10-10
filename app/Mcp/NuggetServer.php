<?php

namespace App\Mcp;

use App\Mcp\Tools\HoldingsTool;
use App\Mcp\Tools\IssuesTool;
use App\Mcp\Tools\ParcelsTool;
use App\Mcp\Tools\TaxReportTool;
use App\Mcp\Tools\TaxYearsTool;
use App\Mcp\Tools\TransactionsTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * The MCP server at /mcp: the read-only API as tools, for an assistant such
 * as Claude to read a user's figures with. It is reached with the same
 * personal access token as the API and reads only that token's user's data.
 *
 * Every tool is read-only. Nothing here changes the ledger.
 */
#[Name('Nugget')]
#[Version('1.0.0')]
#[Instructions(<<<'TEXT'
Nugget is one person's record of their investments (crypto on exchanges, metals and other assets) and their Australian capital gains. These tools read it; none can change it.

- Money is in Australian dollars. Amounts are decimal strings ("1234.50"), never numbers: do arithmetic on them as decimals.
- Units of an asset are in that asset's own unit (BTC, oz, g), given beside it.
- Dates are ISO 8601 in the user's own timezone, with the offset.
- A financial year runs 1 July to 30 June and is named by the year it ends in: 2025 is 2024–25. Only an open year has a tax report; call tax_years to see which.
- The tax report is a working paper, not tax advice. It covers capital gains only. By default it takes in crypto only: the report says which asset classes it includes and which it leaves out.
- "Estimated" marks a cost that is a best guess. Say so when a figure rests on one, and check issues for what is still to be sorted out.
TEXT)]
class NuggetServer extends Server
{
    protected array $tools = [
        HoldingsTool::class,
        ParcelsTool::class,
        TransactionsTool::class,
        TaxYearsTool::class,
        TaxReportTool::class,
        IssuesTool::class,
    ];
}
