<?php

namespace App\Http\Controllers;

use App\Services\Calendar\CalendarJobImporter;
use App\Services\Calendar\CalendarStats;
use App\Services\Messaging\BookingNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Pulls a "calendar only" job (one that exists on the Google Calendar but not in
 * the booking database) into the system as a real booking, so it can be edited
 * and messaged. The existing calendar event is LINKED (not re-created), so the
 * calendar sync never pushes a duplicate. The scheduled refresh does the same
 * automatically (CalendarJobImporter) — this button remains for immediacy.
 */
class CalendarJobController extends Controller
{
    public function __construct(
        private readonly CalendarStats $calendar,
        private readonly BookingNotifier $notifier,
        private readonly CalendarJobImporter $importer,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate(['event_id' => ['required', 'string']]);

        $parsed = $this->calendar->eventToBookingData($data['event_id']);
        if (! $parsed || ! $parsed['pickup_at']) {
            return back()->with('status', 'Could not read that calendar event — try refreshing the day.');
        }

        // Already in the system? Just go there.
        if ($existing = $this->importer->existingBookingFor($parsed)) {
            return redirect()->route('bookings.show', $existing)
                ->with('status', 'This job is already in bookings.');
        }

        $booking = $this->importer->import($parsed, $request->user());

        $this->notifier->ensureReminders($booking->load('customer'));

        return redirect()->route('bookings.show', $booking)
            ->with('status', 'Added to bookings — you can now edit it and send the customer a message.');
    }
}
