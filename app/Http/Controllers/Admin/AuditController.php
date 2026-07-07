<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Import\EtoAuditService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * ETO reconciliation. Upload the EasyTaxiOffice bookings CSV and Command Centre
 * checks each reference one-by-one against the system and the calendar: is the
 * booking here, is it on the calendar, is the event synced and titled in CET
 * format, does the pickup time match, is a fare stored. Anything off is listed
 * and a marker is written onto the booking so it shows on its page. Read-only
 * against the calendar — it never edits an event. The CSV holds customer PII so
 * it is read from the temp upload and never stored.
 */
class AuditController extends Controller
{
    public function index(): View
    {
        abort_unless(request()->user()->isAdmin(), 403);

        return view('admin.audit.index');
    }

    public function run(Request $request, EtoAuditService $audit): View
    {
        abort_unless($request->user()->isAdmin(), 403);

        $ext = strtolower((string) $request->file('file')?->getClientOriginalExtension());
        if (in_array($ext, ['xlsx', 'xls'], true)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'file' => 'That\'s an Excel file. In EasyTaxiOffice choose "Export as CSV" '
                    .'(or open it and File → Save As → CSV), then upload the .csv.',
            ]);
        }
        $request->validate(['file' => ['required', 'file', 'max:20480']]);

        $report = $audit->audit($request->file('file')->getRealPath());

        return view('admin.audit.index', [
            'results' => $report['results'],
            'counts' => $report['counts'],
        ]);
    }
}
