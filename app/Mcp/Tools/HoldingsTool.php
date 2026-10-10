<?php

namespace App\Mcp\Tools;

use App\Api\HoldingsData;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * The same data as GET /api/v1/holdings.
 */
#[Name('holdings')]
#[Description('What the user holds now and what it is worth. For each asset with units held: the units (in the asset\'s own unit), the AUD cost base and average cost, whether any of that cost is estimated, the latest price (AUD bid and ask per unit, and when it was read), the AUD market value at the bid, and the unrealised gain and its percentage. Value and gain are null for an asset with no price. "totals" adds up the assets that count toward the portfolio and have a price, and gives the AUD sitting on exchanges, which is not part of the total value.')]
#[IsReadOnly]
class HoldingsTool extends Tool
{
    public function handle(Request $request, HoldingsData $holdings): ResponseFactory
    {
        return Response::structured($holdings->for($request->user()));
    }
}
