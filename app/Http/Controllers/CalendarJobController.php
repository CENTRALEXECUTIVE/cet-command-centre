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

    /**
     * Bulk version: pull EVERY calendar-only job shown for a day into bookings
     * in one tap, so the operator never adds them one at a time. Each is linked
     * to its existing event (no duplicates); already-imported ones are skipped.
     */
    public function storeAll(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'event_ids' => ['required', 'array'],
            'event_ids.*' => ['string'],
            'date' => ['nullable', 'date'],
        ]);

        $added = 0;
        $skipped = 0;
        foreach (array_unique($data['event_ids']) as $eventId) {
            $parsed = $this->calendar->eventToBookingData($eventId);
            if (! $parsed || ! $parsed['pickup_at'] || $this->importer->existingBookingFor($parsed)) {
                $skipped++;

                continue;
            }
            $booking = $this->importer->import($parsed, $request->user());
            $this->notifier->ensureReminders($booking->load('customer'));
            $added++;
        }

        $back = $request->input('date')
            ? redirect()->route('jobs.day', ['date' => $request->input('date')])
            : redirect()->route('jobs.day');

        return $back->with('status', "Added {$added} job(s) to bookings"
            .($skipped ? ", {$skipped} already in the system." : '.'));
    }
}
