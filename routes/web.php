<?php

use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\ProfileController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::middleware('auth')->group(function () {
    Route::view('/', 'dashboard')->name('dashboard');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');

    // Admin only, by UserPolicy. Users are deactivated, not deleted.
    Route::resource('users', UserController::class)->except(['show', 'destroy']);
});

// The container health check. No session or cookies: it must answer before the first migration.
Route::get('/up', HealthController::class)
    ->name('health')
    ->withoutMiddleware([
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        ShareErrorsFromSession::class,
        PreventRequestForgery::class,
    ]);

require __DIR__.'/auth.php';
