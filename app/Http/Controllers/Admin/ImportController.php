<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Import\CustomerImporter;
use App\Services\Import\EtoBookingImporter;
use App\Services\Inbox\GraphMailClient;
use App\Services\Inbox\OutlookBookingService;
use App\Services\Marketing\AdsSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * In-app CSV imports so the operator never touches File Manager or the terminal:
 * upload a Google Ads report or an ETO bookings export straight into the app and
 * it runs the importer on the spot. Files are read from the request's temp upload
 * and never persisted (the ETO export contains customer PII).
 */
class ImportController extends Controller
{
    public function index(): View
    {
        return view('admin.imports.index', [
            'lastAds' => Setting::get('last_ads_import_at'),
            'lastEto' => Setting::get('last_eto_import_at'),
            'lastCustomers' => Setting::get('last_customers_import_at'),
            'lastEmailResync' => Setting::get('last_email_resync_at'),
            'emailConnected' => app(GraphMailClient::class)->configured(),
        ]);
    }

    public function ads(Request $request, AdsSyncService $ads): RedirectResponse
    {
        $this->validateCsv($request);

        $count = $ads->importCsv($request->file('file')->getRealPath());
        Setting::set('last_ads_import_at', now()->toDateTimeString(), 'string', 'ads');

        return back()->with('status', "Google Ads: imported {$count} period(s) of metrics. Open Review to see spend & ROAS.");
    }

    public function eto(Request $request, EtoBookingImporter $importer): RedirectResponse
    {
        $this->validateCsv($request);

        $stats = $importer->import($request->file('file')->getRealPath());
        Setting::set('last_eto_import_at', now()->toDateTimeString(), 'string', 'eto');

        $errors = count($stats['errors']) ? ' · '.count($stats['errors']).' error(s)' : '';

        return back()->with('status', "ETO import: {$stats['imported']} created, {$stats['updated']} updated (financials), {$stats['skipped']} skipped{$errors}.");
    }

    /** Import the ETO customers export: customers + corporate accounts. */
    public function customers(Request $request, CustomerImporter $importer): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $this->validateCsv($request);

        $s = $importer->import($request->file('file')->getRealPath());
        Setting::set('last_customers_import_at', now()->toDateTimeString(), 'string', 'customers');

        $errors = count($s['errors']) ? ' · '.count($s['errors']).' error(s)' : '';

        return back()->with('status', "Customers import: {$s['customers_created']} created, {$s['customers_updated']} updated; "
            ."{$s['accounts_created']} corporate account(s) created, {$s['accounts_linked']} customer(s) linked to accounts; {$s['skipped']} skipped{$errors}.");
    }

    /**
     * Resync with the ETO emails on demand — runs the Outlook ingest now instead
     * of waiting for the 2-minute schedule, so a booking that hasn't picked up its
     * latest email can be refreshed immediately. Matches by reference, updates in
     * place, protects office edits, fires NO notifications. No-op with a clear
     * message when the mailbox isn't connected.
     */
    public function resyncEmail(Request $request, OutlookBookingService $outlook, GraphMailClient $mail): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        if (! $mail->configured()) {
            return back()->with('status', 'Email isn’t connected yet — add the Microsoft/Outlook mailbox credentials on the server before a resync can read ETO emails. Nothing was changed.');
        }

        $days = (int) $request->integer('days', 30);
        $days = max(1, min(90, $days));
        $stats = $outlook->ingest($days);
        Setting::set('last_email_resync_at', now()->toDateTimeString(), 'string', 'eto');

        return back()->with('status', "Email resync: scanned {$stats['processed']} email(s) — {$stats['created']} created, {$stats['updated']} updated, {$stats['cancelled']} cancelled, {$stats['skipped']} unchanged/skipped. No notifications sent.");
    }

    private function validateCsv(Request $request): void
    {
        // Give an Excel upload a clear, actionable message rather than a generic
        // "wrong file" — ETO can export CSV, or the sheet can be saved as CSV.
        $ext = strtolower((string) $request->file('file')?->getClientOriginalExtension());
        if (in_array($ext, ['xlsx', 'xls'], true)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'file' => 'That\'s an Excel file. In EasyTaxiOffice choose "Export as CSV", '
                    .'or open the file and use File → Save As → CSV, then upload the .csv.',
            ]);
        }

        $request->validate([
            'file' => ['required', 'file', 'extensions:csv,txt', 'max:20480'], // 20 MB
        ], [
            'file.extensions' => 'Please upload a .csv file (exported from Google Ads or ETO). '
                .'If yours is an Excel/.xlsx file, export or save it as CSV first.',
        ]);
    }
}
