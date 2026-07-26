<?php

namespace App\Services\Inbox;

use App\Enums\BookingStatus;
use App\Models\Airport;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\RotationLog;
use App\Models\VehicleType;
use App\Services\Ai\AnthropicService;
use App\Services\Calendar\GoogleCalendarService;
use App\Services\CalendarEventBuilder;
use App\Services\Pricing\FixedPriceService;
use App\Services\RotationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reads ETO booking emails from the mailbox and lands them in Google Calendar.
 *
 * Bookings are matched on the ETO **booking reference**: the first email for a
 * reference creates the booking + calendar event; later emails for the same
 * reference (changed time/address/etc.) UPDATE the same booking and its calendar
 * event — never a duplicate. A cancellation email cancels the booking.
 *
 * Created directly (not via BookingService) so ETO emails do NOT trigger
 * customer WhatsApp/payment side effects — they simply appear on the calendar in
 * the correct UK time and format. The Google push is best-effort here and the
 * cet:sync-calendar schedule retries anything left pending.
 */
class OutlookBookingService
{
    public function __construct(
        private readonly GraphMailClient $mail,
        private readonly AnthropicService $ai,
        private readonly EtoEmailParser $etoParser,
        private readonly FixedPriceService $fixedPrices,
        private readonly CalendarEventBuilder $calendarBuilder,
        private readonly GoogleCalendarService $googleCalendar,
        private readonly RotationService $rotation,
    ) {}

    /**
     * @param  int  $days  How many days of inbox history to scan.
     * @param  bool  $allocateRotation  Auto-assign ABDI/MAJ (true for the live
     *   feed). A bulk backfill passes false and applies drivers from the list.
     * @return array{processed:int, created:int, updated:int, cancelled:int, skipped:int}
     */
    public function ingest(int $days = 30, bool $allocateRotation = true): array
    {
        $stats = ['processed' => 0, 'created' => 0, 'updated' => 0, 'cancelled' => 0, 'skipped' => 0];

        // Scan recent READ and UNREAD emails — read/unread is irrelevant; the
        // booking reference decides what happens. An email a human opened still
        // gets added if it's not on the calendar. Emails are NOT marked read, so
        // the operator keeps their own read/unread view.
        $parsedList = [];
        foreach ($this->mail->fetchRecent($days) as $message) {
            $stats['processed']++;
            $parsed = $this->parse($message['subject'] ?? '', $message['body'] ?? '', $message['from'] ?? null);
            if ($parsed) {
                $parsedList[] = $parsed;
            } else {
                $stats['skipped']++; // not a booking / unparseable
            }
        }

        // Process in BOOKING order — oldest email first (fetchRecent returns
        // newest-first). First booked → first driver in the rotation.
        $parsedList = array_reverse($parsedList);

        foreach ($parsedList as $parsed) {
            $reference = $parsed['reference'] ?? null;
            $existing = $reference
                ? (Booking::where('source_system', 'eto')->where('external_reference', $reference)->first()
                    ?? Booking::resolveByReference($reference))
                : null;

            // A finished job is locked — never re-write a completed/cancelled/
            // no-show booking from a re-read email (it would only churn and risk
            // clobbering what the office has recorded, e.g. driver pay).
            if ($existing && $existing->status->isTerminal()) {
                $stats['skipped']++;

                continue;
            }

            // Already on the calendar and nothing changed → leave it (no re-push).
            if ($existing && $this->alreadyCurrent($existing, $parsed)) {
                $stats['skipped']++;

                continue;
            }

            try {
                $result = $this->upsertFromParsed($parsed, $allocateRotation);
                $result ? $stats[$result['action']]++ : $stats['skipped']++;
            } catch (\Illuminate\Database\QueryException $e) {
                // A concurrent run already created this reference (unique index) —
                // not a duplicate on the calendar, just skip it this pass.
                $stats['skipped']++;
                \Illuminate\Support\Facades\Log::warning('Ingest upsert skipped (likely concurrent create)', [
                    'reference' => $reference, 'error' => $e->getMessage(),
                ]);
            }
        }

        return $stats;
    }

