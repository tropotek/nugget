<?php

namespace App\Mcp\Tools;

use App\Api\TaxData;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * The same data as GET /api/v1/tax/years.
 */
#[Name('tax_years')]
#[Description('The financial years, the latest first: every open year (whether or not anything was sold in it) and each closed year in which something was sold. A year runs 1 July to 30 June and "year" is the one it ends in: 2025 is 2024–25. Each says how many disposal lines it has and whether it is open. Only an open year has a tax report: a closed year was lodged outside Nugget. Call this before tax_report to choose a year.')]
#[IsReadOnly]
class TaxYearsTool extends Tool
{
    public function handle(Request $request, TaxData $tax): ResponseFactory
    {
        return Response::structured($tax->years($request->user()));
    }
}
