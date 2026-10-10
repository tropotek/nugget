<?php

use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExchangeController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SaleParcelController;
use App\Http\Controllers\TaxReportController;
use App\Http\Controllers\TradingViewController;
use App\Http\Controllers\TransactionController;
use App\Http\Middleware\ApplySettings;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::middleware('auth')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');

    // The owner only, by OwnerPolicy.
    Route::resource('assets', AssetController::class);

    // The owner only, by OwnerPolicy.
    Route::resource('transactions', TransactionController::class)->except(['show']);

    // The parcels a sale uses: picked by hand, or returned to the configured method.
    Route::get('/transactions/{transaction}/parcels', [SaleParcelController::class, 'edit'])->name('transactions.parcels.edit');
    Route::put('/transactions/{transaction}/parcels', [SaleParcelController::class, 'update'])->name('transactions.parcels.update');
    Route::delete('/transactions/{transaction}/parcels', [SaleParcelController::class, 'destroy'])->name('transactions.parcels.destroy');

    // The owner only, by OwnerPolicy. The test tries the saved key against the exchange; the sync runs one now.
    Route::resource('exchanges', ExchangeController::class)->except(['show']);
    Route::post('/exchanges/{exchange}/test', [ExchangeController::class, 'test'])->name('exchanges.test');
    Route::post('/exchanges/{exchange}/sync', [ExchangeController::class, 'sync'])->name('exchanges.sync');

    // The signed-in user's own capital gains report, for an open year.
    Route::get('/tax-report', [TaxReportController::class, 'index'])->name('tax-report.index');
    Route::get('/tax-report/{year}', [TaxReportController::class, 'show'])->whereNumber('year')->name('tax-report.show');
    Route::put('/tax-report/{year}', [TaxReportController::class, 'update'])->whereNumber('year')->name('tax-report.update');
    Route::get('/tax-report/{year}/pdf', [TaxReportController::class, 'pdf'])->whereNumber('year')->name('tax-report.pdf');

    // TradingView's embedded chart.
    Route::get('/tradingview', TradingViewController::class)->name('tradingview');

    // The signed-in user's own. Opening one marks it read and goes where it leads.
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/notifications/menu', [NotificationController::class, 'menu'])->name('notifications.menu');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::get('/notifications/{notification}', [NotificationController::class, 'show'])->name('notifications.show');

    // Admin only, by UserPolicy. Users are deactivated, not deleted.
    Route::resource('users', UserController::class)->except(['show', 'destroy']);

    // Admin only, by the admin ability.
    Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
});

// The container health check. No session, cookies or settings: it must answer before the first migration.
Route::get('/up', HealthController::class)
    ->name('health')
    ->withoutMiddleware([
        ApplySettings::class,
        EnsureUserIsActive::class,
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        ShareErrorsFromSession::class,
        PreventRequestForgery::class,
    ]);

require __DIR__.'/auth.php';
