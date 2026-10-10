<?php

use App\Http\Controllers\Api\MeController;
use Illuminate\Support\Facades\Route;

/*
| The read-only JSON API, under /api. Every route is a GET, needs a personal
| access token with the `read` ability (made on the profile page), and reads
| only the data of the token's own user. A token of a deactivated user is
| refused. Errors are JSON.
*/

Route::prefix('v1')->name('api.')->middleware(['auth:sanctum', 'ability:read', 'active', 'throttle:api'])->group(function () {
    Route::get('/me', MeController::class)->name('me');
});