    /**
     * Parse an email into structured booking fields (incl. the ETO reference).
     *
     * @return array<string, mixed>|null
     */
    public function parse(string $subject, string $body, ?string $from = null): ?array
    {
        // Free, deterministic path first: ETO emails are a fixed template, so we
        // read them directly — no AI, no cost. The AI below is only an optional
        // fallback for the rare email this can't read, and only if a key is set.
        if ($parsed = $this->etoParser->parse($subject, $body, $from)) {
            return $parsed;
        }

        if (! $this->ai->configured()) {
            return null;
        }

        $system = 'You extract chauffeur booking details from EasyTaxiOffice (ETO) booking emails for '
            .'Central Executive Transfers. Times are UK local time (Europe/London). Respond ONLY with JSON. '
            .'If the email is not a booking/amendment/cancellation, respond {"is_booking": false}. Otherwise: '
            .'{"is_booking": true, "reference": string|null, "cancelled": boolean, "customer_name": string, '
            .'"customer_phone": string|null, "customer_email": string|null, "pickup_address": string, '
            .'"destination_address": string, "pickup_at": "YYYY-MM-DD HH:MM", "passengers": number, '
            .'"vehicle_type": string|null, "flight_number": string|null}. "reference" is the ETO booking '
            .'reference. "cancelled" is true if the email cancels the booking. vehicle_type is one of: '
            .'Executive, Estate, V Class, 8 Seater, 8 Seater XL, Luxury.';

        $data = $this->ai->completeJson("From: {$from}\nSubject: {$subject}\n\n{$body}", $system, ['max_tokens' => 800]);

        if (! $data || empty($data['is_booking'])) {
            return null;
        }

        // Always try to capture a reference (AI value, else a regex fallback).
        $data['reference'] = $this->cleanReference($data['reference'] ?? null)
            ?? $this->extractReference($subject."\n".$body);
        $data['customer_email'] = $data['customer_email'] ?? $from;

        // A cancellation only needs the reference; a new/amended booking needs the journey.
        $isCancel = ! empty($data['cancelled']);
        if (! $isCancel && (empty($data['pickup_address']) || empty($data['destination_address']))) {
            return null;
        }

        return $data;
    }

    /**
     * Create or update a booking (by ETO reference) and (re)build + push its
     * calendar event.
     *
     * @param  array<string, mixed>  $parsed
     * @param  bool  $allocateRotation  Allocate the rotation driver on create
     *   (true for the live email feed). A bulk CSV backfill passes false so it
     *   leaves the rotation pointer untouched — those jobs are assigned manually.
     * @return array{booking: Booking, action: 'created'|'updated'|'cancelled'}|null
     */
    public function upsertFromParsed(array $parsed, bool $allocateRotation = true): ?array
    {
        $reference = $parsed['reference'] ?? null;

        $existing = $reference
            ? (Booking::where('source_system', 'eto')->where('external_reference', $reference)->first()
                ?? Booking::resolveByReference($reference))
            : null;

        // Cancellation.
        if (! empty($parsed['cancelled'])) {
            if (! $existing) {
                return null; // nothing to cancel
            }
            $existing->forceFill(['status' => BookingStatus::Cancelled->value])->save();
            $this->pushCalendar($existing);

            return ['booking' => $existing, 'action' => 'cancelled'];
        }

        $paymentStatus = strtolower((string) ($parsed['payment_status'] ?? 'pending'));

        // Every confirmed ETO booking is imported, even when still "Pending Pay
        // now": it lands marked 👀 (full balance outstanding). When payment
        // arrives, the same reference updates this booking to paid and the emoji
        // clears — no duplicate.
        $vehicleType = $this->resolveVehicleType($parsed['vehicle_type'] ?? null);
        // Email times are UK local; the app runs in Europe/London, so parse in
        // the app timezone (config APP_TIMEZONE=Europe/London in production).
        $pickupAt = Carbon::parse($parsed['pickup_at']);

        return DB::transaction(function () use ($parsed, $reference, $existing, $vehicleType, $pickupAt, $paymentStatus, $allocateRotation) {
            $customer = $this->resolveCustomer($parsed);
            $airportId = $this->detectAirport($parsed);

            $fields = [
                'customer_id' => $customer->id,
                'vehicle_type_id' => $vehicleType->id,
                'airport_id' => $airportId,
                'pickup_at' => $pickupAt,
                'pickup_address' => $parsed['pickup_address'],
                'destination_address' => $parsed['destination_address'],
                'flight_number' => $parsed['flight_number'] ?? null,
                'passengers' => (int) ($parsed['passengers'] ?? 1),
                'luggage' => (int) ($parsed['luggage'] ?? 0),
                'special_requests' => $parsed['notes'] ?? null,
                'payment_status' => $paymentStatus,
                'payment_method' => $this->paymentMethod($parsed['payment_method'] ?? null),
                // Store the fare in the money columns (not just meta) so revenue
                // reporting works. The ETO total is the agreed fare; mark it as
                // final once paid, otherwise it stands as the quoted price.
                'quoted_price' => $parsed['total_amount'] ?? null,
                'final_price' => $paymentStatus === 'paid' ? ($parsed['total_amount'] ?? null) : null,
                'meta' => $this->meta($parsed, $airportId),
            ];

            if ($existing) {
                // Preserve app-added meta (driver PAYROLL, link tokens, audit
                // flags) — the email only owns the booking-detail fields, never
                // the money and tokens we've recorded. Replacing meta wholesale
                // here was wiping driver pay every 5-minute ingest run.
                $fields['meta'] = array_merge($existing->meta ?? [], $fields['meta']);
                $existing->forceFill($fields)->save();
                $booking = $existing;
                $action = 'updated';
            } else {
                $booking = Booking::create($fields + [
                    'reference' => Booking::generateReference(),
                    'source_system' => 'eto',
                    'external_reference' => $reference,
                    'status' => BookingStatus::Pending->value,
                    'source' => 'outlook',
                ]);
                $action = 'created';

                // Allocate the rotation driver (ABDI/MAJ) for Executive saloon
                // jobs only — paired outbound/return share a driver and advance
                // the rotation once. No-op for non-rotation vehicles, only on
                // create. Skipped for bulk backfill so it doesn't disturb the pointer.
                if ($allocateRotation) {
                    $this->allocateDriver($booking, $reference);
                }
            }

            $this->pushCalendar($booking->fresh(['customer', 'vehicleType', 'airport', 'driver']));

            return ['booking' => $booking, 'action' => $action];
        });
    }

