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
            'pickup_address' => ['required', 'string', 'max:500'],
            'destination_address' => ['required', 'string', 'max:500'],
            'pickup_at' => ['required', 'date', 'after:now'],
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

        $vehicleType = VehicleType::findOrFail($data['vehicle_type_id']);
        $pickupAt = Carbon::createFromFormat('Y-m-d\TH:i', $data['pickup_at'], config('app.timezone'))
            ?: Carbon::parse($data['pickup_at']);

        // A guide price from the existing engine, stored for the office.
        $quote = $this->quotes->quote($data['pickup_address'], $data['destination_address'], $vehicleType);

        $customer = $this->resolveCustomer($data);

        $booking = Booking::create([
            'reference' => Booking::generateReference(),
            'customer_id' => $customer->id,
            'vehicle_type_id' => $vehicleType->id,
            'pickup_at' => $pickupAt,
            'pickup_address' => $data['pickup_address'],
            'destination_address' => $data['destination_address'],
            'passengers' => $data['passengers'],
            'flight_number' => $data['flight_number'] ?? null,
            'special_requests' => $data['notes'] ?? null,
            'status' => BookingStatus::Pending->value,
            'payment_method' => 'card',
            'payment_status' => 'pending',
            'source' => 'web',
            'quoted_price' => $quote['price'],
            'meta' => array_filter([
                'web_booking' => true,
                'suitcases' => (int) ($data['suitcases'] ?? 0),
                'hand_luggage' => (int) ($data['hand_luggage'] ?? 0),
                'driver_notes' => $data['notes'] ?? null,
                'web_quote_basis' => $quote['basis'],
            ], fn ($v) => $v !== null && $v !== ''),
        ]);

        // Tell the office at once — a web request needs a human to confirm.
        $name = $customer->name;
        $when = $pickupAt->format('D d M, H:i');
        \App\Models\WatchdogEvent::log('web_booking', 'New web booking request — '.$name, 'info', $booking);
        $this->adminAlerts->notify('web_booking',
            '🌐 New web booking — '.$name,
            $name.' requested '.$when.': '.\Illuminate\Support\Str::limit($data['pickup_address'], 30)
                .' → '.\Illuminate\Support\Str::limit($data['destination_address'], 30).'. Confirm it.',
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
}
