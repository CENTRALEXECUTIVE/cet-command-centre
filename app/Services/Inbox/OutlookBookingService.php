<?php

namespace App\Services\Inbox;

use App\Enums\BookingStatus;
use App\Models\Airport;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\VehicleType;
use App\Services\Ai\AnthropicService;
use App\Services\Calendar\GoogleCalendarService;
use App\Services\CalendarEventBuilder;
use App\Services\Pricing\FixedPriceService;
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
    ) {}

    /**
     * @return array{processed:int, created:int, updated:int, cancelled:int, skipped:int}
     */
    public function ingest(): array
    {
        $stats = ['processed' => 0, 'created' => 0, 'updated' => 0, 'cancelled' => 0, 'skipped' => 0];

        foreach ($this->mail->fetchUnread() as $message) {
            $stats['processed']++;
            $parsed = $this->parse($message['subject'] ?? '', $message['body'] ?? '', $message['from'] ?? null);

            $result = $parsed ? $this->upsertFromParsed($parsed) : null;

            if ($result) {
                $stats[$result['action']]++;
                $this->mail->markRead($message['id']);
            } else {
                $stats['skipped']++; // not a booking / unparseable — left unread for a human
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
     * @return array{booking: Booking, action: 'created'|'updated'|'cancelled'}|null
     */
    public function upsertFromParsed(array $parsed): ?array
    {
        $reference = $parsed['reference'] ?? null;

        $existing = $reference
            ? Booking::where('source_system', 'eto')->where('external_reference', $reference)->first()
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

        $vehicleType = $this->resolveVehicleType($parsed['vehicle_type'] ?? null);
        // Email times are UK local; the app runs in Europe/London, so parse in
        // the app timezone (config APP_TIMEZONE=Europe/London in production).
        $pickupAt = Carbon::parse($parsed['pickup_at']);

        return DB::transaction(function () use ($parsed, $reference, $existing, $vehicleType, $pickupAt) {
            $customer = $this->resolveCustomer($parsed);

            $fields = [
                'customer_id' => $customer->id,
                'vehicle_type_id' => $vehicleType->id,
                'airport_id' => $this->detectAirport($parsed),
                'pickup_at' => $pickupAt,
                'pickup_address' => $parsed['pickup_address'],
                'destination_address' => $parsed['destination_address'],
                'flight_number' => $parsed['flight_number'] ?? null,
                'passengers' => (int) ($parsed['passengers'] ?? 1),
            ];

            if ($existing) {
                $existing->forceFill($fields)->save();
                $booking = $existing;
                $action = 'updated';
            } else {
                $booking = Booking::create($fields + [
                    'reference' => Booking::generateReference(),
                    'source_system' => 'eto',
                    'external_reference' => $reference,
                    'status' => BookingStatus::Pending->value,
                    'payment_method' => 'card',
                    'source' => 'outlook',
                ]);
                $action = 'created';
            }

            $this->pushCalendar($booking->fresh(['customer', 'vehicleType', 'airport', 'driver']));

            return ['booking' => $booking, 'action' => $action];
        });
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
    ];

    private function detectAirport(array $parsed): ?int
    {
        $haystack = ($parsed['pickup_address'] ?? '').' '.($parsed['destination_address'] ?? '');

        // Preferred: an explicit IATA code in brackets, e.g. "(MAN)".
        if (preg_match('/\(([A-Z]{3})\)/', $haystack, $m)) {
            if ($id = Airport::where('code', $m[1])->value('id')) {
                return $id;
            }
        }

        // Fallback: recognise the airport by name or postcode area.
        $needle = strtolower($haystack);
        foreach (self::AIRPORT_ALIASES as $code => $aliases) {
            foreach ($aliases as $alias) {
                if (str_contains($needle, $alias)) {
                    return Airport::where('code', $code)->value('id');
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
