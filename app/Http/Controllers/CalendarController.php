<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * A month calendar of every booking in the Command Centre, laid out day by day.
 * Reads the system's own bookings (so nothing that's in the system can be
 * missed, including jobs not on Google Calendar), grouped by pickup day. Click a
 * day to open its full Jobs list. Read-only — never touches Google Calendar.
 */
class CalendarController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->isAdmin(), 403);

        $month = ($request->date('month') ?? today())->startOfMonth();

        // The grid spans whole weeks (Mon–Sun) so every day has a cell.
        $gridStart = $month->copy()->startOfWeek(Carbon::MONDAY);
        $gridEnd = $month->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY);

        $byDay = Booking::with(['customer', 'driver', 'calendarEvent'])
            ->whereBetween('pickup_at', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()->endOfDay()])
            ->whereNotIn('status', [BookingStatus::Cancelled->value])
            ->orderBy('pickup_at')
            ->get()
            ->groupBy(fn (Booking $b) => $b->pickup_at->format('Y-m-d'));

        $weeks = [];
        $week = [];
        for ($d = $gridStart->copy(); $d <= $gridEnd; $d->addDay()) {
            $week[] = [
                'date' => $d->copy(),
                'in_month' => $d->month === $month->month,
                'is_today' => $d->isToday(),
                'jobs' => $byDay->get($d->format('Y-m-d'), collect()),
            ];
            if (count($week) === 7) {
                $weeks[] = $week;
                $week = [];
            }
        }

        return view('admin.calendar.index', [
            'month' => $month,
            'weeks' => $weeks,
            'total' => $byDay->flatten()->count(),
        ]);
    }
}
