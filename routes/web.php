<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\ComplianceController;
use App\Http\Controllers\ConsentController;
use App\Http\Controllers\CorporateController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DespatchController;
use App\Http\Controllers\Driver\JobController;
use App\Http\Controllers\Driver\LocationController;
use App\Http\Controllers\ErasureController;
use App\Http\Controllers\FixedPriceController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\MarketingController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\TrackingController;
use App\Http\Controllers\WaitingListController;
use App\Http\Controllers\WebhookController;
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
    Route::get('jobs/day', [DashboardController::class, 'day'])->name('jobs.day');

    // Bookings + AI quotes — admins and corporate clients.
    Route::middleware('role:admin,corporate_client')->group(function () {
        Route::get('bookings/create', [BookingController::class, 'create'])->name('bookings.create');
        Route::post('bookings', [BookingController::class, 'store'])
            ->middleware('throttle:30,1')
            ->name('bookings.store');

        Route::get('quotes/create', [QuoteController::class, 'create'])->name('quotes.create');
        Route::post('quotes', [QuoteController::class, 'store'])->middleware('throttle:30,1')->name('quotes.store');
        Route::get('quotes/{quote}', [QuoteController::class, 'show'])->name('quotes.show');

        // Address autocomplete (server-side proxy to Google Places).
        Route::get('places/autocomplete', [\App\Http\Controllers\PlacesController::class, 'autocomplete'])
            ->middleware('throttle:120,1')->name('places.autocomplete');

        // Live fare estimate (fixed airport price / free-roam distance).
        Route::get('pricing/estimate', [\App\Http\Controllers\PricingController::class, 'estimate'])
            ->middleware('throttle:120,1')->name('pricing.estimate');
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
        Route::get('jobs/offers-count', [JobController::class, 'offersCount'])->name('offers-count');
        Route::get('earnings', [JobController::class, 'earnings'])->name('earnings');
        Route::get('jobs/{booking}', [JobController::class, 'show'])->name('job');
        Route::post('jobs/{booking}/status', [JobController::class, 'updateStatus'])->name('job.status');
        Route::post('jobs/{booking}/decline', [JobController::class, 'decline'])->name('job.decline');
        // GPS ping — only stored while on an active job.
        Route::post('locations', [LocationController::class, 'store'])->name('locations.store');

        // Vehicle & documents — days-left cards + upload.
        Route::get('documents', [\App\Http\Controllers\Driver\DocumentController::class, 'index'])->name('documents');
        Route::post('documents', [\App\Http\Controllers\Driver\DocumentController::class, 'store'])
            ->middleware('throttle:20,1')->name('documents.store');
        Route::get('documents/{document}/file', [\App\Http\Controllers\Driver\DocumentController::class, 'download'])->name('documents.file');
    });

    // ----- Fleet & compliance + reports + waiting list (admin) -----------
    Route::middleware('role:admin')->group(function () {
        Route::get('compliance', [ComplianceController::class, 'index'])->name('compliance.index');

        // Settings — paste integration keys in-app (Google Maps, …).
        Route::get('settings', [\App\Http\Controllers\Admin\SettingsController::class, 'index'])->name('settings.index');
        Route::put('settings', [\App\Http\Controllers\Admin\SettingsController::class, 'update'])->name('settings.update');

        // In-app CSV imports (Google Ads report, ETO bookings export).
        Route::get('imports', [\App\Http\Controllers\Admin\ImportController::class, 'index'])->name('imports.index');
        Route::post('imports/ads', [\App\Http\Controllers\Admin\ImportController::class, 'ads'])
            ->middleware('throttle:20,1')->name('imports.ads');
        Route::post('imports/eto', [\App\Http\Controllers\Admin\ImportController::class, 'eto'])
            ->middleware('throttle:20,1')->name('imports.eto');

        // Driver onboarding — create login + profile (+ vehicle).
        Route::get('drivers/create', [\App\Http\Controllers\Admin\DriverController::class, 'create'])->name('drivers.create');
        Route::post('drivers', [\App\Http\Controllers\Admin\DriverController::class, 'store'])->name('drivers.store');
        Route::get('drivers/{user}/edit', [\App\Http\Controllers\Admin\DriverController::class, 'edit'])->name('drivers.edit');
        Route::put('drivers/{user}', [\App\Http\Controllers\Admin\DriverController::class, 'update'])->name('drivers.update');

        // Driver documents — verification queue + upload on behalf of a driver.
        Route::get('driver-documents', [\App\Http\Controllers\Admin\DriverDocumentController::class, 'index'])->name('driver-documents.index');
        Route::get('driver-documents/{user}', [\App\Http\Controllers\Admin\DriverDocumentController::class, 'show'])->name('driver-documents.show');
        Route::post('driver-documents/{user}', [\App\Http\Controllers\Admin\DriverDocumentController::class, 'store'])
            ->middleware('throttle:30,1')->name('driver-documents.store');
        Route::post('driver-document/{document}/review', [\App\Http\Controllers\Admin\DriverDocumentController::class, 'review'])->name('driver-documents.review');
        Route::get('review', [\App\Http\Controllers\ReviewController::class, 'index'])->name('review.index');
        Route::get('reports/revenue', [ReportController::class, 'revenue'])->name('reports.revenue');
        Route::get('reports/ads', [ReportController::class, 'ads'])->name('reports.ads');

        // Marketing — Google Ads dashboard, keywords, SEO.
        Route::get('marketing/ads', [ReportController::class, 'ads'])->name('marketing.ads');
        Route::get('marketing/keywords', [MarketingController::class, 'keywords'])->name('marketing.keywords');
        Route::post('marketing/keywords', [MarketingController::class, 'storeKeyword'])->name('marketing.keywords.store');
        Route::delete('marketing/keywords/{keyword}', [MarketingController::class, 'destroyKeyword'])->name('marketing.keywords.destroy');
        Route::get('marketing/seo', [MarketingController::class, 'seo'])->name('marketing.seo');
        Route::post('marketing/seo', [MarketingController::class, 'storeSeo'])->name('marketing.seo.store');
        Route::delete('marketing/seo/{seoPage}', [MarketingController::class, 'destroySeo'])->name('marketing.seo.destroy');
        Route::get('pricing', [FixedPriceController::class, 'index'])->name('pricing.index');
        Route::post('pricing', [FixedPriceController::class, 'store'])->name('pricing.store');
        Route::delete('pricing', [FixedPriceController::class, 'destroy'])->name('pricing.destroy');
        Route::get('waiting-list', [WaitingListController::class, 'index'])->name('waiting-list.index');
        Route::post('waiting-list', [WaitingListController::class, 'store'])->name('waiting-list.store');

        // GDPR right-to-erasure.
        Route::get('gdpr/erasure', [ErasureController::class, 'index'])->name('gdpr.erasure');
        Route::post('gdpr/erasure', [ErasureController::class, 'store'])->name('gdpr.erasure.store');
        Route::post('gdpr/erasure/{erasureRequest}/process', [ErasureController::class, 'process'])->name('gdpr.erasure.process');
    });

    // Corporate portal — account statement (clients see own; admins all theirs).
    Route::middleware('role:corporate_client,admin')->group(function () {
        Route::get('account', [CorporateController::class, 'statement'])->name('corporate.statement');
    });

    // Invoices — admins (all) and corporate clients (own account).
    Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
    Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'download'])->name('invoices.pdf');
});

// ----- Public consent capture (cookie banner) ----------------------------
Route::post('consent/cookies', [ConsentController::class, 'cookies'])
    ->middleware('throttle:60,1')
    ->name('consent.cookies');

// ----- Inbound webhooks (secret-guarded, no login) -----------------------
Route::post('webhooks/missed-call', [WebhookController::class, 'missedCall'])
    ->middleware('throttle:60,1')
    ->name('webhooks.missed-call');
Route::post('webhooks/voice', [WebhookController::class, 'voice'])
    ->middleware('throttle:120,1')
    ->name('webhooks.voice');

// ----- Public live tracking (token in URL, no login) ---------------------
Route::get('track/{token}', [TrackingController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('track');
Route::get('track/{token}/location', [TrackingController::class, 'location'])
    ->middleware('throttle:120,1')
    ->name('track.location');
