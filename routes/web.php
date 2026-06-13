<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\ComplianceController;
use App\Http\Controllers\CorporateController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DespatchController;
use App\Http\Controllers\Driver\JobController;
use App\Http\Controllers\Driver\LocationController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\TrackingController;
use Illuminate\Support\Facades\Route;

// Public landing → send to login (or dashboard if already authenticated).
Route::get('/', function () {
    return redirect()->route(auth()->check() ? 'dashboard' : 'login');
});

// ----- Authentication ----------------------------------------------------
// Login is rate limited at the form-request level (5 attempts / email+IP).
Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:10,1');
});

Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

// ----- Authenticated area ------------------------------------------------
Route::middleware('auth')->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Bookings + AI quotes — admins and corporate clients.
    Route::middleware('role:admin,corporate_client')->group(function () {
        Route::get('bookings/create', [BookingController::class, 'create'])->name('bookings.create');
        Route::post('bookings', [BookingController::class, 'store'])
            ->middleware('throttle:30,1')
            ->name('bookings.store');

        Route::get('quotes/create', [QuoteController::class, 'create'])->name('quotes.create');
        Route::post('quotes', [QuoteController::class, 'store'])->middleware('throttle:30,1')->name('quotes.store');
        Route::get('quotes/{quote}', [QuoteController::class, 'show'])->name('quotes.show');
    });

    Route::get('bookings', [BookingController::class, 'index'])->name('bookings.index');
    Route::get('bookings/{booking}', [BookingController::class, 'show'])->name('bookings.show');

    // ----- Despatch board (admin) ----------------------------------------
    Route::middleware('role:admin')->group(function () {
        Route::get('despatch', [DespatchController::class, 'index'])->name('despatch.board');
        Route::post('despatch/{booking}/allocate', [DespatchController::class, 'allocate'])->name('despatch.allocate');
        Route::post('despatch/{booking}/auto-allocate', [DespatchController::class, 'autoAllocate'])->name('despatch.auto-allocate');
        Route::post('despatch/{booking}/status', [DespatchController::class, 'updateStatus'])->name('despatch.status');
    });

    // ----- Driver mobile app (driver) ------------------------------------
    Route::middleware('role:driver,admin')->prefix('driver')->name('driver.')->group(function () {
        Route::get('jobs', [JobController::class, 'index'])->name('jobs');
        Route::get('jobs/{booking}', [JobController::class, 'show'])->name('job');
        Route::post('jobs/{booking}/status', [JobController::class, 'updateStatus'])->name('job.status');
        // GPS ping — only stored while on an active job.
        Route::post('locations', [LocationController::class, 'store'])->name('locations.store');
    });

    // ----- Fleet & compliance + reports (admin) --------------------------
    Route::middleware('role:admin')->group(function () {
        Route::get('compliance', [ComplianceController::class, 'index'])->name('compliance.index');
        Route::get('reports/revenue', [ReportController::class, 'revenue'])->name('reports.revenue');
        Route::get('reports/ads', [ReportController::class, 'ads'])->name('reports.ads');
    });

    // Corporate portal — account statement (clients see own; admins all theirs).
    Route::middleware('role:corporate_client,admin')->group(function () {
        Route::get('account', [CorporateController::class, 'statement'])->name('corporate.statement');
    });

    // Invoices — admins (all) and corporate clients (own account).
    Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
});

// ----- Public live tracking (token in URL, no login) ---------------------
Route::get('track/{token}', [TrackingController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('track');
Route::get('track/{token}/location', [TrackingController::class, 'location'])
    ->middleware('throttle:120,1')
    ->name('track.location');
