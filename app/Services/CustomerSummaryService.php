<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use Illuminate\Support\Collection;

/**
 * Turns a customer's jobs into two ready-to-use, copy-and-paste texts:
 *
 *  - an INTERNAL summary (for the operator to read at a glance / paste into a
 *    note), and
 *  - a customer-facing CONFIRMATION EMAIL that lists every job and asks the
 *    customer to check the details are right.
 *
 * Built purely from the booking's own display accessors (the same source-of-
 * truth values shown on the booking page) — deterministic, no AI, no cost, and
 * nothing is sent: the operator copies the text and sends it themselves, in
 * keeping with the "nothing auto-sends to customers" rule.
 */
class CustomerSummaryService
{
    private const CANCELLEDISH = [
        BookingStatus::Cancelled->value,
        BookingStatus::NoShow->value,
    ];

    /**
     * The jobs to confirm, chronological (soonest first). By default this is the
     * customer's UPCOMING, non-cancelled jobs — the ones a confirmation is
     * actually about. When there are none upcoming, falls back to their most
     * recent jobs so the operator still gets something useful.
     */
    public function bookingsToConfirm(Customer $customer): Collection
    {
        $base = $customer->bookings()
            ->with(['vehicleType', 'calendarEvent'])
            ->whereNotIn('status', self::CANCELLEDISH);

        $upcoming = (clone $base)
            ->where('pickup_at', '>=', now()->startOfDay())
            ->orderBy('pickup_at')
            ->get();

        if ($upcoming->isNotEmpty()) {
            return $upcoming;
        }

        // No future jobs — show the most recent handful, oldest-first so the
        // email still reads in order.
        return (clone $base)
            ->orderByDesc('pickup_at')
            ->limit(5)
            ->get()
            ->sortBy('pickup_at')
            ->values();
    }

    /** Internal at-a-glance summary of the given jobs. */
    public function summary(Customer $customer, ?Collection $bookings = null): string
    {
        $bookings ??= $this->bookingsToConfirm($customer);

        if ($bookings->isEmpty()) {
            return $customer->name.' has no bookings to confirm.';
        }

        $lines = [];
        $lines[] = $customer->name.' — '.$bookings->count().' '.str('booking')->plural($bookings->count());
        if ($customer->phone) {
            $lines[] = 'Contact: '.$customer->phone.($customer->email ? ' · '.$customer->email : '');
        }
        $lines[] = '';

        foreach ($bookings->values() as $i => $b) {
            $lines[] = str_repeat('—', 32);
            $lines[] = ($i + 1).'. '.$b->reference.'  —  '.$b->pickup_at->format('D d M Y, H:i');
            foreach ($this->detailLines($b) as $line) {
                $lines[] = '   '.$line;
            }
        }

        return implode("\n", $lines);
    }

    /** Subject line for the confirmation email. */
    public function emailSubject(Customer $customer, ?Collection $bookings = null): string
    {
        $bookings ??= $this->bookingsToConfirm($customer);
        $refs = $bookings->pluck('reference')->filter()->implode(', ');

        return 'Please confirm your booking'
            .($bookings->count() > 1 ? 's' : '')
            .' with Central Executive Transfers'
            .($refs !== '' ? ' (Ref: '.$refs.')' : '');
    }

    /** Customer-facing confirmation email body. */
    public function emailBody(Customer $customer, ?Collection $bookings = null): string
    {
        $bookings ??= $this->bookingsToConfirm($customer);

        $lines = [];
        $lines[] = 'Hi '.$this->firstName($customer).',';
        $lines[] = '';

        if ($bookings->isEmpty()) {
            $lines[] = 'Thank you for choosing Central Executive Transfers.';
            $lines[] = '';
            $lines[] = 'Kind regards,';
            $lines[] = 'Central Executive Transfers Ltd';

            return implode("\n", $lines);
        }

        $many = $bookings->count() > 1;
        $lines[] = 'Thank you for booking with Central Executive Transfers. Please could you check the '
            .($many ? 'details of your journeys' : 'details below')
            .' and confirm everything is correct:';
        $lines[] = '';

        foreach ($bookings->values() as $i => $b) {
            $lines[] = ($many ? 'Journey '.($i + 1).' — ' : '').$b->pickup_at->format('l j F Y');
            foreach ($this->detailLines($b, customerFacing: true) as $line) {
                $lines[] = '  '.$line;
            }
            $lines[] = '';
        }

        $lines[] = 'If anything needs changing, just reply and let us know — otherwise a quick "all confirmed" is perfect.';
        $lines[] = '';
        $lines[] = 'Kind regards,';
        $lines[] = 'Central Executive Transfers Ltd';

        return implode("\n", $lines);
    }

    /**
     * The per-booking detail lines, shared by the internal summary and the
     * customer email. customerFacing drops the office-only lines (ref, driver)
     * and softens the labels.
     *
     * @return list<string>
     */
    private function detailLines(Booking $b, bool $customerFacing = false): array
    {
        $lines = [];

        if ($customerFacing) {
            $lines[] = 'Pick-up: '.$b->pickup_at->format('H:i').' — '.($b->displayPickupAddress() ?: 'to confirm');
        } else {
            $lines[] = 'Pickup: '.($b->displayPickupAddress() ?: '—');
        }
        $lines[] = ($customerFacing ? 'Drop-off: ' : 'Drop-off: ').($b->displayDropoffAddress() ?: '—');

        $pax = $b->passengerCount();
        $vehicle = $b->displayVehicleType();
        $paxVehicle = [];
        if ($pax !== null) {
            $paxVehicle[] = $pax.' '.str('passenger')->plural($pax);
        }
        if ($vehicle) {
            $paxVehicle[] = 'Vehicle: '.$vehicle;
        }
        if ($paxVehicle) {
            $lines[] = implode(' · ', $paxVehicle);
        }

        if ($flight = $b->displayFlightNumber()) {
            $mg = $b->displayMeetAndGreet();
            $lines[] = 'Flight: '.$flight.($mg ? ' · Meet & Greet: '.$mg : '');
        }

        if ($seats = $b->displayChildSeats()) {
            $lines[] = 'Child seats: '.$seats;
        }

        $fare = $b->fareAmount();
        if ($fare !== null) {
            $lines[] = ($customerFacing ? 'Price: ' : 'Price: ').'£'.number_format($fare, 2);
        }

        if (! $customerFacing) {
            if ($payment = $b->displayPayment()) {
                $lines[] = 'Payment: '.$payment;
            }
            if ($b->special_requests) {
                $lines[] = 'Notes: '.$b->special_requests;
            }
        }

        return $lines;
    }

    private function firstName(Customer $customer): string
    {
        $name = trim((string) $customer->name);

        return $name !== '' ? explode(' ', $name)[0] : 'there';
    }
}
