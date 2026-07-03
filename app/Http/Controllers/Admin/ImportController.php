<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Import\EtoBookingImporter;
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

    private function validateCsv(Request $request): void
    {
        $request->validate([
            'file' => ['required', 'file', 'extensions:csv,txt', 'max:20480'], // 20 MB
        ], [
            'file.extensions' => 'Please upload a .csv file (exported from Google Ads or ETO).',
        ]);
    }
}
