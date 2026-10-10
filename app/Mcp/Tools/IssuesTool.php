<?php

namespace App\Mcp\Tools;

use App\Api\IssuesData;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * The same data as GET /api/v1/issues.
 */
#[Name('issues')]
#[Description('What the user has left to sort out before the figures can be relied on. "balance_mismatches": an exchange says the account holds a different number of units of an asset than the ledger does (difference is what the exchange has over the ledger). "parcels_without_cost": units still held with an AUD cost base of zero. One acquired_by "found" (prospected gold) has no cost on purpose; one acquired any other way, such as a transfer in, is missing its real cost, and its whole sale price would count as gain.')]
#[IsReadOnly]
class IssuesTool extends Tool
{
    public function handle(Request $request, IssuesData $issues): ResponseFactory
    {
        return Response::structured($issues->for($request->user()));
    }
}
