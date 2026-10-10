<?php

namespace App\Mcp\Tools;

use App\Api\TaxData;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * The same data as GET /api/v1/tax/{year}.
 */
#[Name('tax_report')]
#[Description('The capital gains report for one financial year, in AUD, as the user has it set up. "summary" is the working: gains on assets held 12 months or less, gains on assets held longer, losses, losses carried forward in, the losses applied (to the short-held gains first), the 50% discount on what is left of the long-held gains, the net capital gain, and losses to carry forward. "sections" (crypto, and other assets) list each disposal: the asset, when it was acquired and sold, the units, cost base, proceeds, gain (negative for a loss), whether it was held over 12 months, and whether its cost is estimated. "asset_classes" says which classes the report takes in (crypto only unless the user chose others) and "asset_classes_left_out" which it does not. When "warning" is not null, disposals used estimated costs and the figures are not final: say so. It is a working paper, not tax advice.')]
#[IsReadOnly]
class TaxReportTool extends Tool
{
    public function handle(Request $request, TaxData $tax): Response|ResponseFactory
    {
        $year = (int) $request->validate(['year' => ['required', 'integer']])['year'];

        if (! $tax->isOpen($year)) {
            return Response::error("There is no tax report for {$year}: the year is closed, or is not one Nugget reports on. Call tax_years for the open years.");
        }

        return Response::structured($tax->report($request->user(), $year));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'year' => $schema->integer()->description('The year the financial year ends in: 2025 for 2024–25 (1 July 2024 to 30 June 2025).')->required(),
        ];
    }
}
