<?php

namespace App\Http\Controllers\Api;

use App\Api\HoldingsData;
use App\Api\IssuesData;
use App\Api\ParcelsData;
use App\Api\TaxData;
use App\Api\TransactionsData;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The read-only endpoints. Each returns what its App\Api class works out for
 * the token's own user, wrapped in `data`. The MCP server's tools call the
 * same classes, so the two cannot disagree.
 */
class PortfolioController extends Controller
{
    public function holdings(Request $request, HoldingsData $holdings): JsonResponse
    {
        return $this->data($holdings->for($request->user()));
    }

    /**
     * Another user's asset is not found, the same as one that does not exist.
     */
    public function parcels(Request $request, int $asset, ParcelsData $parcels): JsonResponse
    {
        return $this->data($parcels->for($request->user()->assets()->findOrFail($asset)));
    }

    public function transactions(Request $request, TransactionsData $transactions): JsonResponse
    {
        return $this->data($transactions->for($request->user(), $request->query()));
    }

    public function taxYears(Request $request, TaxData $tax): JsonResponse
    {
        return $this->data($tax->years($request->user()));
    }

    public function taxReport(Request $request, int $year, TaxData $tax): JsonResponse
    {
        abort_unless($tax->isOpen($year), 404, "There is no tax report for {$year}: the year is closed, or is not one Nugget reports on.");

        return $this->data($tax->report($request->user(), $year));
    }

    public function issues(Request $request, IssuesData $issues): JsonResponse
    {
        return $this->data($issues->for($request->user()));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function data(array $data): JsonResponse
    {
        return response()->json(['data' => $data]);
    }
}
