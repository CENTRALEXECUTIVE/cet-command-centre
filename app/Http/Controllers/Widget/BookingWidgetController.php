<?php

namespace App\Http\Controllers\Widget;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\VehicleType;
use App\Services\Payments\SquareBookingPaymentService;
use App\Services\Pricing\QuoteService;
use App\Services\Watchdog\AdminAlerts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;

/**
 * Public, embeddable web-booking widgets (mirrors EasyTaxiOffice's Web Widgets):
 * a "mini" quick-price checker that a customer uses on the marketing site. Served
 * from the Command Centre and dropped into the website by iframe — the live site
 * itself is never touched. Uses the existing CET pricing (fixed matrix + free-roam
 * distance), so no AI cost and nothing is written per keystroke.
 */
class BookingWidgetController extends Controller
{
    public function __construct(
        private readonly QuoteService $quotes,
        private readonly AdminAlerts $adminAlerts,
        private readonly SquareBookingPaymentService $payments,
    ) {}

    /** Domains allowed to embed the widget in an iframe. */
    private function frameAncestors(): string
    {
        return "frame-ancestors 'self' https://centralexecutivetransfers.co.uk "
            .'https://*.centralexecutivetransfers.co.uk http://localhost:* http://127.0.0.1:*';
    }

    /** The mini quick-price widget page (embeddable). */
    public function mini(): \Illuminate\Http\Response
    {
        $vehicleTypes = VehicleType::where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'name', 'slug', 'passenger_capacity', 'luggage_capacity']);

        return response()
            ->view('widget.mini', ['vehicleTypes' => $vehicleTypes])
            ->header('Content-Security-Policy', $this->frameAncestors());
    }

    /** Instant price for the widget (JSON). Lightweight — no AI, no saved quote. */
    public function price(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pickup' => ['required', 'string', 'max:500'],
            'destination' => ['required', 'string', 'max:500'],
            'vehicle_type_id' => ['required', Rule::exists('vehicle_types', 'id')],
        ]);

        $vehicleType = VehicleType::findOrFail($data['vehicle_type_id']);
        $result = $this->quotes->quote($data['pickup'], $data['destination'], $vehicleType);

        return response()->json([
            'price' => $result['price'],
            'basis' => $result['basis'],
            'fixed' => $result['fixed'],
            'formatted' => $result['price'] !== null ? '£'.number_format($result['price'], 0) : 'Price on request',
            'vehicle' => $vehicleType->name,
        ]);
    }

    /** The full booking widget page (embeddable): complete a booking REQUEST. */
    public function book(): \Illuminate\Http\Response
    {
        $vehicleTypes = VehicleType::where('is_active', true)
            // Minibus XL isn't offered directly — a standard Minibus booking is
            // auto-upgraded to it on the office side when the party/luggage is
            // too big (see autoUpgradeMinibus in store()).
            ->where('slug', '!=', 'minibus-8-xl')
            ->orderBy('sort_order')
            ->get(['id', 'name', 'slug', 'passenger_capacity', 'luggage_capacity']);

        return response()
            ->view('widget.book', ['vehicleTypes' => $vehicleTypes, 'done' => false])
            ->header('Content-Security-Policy', $this->frameAncestors());
    }

    /**
     * Take a full booking REQUEST from the public widget. It lands in the Command
     * Centre as a PENDING booking for the office to confirm — no payment is taken,
     * nothing is auto-sent to the customer, and the calendar is untouched. The
     * office is notified so they can review and confirm it.
     */
    public function store(Request $request): \Illuminate\Http\Response
    {
        // Anti-spam honeypot: a hidden field real users never fill.
        if (filled($request->input('company'))) {
            return response()->view('widget.book', ['vehicleTypes' => collect(), 'done' => true])
                ->header('Content-Security-Policy', $this->frameAncestors());
        }

        $data = $request->validate([
            'journey_type' => ['nullable', Rule::in(['one_way', 'return', 'hourly'])],
            'pickup_address' => ['required', 'string', 'max:500'],
            'destination_address' => ['required_unless:journey_type,hourly', 'nullable', 'string', 'max:500'],
            'pickup_at' => ['required', 'date', 'after:now'],
            'return_pickup_at' => ['nullable', 'required_if:journey_type,return', 'date', 'after:pickup_at'],
            'hours' => ['nullable', 'required_if:journey_type,hourly', 'integer', 'min:1', 'max:24'],
            'vehicle_type_id' => ['required', Rule::exists('vehicle_types', 'id')],
            'passengers' => ['required', 'integer', 'min:1', 'max:60'],
            'suitcases' => ['nullable', 'integer', 'min:0', 'max:30'],
            'hand_luggage' => ['nullable', 'integer', 'min:0', 'max:30'],
            'flight_number' => ['nullable', 'string', 'max:32'],
            'customer_name' => ['required', 'string', 'max:120'],
            'customer_phone' => ['nullable', 'string', 'max:32', 'required_without:customer_email'],
            'customer_email' => ['nullable', 'email', 'max:160', 'required_without:customer_phone'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [], ['customer_phone' => 'phone', 'customer_email' => 'email']);

        $journeyType = $data['journey_type'] ?? 'one_way';
        $isHourly = $journeyType === 'hourly';
        $isReturn = $journeyType === 'return';
        $destination = $isHourly ? 'As directed (hourly hire)' : $data['destination_address'];

        $vehicleType = VehicleType::findOrFail($data['vehicle_type_id']);
        // A big party or lots of luggage on a standard Minibus is bumped up to
        // the Minibus XL automatically — the customer only ever picks "Minibus".
        $vehicleType = $this->autoUpgradeMinibus(
            $vehicleType,
            (int) $data['passengers'],
            (int) ($data['suitcases'] ?? 0) + (int) ($data['hand_luggage'] ?? 0),
        );
        $pickupAt = Carbon::createFromFormat('Y-m-d\TH:i', $data['pickup_at'], config('app.timezone'))
            ?: Carbon::parse($data['pickup_at']);

        // A guide price from the existing engine, stored for the office. Hourly
        // hire has no fixed route — the office confirms the price.
        $quote = $isHourly
            ? ['price' => null, 'basis' => 'Hourly hire — office to confirm', 'fixed' => false]
            : $this->quotes->quote($data['pickup_address'], $destination, $vehicleType);

        $customer = $this->resolveCustomer($data);

        $booking = Booking::create([
            'reference' => Booking::generateReference(),
            'customer_id' => $customer->id,
            'vehicle_type_id' => $vehicleType->id,
            'journey_type' => $journeyType,
            'pickup_at' => $pickupAt,
            'pickup_address' => $data['pickup_address'],
            'destination_address' => $destination,
            'passengers' => $data['passengers'],
            'flight_number' => $data['flight_number'] ?? null,
            'special_requests' => $data['notes'] ?? null,
            'status' => BookingStatus::Pending->value,
            // No card is taken at booking time — the customer pays on the day, so
            // the DRIVER collects the fare in cash. If they later pay online via
            // Square, markFarePaid() records it and the driver-collect logic then
            // shows "collect nothing" (Square counts as the business collecting).
            // Stamping this 'card' told the driver to collect nothing and lost the
            // fare. The office can switch it to card/account when confirming.
            'payment_method' => 'cash',
            'payment_status' => 'pending',
            'source' => 'web',
            'quoted_price' => $quote['price'],
            'meta' => array_filter([
                'web_booking' => true,
                'suitcases' => (int) ($data['suitcases'] ?? 0),
                'hand_luggage' => (int) ($data['hand_luggage'] ?? 0),
                'driver_notes' => $data['notes'] ?? null,
                'web_quote_basis' => $quote['basis'],
                'hourly_hours' => $isHourly ? (int) $data['hours'] : null,
            ], fn ($v) => $v !== null && $v !== ''),
        ]);

        // Return trip: create the linked inbound leg (office confirms; no rotation
        // or calendar until then). Fare stays on the outbound so revenue isn't
        // double-counted.
        if ($isReturn) {
            $returnAt = Carbon::createFromFormat('Y-m-d\TH:i', $data['return_pickup_at'], config('app.timezone'))
                ?: Carbon::parse($data['return_pickup_at']);
            $return = Booking::create([
                'reference' => Booking::generateReference(),
                'customer_id' => $customer->id,
                'vehicle_type_id' => $vehicleType->id,
                'journey_type' => 'return',
                'is_return_leg' => true,
                'linked_booking_id' => $booking->id,
                'pickup_at' => $returnAt,
                'pickup_address' => $destination,
                'destination_address' => $data['pickup_address'],
                'passengers' => $data['passengers'],
                'special_requests' => $data['notes'] ?? null,
                'status' => BookingStatus::Pending->value,
                // Cash by default (see the outbound leg above). A return leg never
                // collects on the day anyway — the outbound carries the fare.
                'payment_method' => 'cash',
                'payment_status' => 'pending',
                'source' => 'web',
                'meta' => array_filter([
                    'web_booking' => true,
                    'suitcases' => (int) ($data['suitcases'] ?? 0),
                    'hand_luggage' => (int) ($data['hand_luggage'] ?? 0),
                ], fn ($v) => $v !== null && $v !== ''),
            ]);
            $booking->forceFill(['linked_booking_id' => $return->id])->save();
        }

        // Confirmation email to the customer who just used our widget — strictly
        // web-only and off by default (see CustomerBookingMailer). Never reaches
        // ETO/existing customers.
        app(\App\Services\Messaging\CustomerBookingMailer::class)->confirmIfWebBooking($booking);

        // Tell the office at once — a web request needs a human to confirm.
        $name = $customer->name;
        $when = $pickupAt->format('D d M, H:i');
        \App\Models\WatchdogEvent::log('web_booking', 'New web booking request — '.$name, 'info', $booking);
        $typeLabel = $isHourly ? ' (hourly hire)' : ($isReturn ? ' (return)' : '');
        $this->adminAlerts->notify('web_booking',
            '🌐 New web booking — '.$name.$typeLabel,
            $name.' requested '.$when.$typeLabel.': '.\Illuminate\Support\Str::limit($data['pickup_address'], 30)
                .' → '.\Illuminate\Support\Str::limit($destination, 30).'. Confirm it.',
            'info', $booking);

        // Offer online card payment only when Square is live AND we have a firm
        // price to charge (a fixed-matrix fare). "Price on request" stays office-
        // confirmed first — never charge a guess.
        $payUrl = null;
        if ($this->payments->enabled() && $quote['fixed'] && ($quote['price'] ?? 0) > 0) {
            $payUrl = URL::temporarySignedRoute('widget.pay', now()->addHours(6), ['booking' => $booking->id]);
        }

        return response()
            ->view('widget.book', [
                'vehicleTypes' => collect(),
                'done' => true,
                'ref' => $booking->reference,
                'payUrl' => $payUrl,
                'payAmount' => $quote['price'] ?? null,
            ])
            ->header('Content-Security-Policy', $this->frameAncestors());
    }

    /** Send the customer to Square to pay their fare (signed link from the thanks page). */
    public function pay(Request $request, Booking $booking): \Illuminate\Http\RedirectResponse
    {
        abort_unless($request->hasValidSignature(), 403);

        $amount = (float) ($booking->quoted_price ?? 0);
        $url = $amount > 0
            ? $this->payments->createCheckoutUrl($booking, $amount, route('widget.paid'))
            : null;

        if (! $url) {
            return redirect()->route('widget.paid', ['unavailable' => 1]);
        }

        return redirect()->away($url);
    }

    /** Post-payment landing (Square redirects here). Status confirmed by webhook. */
    public function paid(Request $request): \Illuminate\Http\Response
    {
        return response()
            ->view('widget.paid', ['unavailable' => $request->boolean('unavailable')])
            ->header('Content-Security-Policy', $this->frameAncestors());
    }

    /** Match a customer by phone (then email), else create one. */
    private function resolveCustomer(array $data): Customer
    {
        $phone = $data['customer_phone'] ?? null;
        $email = $data['customer_email'] ?? null;

        if ($phone && $found = Customer::where('phone', $phone)->first()) {
            return $found;
        }
        if (! $phone && $email && $found = Customer::where('email', $email)->first()) {
            return $found;
        }

        return Customer::create([
            'name' => $data['customer_name'],
            'phone' => $phone ?: null,
            'email' => $email ?: null,
        ]);
    }

    /**
     * Customers only ever see (and pick) the standard "Minibus 8 Seater". When
     * the party or the luggage is too big for it, silently upgrade the booking to
     * the Minibus XL so the right vehicle is allocated — otherwise it stays a
     * standard Minibus. No effect on any other vehicle class.
     */
    private function autoUpgradeMinibus(VehicleType $type, int $passengers, int $luggage): VehicleType
    {
        if ($type->slug !== 'minibus-8') {
            return $type;
        }
        if ($passengers <= $type->passenger_capacity && $luggage <= $type->luggage_capacity) {
            return $type;
        }

        return VehicleType::where('slug', 'minibus-8-xl')->where('is_active', true)->first() ?? $type;
    }
}
