<?php

namespace App\Mcp\Tools;

use App\Api\ParcelsData;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * The same data as GET /api/v1/assets/{asset}/parcels.
 */
#[Name('parcels')]
#[Description('The open parcels of one asset: the lots still held, oldest first. Each has the date it was acquired, the units acquired and the units remaining (in the asset\'s unit), the AUD cost base of the units remaining and the cost per unit, whether that cost is estimated, and whether it has been held over 12 months as of now (which is when the 50% capital gains discount would apply to a sale). Get an asset\'s id from holdings or transactions.')]
#[IsReadOnly]
class ParcelsTool extends Tool
{
    public function handle(Request $request, ParcelsData $parcels): Response|ResponseFactory
    {
        $validated = $request->validate(['asset_id' => ['required', 'integer']]);

        // Another user's asset is unknown, the same as one that does not exist.
        $asset = $request->user()->assets()->find($validated['asset_id']);

        return $asset
            ? Response::structured($parcels->for($asset))
            : Response::error("There is no asset with the id {$validated['asset_id']}. Call holdings for the ids.");
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'asset_id' => $schema->integer()->description('The id of the asset, from holdings or transactions.')->required(),
        ];
    }
}
