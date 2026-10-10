<?php

namespace App\Mcp\Tools;

use App\Api\TransactionsData;
use App\Enums\TransactionSource;
use App\Enums\TransactionType;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * The same data as GET /api/v1/transactions.
 */
#[Name('transactions')]
#[Description('The user\'s ledger, a page at a time, newest first: every buy, sell, find, reward, transfer and adjustment. Each has the asset, the type, when it happened, the units (and units_change, negative when units left the holding), the AUD unit price, fee and total (the cost with the fee for units coming in, the proceeds less the fee for units going out), where it came from (entered by hand, an exchange, or an import) and whether its amounts are estimated. Filter by asset, type, source, a range of days or estimated. "pages" and "total" say how much there is.')]
#[IsReadOnly]
class TransactionsTool extends Tool
{
    public function handle(Request $request, TransactionsData $transactions): ResponseFactory
    {
        $validated = $request->validate([
            'asset_id' => ['nullable', 'integer'],
            'type' => ['nullable', Rule::enum(TransactionType::class)],
            'source' => ['nullable', Rule::enum(TransactionSource::class)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'estimated' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.TransactionsData::MOST_PER_PAGE],
        ]);

        // As the endpoint's query string has them.
        return Response::structured($transactions->for($request->user(), array_filter([
            'asset' => $validated['asset_id'] ?? null,
            'type' => $validated['type'] ?? null,
            'source' => $validated['source'] ?? null,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'estimated' => isset($validated['estimated']) ? (string) (int) (bool) $validated['estimated'] : null,
            'page' => $validated['page'] ?? null,
            'per_page' => $validated['per_page'] ?? null,
        ], fn (mixed $value) => $value !== null)));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'asset_id' => $schema->integer()->description('Only this asset\'s transactions. The id is from holdings.'),
            'type' => $schema->string()->enum(array_column(TransactionType::cases(), 'value'))->description('Only this type.'),
            'source' => $schema->string()->enum(array_column(TransactionSource::cases(), 'value'))->description('Only those entered by hand (manual), fetched from an exchange, or imported.'),
            'from' => $schema->string()->description('The first day to include, as 2025-06-30, in the user\'s timezone.'),
            'to' => $schema->string()->description('The last day to include, as 2025-06-30, in the user\'s timezone.'),
            'estimated' => $schema->boolean()->description('true for only the transactions with estimated amounts, false for only the others.'),
            'page' => $schema->integer()->description('The page, from 1.'),
            'per_page' => $schema->integer()->description('How many to a page: '.TransactionsData::PER_PAGE.' unless given, '.TransactionsData::MOST_PER_PAGE.' at most.'),
        ];
    }
}