    /** "1 Suitcase + 1 Hand Luggage" — descriptive, never a bare number. */
    private function luggageText(int $suitcases, int $hand): string
    {
        $parts = [];
        if ($suitcases > 0) {
            $parts[] = $suitcases.' Suitcase'.($suitcases > 1 ? 's' : '');
        }
        if ($hand > 0) {
            $parts[] = $hand.' Hand Luggage';
        }

        return $parts ? implode(' + ', $parts) : 'None';
    }

    /** Map the ETO payment label (Square/Stripe/Cash/Card) to our method enum. */
    private function paymentMethod(?string $label): string
    {
        return $label && stripos($label, 'cash') !== false ? 'cash' : 'card';
    }

    /**
     * Extra ETO details the calendar description needs, stored on the booking's
     * meta. journey_label drives the "Departure / Arrival / Transfer" heading.
     *
     * @param  array<string, mixed>  $parsed
     * @return array<string, mixed>
     */
    private function meta(array $parsed, ?int $airportId): array
    {
        $pickup = strtolower($parsed['pickup_address'] ?? '');
        $dropoff = strtolower($parsed['destination_address'] ?? '');
        $meetGreet = ! empty($parsed['meet_and_greet']);

        // Airport in the PICKUP → an arrival (meeting an inbound flight);
        // airport in the DROPOFF → a departure; otherwise a point-to-point transfer.
        $pickupIsAirport = $airportId && str_contains($pickup, 'airport');
        $dropoffIsAirport = str_contains($dropoff, 'airport');

        if ($pickupIsAirport) {
            $label = $meetGreet ? 'Arrival (Meet & Greet)' : 'Arrival';
        } elseif ($dropoffIsAirport || $airportId) {
            $label = 'Departure';
        } else {
            $label = 'Transfer';
        }

        $childSeats = (int) ($parsed['child_seats'] ?? 0);
        $boosterSeats = (int) ($parsed['booster_seats'] ?? 0);

        return array_filter([
            'journey_label' => $label,
            // Title location: airport code, else FREE ROAM (rule 8).
            'where' => $this->airportCodeFor($parsed) ?? 'FREE ROAM',
            // Descriptive luggage, never a bare number (e.g. "1 Suitcase + 1 Hand Luggage").
            'luggage_text' => $this->luggageText((int) ($parsed['suitcases'] ?? 0), (int) ($parsed['hand_luggage'] ?? ($parsed['luggage'] ?? 0))),
            // Discrete counts so the booking pages can show the suitcase / hand
            // luggage split, not just the combined total.
            'suitcases' => (int) ($parsed['suitcases'] ?? 0),
            'hand_luggage' => (int) ($parsed['hand_luggage'] ?? ($parsed['luggage'] ?? 0)),
            // Always show the lead passenger, never the booker/company (rule 9).
            'lead_name' => $parsed['customer_name'] ?? null,
            'meet_and_greet' => $meetGreet,
            'child_seat' => ! empty($parsed['child_seat']) || $childSeats > 0 || $boosterSeats > 0,
            'child_seats' => $childSeats,
            'booster_seats' => $boosterSeats,
            'stops' => $parsed['stops'] ?? [],
            'payment_text' => $parsed['payment_text'] ?? null,
            'payment_method_label' => $parsed['payment_method'] ?? null,
            'total_amount' => $parsed['total_amount'] ?? null,
            'booker_name' => $parsed['booker_name'] ?? null,
            'contact_no' => $parsed['customer_phone'] ?? null,
        ], fn ($v) => $v !== null && $v !== []);
    }

