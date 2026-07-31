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

// ----- PWA assets ----------------------------------------------------------
// Served as static files by the webserver in production; these routes make
// them available under any server config (and in tests). No auth — the
// manifest/worker/offline page contain no data.
Route::get('manifest.webmanifest', fn () => response()->file(
    public_path('manifest.webmanifest'), ['Content-Type' => 'application/manifest+json']
))->name('pwa.manifest');
Route::get('sw.js', fn () => response()->file(
    public_path('sw.js'), ['Content-Type' => 'application/javascript', 'Service-Worker-Allowed' => '/']
))->name('pwa.sw');
Route::get('offline.html', fn () => response()->file(public_path('offline.html')))->name('pwa.offline');

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

// Set-your-own-password (forced first-login flow + voluntary change). Sits
// OUTSIDE the password-change gate so a flagged user can actually reach it.
Route::middleware('auth')->group(function () {
    Route::get('password/change', [\App\Http\Controllers\Auth\PasswordController::class, 'edit'])->name('password.change');
    Route::put('password/change', [\App\Http\Controllers\Auth\PasswordController::class, 'update'])
        ->middleware('throttle:10,1')->name('password.update');
});

// ----- Authenticated area ------------------------------------------------
Route::middleware(['auth', \App\Http\Middleware\RequirePasswordChange::class])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('dashboard/fix-times', [DashboardController::class, 'fixTimes'])->middleware('throttle:10,1')->name('dashboard.fix-times');
    Route::get('jobs/day', [DashboardController::class, 'day'])->name('jobs.day');
    // Live fleet map (admin).
    Route::get('fleet/map', [\App\Http\Controllers\FleetMapController::class, 'index'])->name('fleet.map');
    Route::get('fleet/positions', [\App\Http\Controllers\FleetMapController::class, 'data'])->middleware('throttle:120,1')->name('fleet.positions');
    // Pull a calendar-only job into the booking system (admin only).
    Route::post('jobs/import', [\App\Http\Controllers\CalendarJobController::class, 'store'])
        ->middleware('role:admin', 'throttle:30,1')->name('jobs.import');
    // Pull EVERY calendar-only job for a day into bookings in one tap.
    Route::post('jobs/import-all', [\App\Http\Controllers\CalendarJobController::class, 'storeAll'])
        ->middleware('role:admin', 'throttle:20,1')->name('jobs.import-all');

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

    // Amend / cancel an existing booking + customer comms (admin only).
    Route::middleware('role:admin')->group(function () {
        // CSV export of the current list view (registered before the {booking}
        // wildcard so "export" isn't mistaken for a booking id).
        Route::get('bookings/export', [BookingController::class, 'export'])->middleware('throttle:12,1')->name('bookings.export');
        Route::get('bookings/{booking}/edit', [BookingController::class, 'edit'])->name('bookings.edit');
        Route::put('bookings/{booking}', [BookingController::class, 'update'])->middleware('throttle:30,1')->name('bookings.update');
        Route::post('bookings/{booking}/cancel', [BookingController::class, 'cancel'])->name('bookings.cancel');
        Route::post('bookings/{booking}/merge', [BookingController::class, 'merge'])->middleware('throttle:30,1')->name('bookings.merge');
        Route::post('bookings/{booking}/keep-separate', [BookingController::class, 'keepSeparate'])->middleware('throttle:30,1')->name('bookings.keep-separate');
        Route::post('bookings/{booking}/extra-drivers', [BookingController::class, 'addExtraDriver'])->middleware('throttle:30,1')->name('bookings.extra-drivers.add');
        Route::post('bookings/{booking}/extra-drivers/remove', [BookingController::class, 'removeExtraDriver'])->middleware('throttle:30,1')->name('bookings.extra-drivers.remove');
        Route::post('bookings/{booking}/extra-drivers/payroll', [BookingController::class, 'extraDriverPayroll'])->middleware('throttle:60,1')->name('bookings.extra-drivers.payroll');
        Route::post('bookings/{booking}/driver-details', [BookingController::class, 'setDriverDetails'])->middleware('throttle:30,1')->name('bookings.driver-details');
        Route::post('bookings/{booking}/sync-time', [BookingController::class, 'syncTime'])->middleware('throttle:30,1')->name('bookings.sync-time');
        Route::post('bookings/{booking}/payroll', [BookingController::class, 'payroll'])->middleware('throttle:30,1')->name('bookings.payroll');
        Route::post('bookings/{booking}/scan-calendar', [BookingController::class, 'scanCalendar'])->middleware('throttle:30,1')->name('bookings.scan-calendar');
        // Ask the driver to share their location now + poll their latest ping.
        Route::post('bookings/{booking}/request-location', [BookingController::class, 'requestLocation'])->middleware('throttle:20,1')->name('bookings.request-location');
        Route::get('bookings/{booking}/location', [BookingController::class, 'locationData'])->name('bookings.location');
        // Turn number masking off / on for a single job (e.g. a return leg).
        Route::post('bookings/{booking}/toggle-masking', [BookingController::class, 'toggleMasking'])->middleware('throttle:20,1')->name('bookings.toggle-masking');
        // Per-booking masking timing: when the line goes live + when it closes.
        Route::post('bookings/{booking}/masking-timing', [BookingController::class, 'maskingTiming'])->middleware('throttle:30,1')->name('bookings.masking-timing');
        Route::get('payroll', [\App\Http\Controllers\Admin\PayrollController::class, 'index'])->name('payroll.index');
        Route::post('bookings/{booking}/message', [\App\Http\Controllers\MessageController::class, 'store'])->middleware('throttle:30,1')->name('bookings.message');
        Route::post('bookings/{booking}/request-review', [BookingController::class, 'requestReview'])->middleware('throttle:30,1')->name('bookings.request-review');
        // Correct the customer record's phone to this booking's calendar contact.
        Route::post('bookings/{booking}/fix-contact', [BookingController::class, 'fixContact'])->middleware('throttle:30,1')->name('bookings.fix-contact');
        Route::post('messages/{message}/resend', [\App\Http\Controllers\MessageController::class, 'resend'])->middleware('throttle:30,1')->name('messages.resend');
        Route::post('messages/{message}/sent', [\App\Http\Controllers\MessageController::class, 'markSent'])->middleware('throttle:60,1')->name('messages.sent');

        // Email enquiries inbox (Outlook → quote → draft reply).
        Route::get('enquiries', [\App\Http\Controllers\EnquiryController::class, 'index'])->name('enquiries.index');
        Route::post('enquiries/refresh', [\App\Http\Controllers\EnquiryController::class, 'refresh'])->middleware('throttle:10,1')->name('enquiries.refresh');
        Route::get('enquiries/{enquiry}', [\App\Http\Controllers\EnquiryController::class, 'show'])->name('enquiries.show');
        Route::put('enquiries/{enquiry}', [\App\Http\Controllers\EnquiryController::class, 'update'])->name('enquiries.update');
        Route::post('enquiries/{enquiry}/send', [\App\Http\Controllers\EnquiryController::class, 'send'])->middleware('throttle:30,1')->name('enquiries.send');
        Route::post('enquiries/{enquiry}/dismiss', [\App\Http\Controllers\EnquiryController::class, 'dismiss'])->name('enquiries.dismiss');

        // Payments — outstanding money + mark paid.
        Route::get('payments', [\App\Http\Controllers\PaymentController::class, 'index'])->name('payments.index');
        Route::post('bookings/{booking}/paid', [\App\Http\Controllers\PaymentController::class, 'markPaid'])->name('payments.paid');

        // User management (admins; admin/super-admin creation is super-admin only).
        Route::get('users', [\App\Http\Controllers\Admin\UserController::class, 'index'])->name('users.index');
        Route::get('users/create', [\App\Http\Controllers\Admin\UserController::class, 'create'])->name('users.create');
        Route::post('users', [\App\Http\Controllers\Admin\UserController::class, 'store'])->name('users.store');
        Route::get('users/{user}/edit', [\App\Http\Controllers\Admin\UserController::class, 'edit'])->name('users.edit');
        Route::put('users/{user}', [\App\Http\Controllers\Admin\UserController::class, 'update'])->name('users.update');
        Route::delete('users/{user}', [\App\Http\Controllers\Admin\UserController::class, 'destroy'])->name('users.destroy');

        // Drivers directory (roster picked from when preparing reminders).
        Route::get('cover-drivers', [\App\Http\Controllers\CoverDriverController::class, 'index'])->name('cover-drivers.index');
        Route::post('cover-drivers', [\App\Http\Controllers\CoverDriverController::class, 'store'])->name('cover-drivers.store');
        Route::post('cover-drivers/sync', [\App\Http\Controllers\CoverDriverController::class, 'sync'])->middleware('throttle:6,1')->name('cover-drivers.sync');
        Route::put('cover-drivers/{coverDriver}', [\App\Http\Controllers\CoverDriverController::class, 'update'])->name('cover-drivers.update');
        Route::delete('cover-drivers/{coverDriver}', [\App\Http\Controllers\CoverDriverController::class, 'destroy'])->name('cover-drivers.destroy');

        // Customer CRM.
        Route::get('customers', [\App\Http\Controllers\CustomerController::class, 'index'])->name('customers.index');
        Route::get('customers/{customer}', [\App\Http\Controllers\CustomerController::class, 'show'])->name('customers.show');
        Route::put('customers/{customer}', [\App\Http\Controllers\CustomerController::class, 'update'])->name('customers.update');
        Route::delete('customers/{customer}', [\App\Http\Controllers\CustomerController::class, 'destroy'])->name('customers.destroy');
        Route::post('customers/{customer}/addresses', [\App\Http\Controllers\CustomerController::class, 'storeAddress'])->name('customers.addresses.store');
        Route::delete('customers/{customer}/addresses/{address}', [\App\Http\Controllers\CustomerController::class, 'destroyAddress'])->name('customers.addresses.destroy');
    });

    Route::get('bookings/{booking}', [BookingController::class, 'show'])->name('bookings.show');

    // ----- Despatch board (admin) ----------------------------------------
    Route::middleware('role:admin')->group(function () {
        Route::get('despatch', [DespatchController::class, 'index'])->name('despatch.board');
        Route::post('despatch/{booking}/allocate', [DespatchController::class, 'allocate'])->name('despatch.allocate');
        Route::post('despatch/{booking}/auto-allocate', [DespatchController::class, 'autoAllocate'])->name('despatch.auto-allocate');
        Route::post('despatch/{booking}/status', [DespatchController::class, 'updateStatus'])->name('despatch.status');
        Route::post('despatch/{booking}/quick-status', [DespatchController::class, 'quickStatus'])->middleware('throttle:60,1')->name('despatch.quick-status');
        Route::post('despatch/{booking}/reassign', [DespatchController::class, 'reassign'])->middleware('throttle:60,1')->name('despatch.reassign');
    });

    // ----- Driver mobile app (driver) ------------------------------------
    Route::middleware('role:driver,admin')->prefix('driver')->name('driver.')->group(function () {
        Route::get('jobs', [JobController::class, 'index'])->name('jobs');
        Route::get('jobs/offers-count', [JobController::class, 'offersCount'])->name('offers-count');
        Route::get('earnings', [JobController::class, 'earnings'])->name('earnings');
        Route::get('jobs/{booking}', [JobController::class, 'show'])->name('job');
        Route::post('jobs/{booking}/status', [JobController::class, 'updateStatus'])->name('job.status');
        Route::post('jobs/{booking}/reach-stop', [JobController::class, 'reachStop'])->middleware('throttle:60,1')->name('job.reach-stop');
        Route::post('jobs/{booking}/decline', [JobController::class, 'decline'])->name('job.decline');
        // Answer an office location request with a one-off ping.
        Route::post('jobs/{booking}/location', [JobController::class, 'shareLocation'])->middleware('throttle:60,1')->name('job.location');
        // GPS ping — only stored while on an active job.
        Route::post('locations', [LocationController::class, 'store'])->name('locations.store');

        // Push notifications (new-job alerts to the driver's phone).
        Route::get('push/key', [\App\Http\Controllers\Driver\PushSubscriptionController::class, 'key'])->name('push.key');
        Route::post('push/subscribe', [\App\Http\Controllers\Driver\PushSubscriptionController::class, 'store'])->middleware('throttle:30,1')->name('push.subscribe');
        Route::post('push/unsubscribe', [\App\Http\Controllers\Driver\PushSubscriptionController::class, 'destroy'])->middleware('throttle:30,1')->name('push.unsubscribe');
        // Fire a test notification to your own devices (confirm it reaches the phone).
        Route::post('push/test', [\App\Http\Controllers\Driver\PushSubscriptionController::class, 'test'])->middleware('throttle:6,1')->name('push.test');

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

        // Control-tower alerts feed + per-admin notification preferences.
        Route::get('alerts/feed', [\App\Http\Controllers\AlertsController::class, 'feed'])->name('alerts.feed');
        Route::post('alerts/{event}/ack', [\App\Http\Controllers\AlertsController::class, 'acknowledge'])
            ->middleware('throttle:60,1')->name('alerts.ack');
        Route::post('alerts/ack-all', [\App\Http\Controllers\AlertsController::class, 'acknowledgeAll'])
            ->middleware('throttle:30,1')->name('alerts.ackAll');
        Route::get('settings/notifications', [\App\Http\Controllers\Admin\NotificationPreferencesController::class, 'index'])->name('notifications.index');
        Route::put('settings/notifications', [\App\Http\Controllers\Admin\NotificationPreferencesController::class, 'update'])->name('notifications.update');

        // In-app CSV imports (Google Ads report, ETO bookings export).
        Route::get('imports', [\App\Http\Controllers\Admin\ImportController::class, 'index'])->name('imports.index');
        Route::post('imports/ads', [\App\Http\Controllers\Admin\ImportController::class, 'ads'])
            ->middleware('throttle:20,1')->name('imports.ads');
        Route::post('imports/eto', [\App\Http\Controllers\Admin\ImportController::class, 'eto'])
            ->middleware('throttle:20,1')->name('imports.eto');

        // ETO reconciliation — reconfirm bookings against the calendar, one ref at a time.
        Route::get('audit', [\App\Http\Controllers\Admin\AuditController::class, 'index'])->name('audit.index');
        Route::post('audit', [\App\Http\Controllers\Admin\AuditController::class, 'run'])
            ->middleware('throttle:20,1')->name('audit.run');

        // Driver rotation — read-only order + next-driver pointer + history.
        Route::get('rotation', [\App\Http\Controllers\Admin\RotationController::class, 'index'])->name('rotation.index');
        Route::post('rotation/next', [\App\Http\Controllers\Admin\RotationController::class, 'setNext'])->middleware('throttle:30,1')->name('rotation.set-next');

        // Paste a message → formats it into the exact CET calendar block to copy
        // onto Google Calendar. Never creates a booking (calendar is the origin).
        Route::get('intake', [\App\Http\Controllers\Admin\BookingIntakeController::class, 'index'])->name('intake.index');
        Route::post('intake/preview', [\App\Http\Controllers\Admin\BookingIntakeController::class, 'preview'])
            ->middleware('throttle:30,1')->name('intake.preview');

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
        Route::post('review/backfill-prices', [\App\Http\Controllers\ReviewController::class, 'backfillPrices'])->middleware('throttle:6,1')->name('review.backfill-prices');
        Route::get('reports/profit', [ReportController::class, 'profit'])->name('reports.profit');
        Route::get('reports/revenue', [ReportController::class, 'revenue'])->name('reports.revenue');
        Route::get('reports/ads', [ReportController::class, 'ads'])->name('reports.ads');

        // Marketing — Google Ads dashboard, keywords, SEO.
        Route::get('marketing/studio', [\App\Http\Controllers\MarketingStudioController::class, 'index'])->name('marketing.studio');
        Route::post('marketing/studio', [\App\Http\Controllers\MarketingStudioController::class, 'generate'])->middleware('throttle:20,1')->name('marketing.studio.generate');
        Route::get('marketing/ads', [ReportController::class, 'ads'])->name('marketing.ads');
        Route::get('marketing/keywords', [MarketingController::class, 'keywords'])->name('marketing.keywords');
        Route::post('marketing/keywords', [MarketingController::class, 'storeKeyword'])->name('marketing.keywords.store');
        Route::post('marketing/keywords/import', [MarketingController::class, 'importKeywords'])->middleware('throttle:20,1')->name('marketing.keywords.import');
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
Route::post('webhooks/twilio-proxy', [WebhookController::class, 'proxyEvent'])
    ->middleware('throttle:120,1')
    ->name('webhooks.twilio-proxy');
Route::post('webhooks/sms', [WebhookController::class, 'sms'])
    ->middleware('throttle:120,1')
    ->name('webhooks.sms');
Route::post('webhooks/voice', [WebhookController::class, 'voice'])
    ->middleware('throttle:120,1')
    ->name('webhooks.voice');
// Square card-tip payment webhook (HMAC-verified inside the controller).
Route::post('webhooks/square', [WebhookController::class, 'square'])
    ->middleware('throttle:120,1')
    ->name('webhooks.square');

// ----- Public live tracking (token in URL, no login) ---------------------
Route::get('track/{token}', [TrackingController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('track');
Route::get('track/{token}/location', [TrackingController::class, 'location'])
    ->middleware('throttle:120,1')
    ->name('track.location');

// ----- Public shareable DRIVER LINK (token in URL, no login) --------------
// A cover driver works their job — details, cash to collect, masked number,
// navigate, status buttons and live GPS — without an account. The token is the
// key; the link dies once the job is terminal.
Route::get('job/{token}', [\App\Http\Controllers\Driver\LinkController::class, 'show'])
    ->middleware('throttle:60,1')->name('driver.link');
Route::post('job/{token}/status', [\App\Http\Controllers\Driver\LinkController::class, 'updateStatus'])
    ->middleware('throttle:60,1')->name('driver.link.status');
Route::post('job/{token}/reach-stop', [\App\Http\Controllers\Driver\LinkController::class, 'reachStop'])
    ->middleware('throttle:60,1')->name('driver.link.reach-stop');

// Additional-car links on a multi-car job (each extra driver, tracked per car).
Route::get('car/{token}', [\App\Http\Controllers\Driver\ExtraDriverController::class, 'show'])
    ->middleware('throttle:60,1')->name('driver.car');
Route::post('car/{token}/status', [\App\Http\Controllers\Driver\ExtraDriverController::class, 'updateStatus'])
    ->middleware('throttle:60,1')->name('driver.car.status');
Route::post('job/{token}/location', [\App\Http\Controllers\Driver\LinkController::class, 'location'])
    ->middleware('throttle:120,1')->name('driver.link.location');

// ----- Public customer TIP page (token in URL, no login) ------------------
// The customer thanks the driver with a card tip via Square-hosted checkout.
Route::get('tip/{token}', [\App\Http\Controllers\TipController::class, 'show'])
    ->middleware('throttle:60,1')->name('tip.show');
Route::post('tip/{token}', [\App\Http\Controllers\TipController::class, 'pay'])
    ->middleware('throttle:30,1')->name('tip.pay');
Route::get('tip/{token}/thanks', [\App\Http\Controllers\TipController::class, 'thanks'])
    ->middleware('throttle:60,1')->name('tip.thanks');
