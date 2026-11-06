<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Growth\BookingReviewExport;
use App\Services\Growth\GrowthDashboard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Growth dashboard: how outreach, social media, reviews and each booking
 * channel are performing, week by week. Read-only apart from the small
 * weekly social/reviews log the office types in. Nothing is sent anywhere.
 */
class GrowthController extends Controller
{
    public function index(Request $request, GrowthDashboard $dash): View
    {
        abort_unless($request->user()->isAdmin(), 403);

        $weeks = in_array((int) $request->query('weeks'), [4, 8, 13], true) ? (int) $request->query('weeks') : 8;

        return view('admin.growth.index', [
            'data' => $dash->build($weeks),
            'weeksShown' => $weeks,
            'channels' => GrowthDashboard::CHANNELS,
            'socialFields' => GrowthDashboard::SOCIAL_FIELDS,
        ]);
    }

    /**
     * Detailed bookings CSV for a business review. No names, phones, emails or
     * full addresses (see BookingReviewExport), so it can be shared for analysis.
     * Defaults to the last 6 months by the date bookings came in.
     */
    public function export(Request $request, BookingReviewExport $export): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'by' => ['nullable', 'in:booked,pickup'],
        ]);
        $by = $request->query('by') === 'pickup' ? 'pickup' : 'booked';
        $tz = config('app.timezone', 'Europe/London');
        $from = $request->filled('from')
            ? Carbon::parse($request->query('from'), $tz)->startOfDay()
            : now($tz)->subMonthsNoOverflow(6)->startOfDay();
        $to = $request->filled('to')
            ? Carbon::parse($request->query('to'), $tz)->endOfDay()
            : now($tz)->endOfDay();

        $filename = 'cet-bookings-'.($by === 'pickup' ? 'by-pickup-' : 'review-').$from->format('Ymd').'-'.$to->format('Ymd').'.csv';

        return response()->streamDownload(function () use ($export, $from, $to, $by) {
            $out = fopen('php://output', 'w');
            fputcsv($out, BookingReviewExport::COLUMNS);
            foreach ($export->rows($from, $to, $by) as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function saveSocial(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'week' => ['required', 'date_format:Y-m-d'],
            'instagram' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'facebook' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'linkedin' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'tiktok' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'google_reviews' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'google_rating' => ['nullable', 'numeric', 'min:0', 'max:5'],
            'posts' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ]);

        // Always store against the Monday of the chosen week.
        $monday = Carbon::parse($data['week'])->startOfWeek(Carbon::MONDAY)->toDateString();
        GrowthDashboard::saveWeek($monday, $data);

        return redirect()->route('marketing.growth')->with('status', 'Saved the numbers for the week of '.Carbon::parse($monday)->format('j M Y').'.');
    }
}