    /**
     * Is this booking already correctly on the calendar for what the email says?
     * If so we skip it (no needless re-push). Returns false whenever something
     * material changed — a cancellation, a payment update, or amended time/route
     * — so those still flow through.
     *
     * @param  array<string, mixed>  $parsed
     */
    private function alreadyCurrent(Booking $existing, array $parsed): bool
    {
        if (! empty($parsed['cancelled'])) {
            return $existing->status === BookingStatus::Cancelled;
        }

        $event = $existing->calendarEvent;
        if (! $event || $event->sync_status !== 'synced') {
            return false; // not on the calendar yet (or failed) → (re)push
        }

        $sameTime = $existing->pickup_at?->format('Y-m-d H:i') === Carbon::parse($parsed['pickup_at'])->format('Y-m-d H:i');

        return $sameTime
            && strtolower((string) $existing->payment_status) === strtolower((string) ($parsed['payment_status'] ?? 'pending'))
            && trim((string) $existing->pickup_address) === trim((string) ($parsed['pickup_address'] ?? ''))
            && trim((string) $existing->destination_address) === trim((string) ($parsed['destination_address'] ?? ''))
            // Passenger/luggage too, so an email enriches a booking that came from
            // the CSV with placeholder counts (the email is the real source).
            && (int) $existing->passengers === (int) ($parsed['passengers'] ?? 1)
            && (int) $existing->luggage === (int) ($parsed['luggage'] ?? 0);
    }

    /**
     * Assign the rotation driver for a new Executive saloon job, honouring paired
     * outbound/return bookings. ETO marks the two legs with a trailing lowercase
     * 'a' (outbound) / 'b' (return) on the same base reference; both legs get the
     * SAME driver and the rotation advances only ONCE.
     */
    private function allocateDriver(Booking $booking, ?string $reference): void
    {
        $sibling = $this->pairedSibling($reference);
        if (! $sibling) {
            $this->rotation->allocate($booking); // standalone — normal rotation

            return;
        }

        $isReturn = $reference !== null && str_ends_with($reference, 'b');

        if ($sibling->driver_id) {
            // The other leg already has the driver → match it, do NOT advance.
            $booking->forceFill([
                'driver_id' => $sibling->driver_id,
                'is_return_leg' => $isReturn,
                'linked_booking_id' => $sibling->id,
                'journey_type' => 'return',
            ])->save();

            // Audit the paired assignment so the driver-order history is complete.
            RotationLog::create([
                'airport_id' => $booking->airport_id,
                'vehicle_type_id' => $booking->vehicle_type_id,
                'booking_id' => $booking->id,
                'from_driver_id' => null,
                'to_driver_id' => $sibling->driver_id,
                'reason' => 'paired_same_driver',
            ]);
        } else {
            // First leg seen → normal rotation (advances once); the other matches.
            $this->rotation->allocate($booking);
            $booking->forceFill([
                'is_return_leg' => $isReturn,
                'linked_booking_id' => $sibling->id,
                'journey_type' => 'return',
            ])->save();
        }

        $sibling->forceFill(['linked_booking_id' => $booking->id, 'journey_type' => 'return'])->save();
    }

