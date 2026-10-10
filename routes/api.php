<?php

use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\PortfolioController;
use Illuminate\Support\Facades\Route;

/*
| The read-only JSON API, under /api. Every route is a GET, needs a personal
| access token with the `read` ability (made on the profile page), and reads
| only the data of the token's own user. A token of a deactivated user is
| refused. Errors are JSON.
*/

Route::prefix('v1')->name('api.')->middleware(['auth:sanctum', 'ability:read', 'active', 'throttle:api'])->group(function () {
    Route::get('/me', MeController::class)->name('me');

    Route::get('/holdings', [PortfolioController::class, 'holdings'])->name('holdings');
    Route::get('/assets/{asset}/parcels', [PortfolioController::class, 'parcels'])->whereNumber('asset')->name('parcels');
    Route::get('/transactions', [PortfolioController::class, 'transactions'])->name('transactions');
    Route::get('/tax/years', [PortfolioController::class, 'taxYears'])->name('tax.years');
    Route::get('/tax/{year}', [PortfolioController::class, 'taxReport'])->whereNumber('year')->name('tax.report');
    Route::get('/issues', [PortfolioController::class, 'issues'])->name('issues');
});
