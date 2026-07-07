<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use App\Models\VehicleType;
use App\Services\Ai\AnthropicService;
use App\Services\Calendar\GoogleCalendarService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns a pasted booking message (WhatsApp / email text) into a properly
 * formatted CET booking. The operator pastes the message, the AI extracts the
 * fields, and we build a NON-SAVED preview of the exact calendar title and
 * details block. Nothing is created and nothing touches the calendar until the
 * operator reviews the preview and explicitly confirms.
 */
class BookingIntakeService
{
    public function __construct(
        private readonly AnthropicService $ai,
        private readonly CalendarEventBuilder $calendar,
        private readonly RotationService $rotation,
        private readonly GoogleCalendarService $google,
    ) {}

    /**
     * Extract booking fields from free text with the AI. Returns a normalised
     * field array (empty strings where nothing was found) so the operator always
     * gets an editable form, even when the AI is unavailable.
     *
     * @return array<string, mixed>
     */
    public function parse(string $text): array
    {
        $system = 'You extract UK executive-taxi booking details from a pasted message. '
            .'Return ONLY a JSON object. Times are UK local (Europe/London). Use 24-hour HH:MM. '
            .'Keys: lead_name, contact_no, email, pickup_at (YYYY-MM-DD HH:MM), pickup_address, '
            .'destination_address, where (short airport code or destination word for the title, e.g. MAN, LHR, Sheffield), '
            .'flight_number, passengers (integer), luggage (integer), vehicle (one of: Executive, Estate, V Class, '
            .'Minibus 8 Seater, Rolls Royce Ghost), payment (cash|card|account), paid (true|false), '
            .'booked_by, notes. Use empty string when a field is not present.';

        $parsed = $this->ai->completeJson("Message:\n\n".$text, $system, ['max_tokens' => 800]) ?? [];

        return $this->normalise($parsed);
    }

    /**
     * Normalise a raw field array (from the AI or from the edited form) into the
     * canonical shape used by preview() and confirm().
     *
     * @param  array<string, mixed>  $in
     * @return array<string, mixed>
     */
    public function normalise(array $in): array
    {
        $get = fn (string $k) => is_scalar($in[$k] ?? null) ? trim((string) $in[$k]) : '';

        return [
            'lead_name' => $get('lead_name'),
            'contact_no' => $get('contact_no'),
            'email' => $get('email'),
            'pickup_at' => $get('pickup_at'),
            'pickup_address' => $get('pickup_address'),
            'destination_address' => $get('destination_address'),
            'where' => $get('where'),
            'flight_number' => $get('flight_number'),
            'passengers' => (int) ($get('passengers') ?: 1) ?: 1,
            'luggage' => (int) ($get('luggage') ?: 0),
            'vehicle' => $get('vehicle') ?: 'Executive',
            'payment' => in_array($get('payment'), ['cash', 'card', 'account'], true) ? $get('payment') : 'card',
            'paid' => filter_var($in['paid'] ?? false, FILTER_VALIDATE_BOOL),
            'booked_by' => $get('booked_by'),
            'notes' => $get('notes'),
        ];
    }

    /**
     * Build an UNSAVED booking carrying the parsed fields, so the calendar
     * builder can render a faithful preview without persisting anything.
     *
     * @param  array<string, mixed>  $f
     */
    public function draft(array $f): Booking
    {
        $vehicleType = $this->resolveVehicleType($f['vehicle'] ?? '');

        $booking = new Booking([
            'reference' => 'PREVIEW',
            'vehicle_type_id' => $vehicleType->id,
            'journey_type' => 'one_way',
            'is_return_leg' => false,
            'pickup_at' => $this->parseTime($f['pickup_at'] ?? ''),
            'pickup_address' => $f['pickup_address'] ?: null,
            'destination_address' => $f['destination_address'] ?: null,
            'flight_number' => $f['flight_number'] ?: null,
            'passengers' => (int) ($f['passengers'] ?? 1) ?: 1,
            'luggage' => (int) ($f['luggage'] ?? 0),
            'special_requests' => $f['notes'] ?: null,
            'status' => BookingStatus::Pending->value,
            'payment_method' => $f['payment'] ?? 'card',
            'payment_status' => ! empty($f['paid']) ? 'paid' : 'pending',
        ]);
        // In-memory relations + meta the calendar title/description read from.
        $booking->setRelation('vehicleType', $vehicleType);
        $booking->setRelation('customer', new Customer(['name' => $f['lead_name'] ?: 'Customer']));
        $booking->meta = array_filter([
            'lead_name' => $f['lead_name'] ?: null,
            'where' => $f['where'] ?: null,
            'contact_no' => $f['contact_no'] ?: null,
            'booked_by' => $f['booked_by'] ?: null,
        ]);

        return $booking;
    }