    /** The other leg of a paired ETO booking (…a ↔ …b), if it exists. */
    private function pairedSibling(?string $reference): ?Booking
    {
        if (! $reference || ! preg_match('/^([A-Za-z0-9]{4,})([ab])$/', $reference, $m)) {
            return null;
        }
        $base = $m[1];

        return Booking::where('source_system', 'eto')
            ->whereIn('external_reference', [$base.'a', $base.'b'])
            ->where('external_reference', '!=', $reference)
            ->first();
    }

    /** Build the formatted calendar event and push it to Google (best-effort). */
    private function pushCalendar(Booking $booking): void
    {
        $event = $this->calendarBuilder->buildFor($booking);
        $this->googleCalendar->push($event); // no-op until credentials are set; sync-calendar retries
    }

    private function resolveCustomer(array $parsed): Customer
    {
        $phone = $parsed['customer_phone'] ?? null;
        $email = $parsed['customer_email'] ?? null;

        $customer = Customer::query()
            ->when($phone, fn ($q) => $q->orWhere('phone', $phone))
            ->when($email, fn ($q) => $q->orWhere('email', $email))
            ->first();

        return $customer ?? Customer::create([
            'name' => $parsed['customer_name'] ?? 'Email customer',
            'phone' => $phone,
            'email' => $email,
        ]);
    }

    private function resolveVehicleType(?string $label): VehicleType
    {
        $slug = ($label ? $this->fixedPrices->slugForColumn($label) : null) ?? 'executive';

        return VehicleType::where('slug', $slug)->first()
            ?? VehicleType::where('slug', 'executive')->firstOrFail();
    }

    /**
     * Airport name/postcode aliases — ETO emails don't always include the IATA
     * code in brackets (e.g. "Manchester Airport M90 1QX"), so match on the
     * airport name or its postcode-area prefix as well.
     *
     * @var array<string, list<string>>
     */
    private const AIRPORT_ALIASES = [
        'MAN' => ['manchester airport', 'm90'],
        'LHR' => ['heathrow', 'tw6'],
        'LGW' => ['gatwick', 'rh6'],
        'STN' => ['stansted', 'cm24'],
        'EMA' => ['east midlands airport', 'nottingham east midlands', 'de74'],
        'LBA' => ['leeds bradford', 'ls19'],
        'BHX' => ['birmingham airport', 'b26'],
        'LPL' => ['liverpool john lennon', 'liverpool airport', 'l24'],
        'HUY' => ['humberside airport', 'dn39'],
        'LTN' => ['luton airport', 'london luton', 'lu2'],
    ];

    private function detectAirport(array $parsed): ?int
    {
        $code = $this->airportCodeFor($parsed);

        return $code ? Airport::where('code', $code)->value('id') : null;
    }

    /**
     * IATA code for the job from the addresses — a bracketed code, else an
     * airport name or postcode area (covers airport-hotel jobs, rule 8).
     *
     * @param  array<string, mixed>  $parsed
     */
    private function airportCodeFor(array $parsed): ?string
    {
        $haystack = ($parsed['pickup_address'] ?? '').' '.($parsed['destination_address'] ?? '');

        if (preg_match('/\(([A-Z]{3})\)/', $haystack, $m) && Airport::where('code', $m[1])->exists()) {
            return $m[1];
        }

        $needle = strtolower($haystack);
        foreach (self::AIRPORT_ALIASES as $code => $aliases) {
            foreach ($aliases as $alias) {
                if (str_contains($needle, $alias)) {
                    return $code;
                }
            }
        }

        return null;
    }

    private function cleanReference(?string $reference): ?string
    {
        $reference = trim((string) $reference);

        return $reference !== '' ? $reference : null;
    }

    /** Fallback: pull a reference token out of the email text. */
    private function extractReference(string $text): ?string
    {
        if (preg_match('/\b(?:booking\s*)?(?:ref(?:erence)?|reference\s*number|booking\s*number)\b[^A-Z0-9]{0,8}([A-Z0-9]{5,12})/i', $text, $m)) {
            return $m[1];
        }

        return null;
    }
}