    /**
     * The CET calendar preview (title, location, description) for the fields —
     * nothing is saved.
     *
     * @param  array<string, mixed>  $f
     * @return array{title: string, location: ?string, description: string}
     */
    public function preview(array $f): array
    {
        return $this->calendar->preview($this->draft($f));
    }

    /**
     * Persist the booking the operator confirmed: customer, booking, rotation
     * allocation, then build the calendar event and push it to Google IF the
     * calendar is connected (otherwise it's left pending — never silently
     * dropped). Returns the saved booking.
     *
     * @param  array<string, mixed>  $f
     */
    public function confirm(array $f, ?User $creator): Booking
    {
        $f = $this->normalise($f);

        return DB::transaction(function () use ($f, $creator) {
            $vehicleType = $this->resolveVehicleType($f['vehicle']);
            $customer = $this->resolveCustomer($f);

            $booking = Booking::create([
                'reference' => Booking::generateReference(),
                'customer_id' => $customer->id,
                'vehicle_type_id' => $vehicleType->id,
                'journey_type' => 'one_way',
                'is_return_leg' => false,
                'pickup_at' => $this->parseTime($f['pickup_at']) ?? now(),
                'pickup_address' => $f['pickup_address'] ?: 'Unknown',
                'destination_address' => $f['destination_address'] ?: 'Unknown',
                'flight_number' => $f['flight_number'] ?: null,
                'passengers' => $f['passengers'],
                'luggage' => $f['luggage'],
                'special_requests' => $f['notes'] ?: null,
                'status' => BookingStatus::Pending,
                'payment_method' => $f['payment'],
                'payment_status' => $f['paid'] ? 'paid' : 'pending',
                'source' => 'phone',
                'created_by' => $creator?->id,
                'meta' => array_filter([
                    'lead_name' => $f['lead_name'] ?: null,
                    'where' => $f['where'] ?: null,
                    'contact_no' => $f['contact_no'] ?: null,
                    'booked_by' => $f['booked_by'] ?: null,
                    'created_from' => 'pasted_message',
                ]),
            ]);

            // Allocate a rotation driver for executive saloon jobs (so the
            // calendar tag is ABDI/MAJ, not COVER); other vehicles stay manual.
            $this->rotation->allocate($booking);

            $event = $this->calendar->buildFor($booking->refresh());

            // Push to Google only when the integration is live. Otherwise leave
            // the event pending for the normal sync — we never silently drop it.
            if ($this->google->active()) {
                $this->google->push($event);
            }

            return $booking;
        });
    }

    private function resolveCustomer(array $f): Customer
    {
        $phone = $f['contact_no'] ?: null;
        $email = $f['email'] ?: null;

        $customer = Customer::query()
            ->when($phone, fn ($q) => $q->orWhere('phone', $phone))
            ->when($email, fn ($q) => $q->orWhere('email', $email))
            ->first();

        return $customer ?? Customer::create([
            'name' => $f['lead_name'] ?: 'Customer',
            'phone' => $phone,
            'email' => $email,
        ]);
    }

    /** Map a free-text vehicle label to a seeded vehicle type; default Executive. */
    private function resolveVehicleType(string $label): VehicleType
    {
        $l = Str::lower($label);
        $slug = match (true) {
            str_contains($l, 'v class') || str_contains($l, 'v-class') || str_contains($l, 'vclass') => 'v-class',
            str_contains($l, 'rolls') => 'rolls-royce-ghost',
            str_contains($l, 'estate') => 'estate',
            str_contains($l, 'xl') => 'minibus-8-xl',
            str_contains($l, 'minibus') || str_contains($l, '8 seat') || str_contains($l, 'mini bus') => 'minibus-8',
            default => 'executive',
        };

        return VehicleType::where('slug', $slug)->first()
            ?? VehicleType::where('slug', 'executive')->firstOrFail();
    }

    /** Parse the "YYYY-MM-DD HH:MM" the AI/form gives us as UK local time. */
    private function parseTime(string $value): ?Carbon
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        foreach (['Y-m-d H:i', 'Y-m-d H:i:s', 'd/m/Y H:i', 'Y-m-d'] as $format) {
            try {
                return Carbon::createFromFormat($format, $value, config('app.timezone'));
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }
}
