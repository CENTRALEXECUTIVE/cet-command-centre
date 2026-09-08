<?php

namespace App\Http\Controllers\Public;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\VehicleType;
use App\Services\Payments\SquareBookingPaymentService;
use App\Services\Payments\VatService;
use App\Services\Pricing\QuoteService;
use App\Services\Watchdog\AdminAlerts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The public, first-class booking page (centralexecutivetransfers.co.uk/book):
 * a customer gets an instant price for every vehicle, picks one, enters their
 * details and pays IN FULL via Square. Payment then confirms the booking
 * automatically (see BookingStatusService::confirmPaidWebBooking, fired by the
 * Square webhook) — allocating a driver and putting it on the calendar with no
 * office action.
 *
 * Prices are VAT-inclusive for private customers. A business that needs a VAT
 * invoice ticks the box and 20% is added on top; they get a proper VAT invoice
 * to reclaim it. Uses the existing CET pricing engine (fixed matrix + free-roam),
 * so quoting costs nothing and never touches the live marketing site.
 */
class PublicBookingController extends Controller
{
    public function __construct(
        private readonly QuoteService $quotes,
        private readonly SquareBookingPaymentService $payments,
        private readonly VatService $vat,
        private readonly AdminAlerts $adminAlerts,
    ) {}

    /** The booking page. */
    public function index(): \Illuminate\View\View
    {
        return view('public.book', [
            'vehicleTypes' => $this->activeVehicleTypes(),
            'payEnabled' => $this->payments->enabled(),
            'vatPercent' => $this->vat->ratePercent(),
        ]);
    }

    /**
     * Instant prices for EVERY vehicle for a journey (JSON), VAT-inclusive.
     * Lightweight — the existing pricing engine, no AI, nothing saved.
     */
    public function quotes(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pickup' => ['required', 'string', 'max:500'],
            'destination' => ['required', 'string', 'max:500'],
        ]);

        $options = $this->activeVehicleTypes()->map(function (VehicleType $type) use ($data) {
            $q = $this->quotes->quote($data['pickup'], $data['destination'], $type);
            $price = $q['price'];

            return [
                'id' => $type->id,
                'name' => $type->name,
                'passengers' => $type->passenger_capacity,
                'luggage' => $type->luggage_capacity,
                'price' => $price,
                'formatted' => $price !== null ? '£'.number_format($price, 0) : 'Price on request',
                'poa' => $price === null,
                'fixed' => $q['fixed'],
            ];
        })->values();

        return response()->json(['options' => $options]);
    }

    /**
     * Take the booking and send the customer to pay in full. Creates a PENDING
     * web booking; the Square webhook confirms it on payment. A firm price is
     * required to pay online — "price on request" jobs are submitted as an
     * enquiry for the office to price and confirm.
     */
    public function store(Request $request): RedirectResponse
    {
        // Honeypot: a hidden field real customers never fill.
        if (filled($request->input('company'))) {
            return redirect()->route('public.book')->with('booked', 'thanks');
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
            'customer_email' => ['required', 'email', 'max:160'],
            'customer_phone' => ['required', 'string', 'max:32'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'vat_invoice' => ['nullable', 'boolean'],
        ], [], ['customer_phone' => 'phone', 'customer_email' => 'email']);

        $vehicleType = VehicleType::findOrFail($data['vehicle_type_id']);
        $pickupAt = Carbon::createFromFormat('Y-m-d\TH:i', $data['pickup_at'], config('app.timezone'))
            ?: Carbon::parse($data['pickup_at']);

        $quote = $this->quotes->quote($data['pickup_address'], $data['destination_address'], $vehicleType);
        $base = $quote['price'];
        $needsInvoice = (bool) ($data['vat_invoice'] ?? false);

        // A business that needs a VAT invoice has 20% added on top of the listed
        // price; a private customer pays the listed (VAT-inclusive) price.
        $charge = $base === null
            ? null
            : ($needsInvoice ? round($base * (1 + $this->vat->rate()), 2) : $base);

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
            'quoted_price' => $charge,
            'meta' => array_filter([
                'web_booking' => true,
                'lead_name' => $data['customer_name'],
                'suitcases' => (int) ($data['suitcases'] ?? 0),
                'hand_luggage' => (int) ($data['hand_luggage'] ?? 0),
                'web_quote_basis' => $quote['basis'],
                'vat_invoice_requested' => $needsInvoice,
                'list_price' => $base,
            ], fn ($v) => $v !== null && $v !== '' && $v !== false),
        ]);

        // Alert the office (a paid one will alert again on payment).
        \App\Models\WatchdogEvent::log('web_booking', 'New web booking — '.$customer->name, 'info', $booking);
        $this->adminAlerts->notify('web_booking',
            '🌐 New web booking — '.$customer->name,
            $customer->name.' — '.$pickupAt->format('D d M, H:i').', '.Str::limit($data['pickup_address'], 24)
                .' → '.Str::limit($data['destination_address'], 24)
                .($charge !== null ? '. £'.number_format($charge, 2).' to pay.' : '. Price on request.'),
            'info', $booking);

        // Firm price + Square live → straight to pay. Otherwise it's an enquiry.
        if ($charge !== null && $charge > 0 && $this->payments->enabled()) {
            $url = $this->payments->createCheckoutUrl($booking, $charge, route('public.book.thanks'));
            if ($url) {
                return redirect()->away($url);
            }
        }

        return redirect()->route('public.book.thanks')->with('enquiry', $charge === null);
    }

    /** Post-payment / enquiry landing. Payment is confirmed by the webhook. */
    public function thanks(Request $request): \Illuminate\View\View
    {
        return view('public.thanks', ['enquiry' => (bool) $request->session()->get('enquiry', false)]);
    }

    /** @return \Illuminate\Support\Collection<int, VehicleType> */
    private function activeVehicleTypes()
    {
        return VehicleType::where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'name', 'slug', 'passenger_capacity', 'luggage_capacity']);
    }

    private function resolveCustomer(array $data): Customer
    {
        return Customer::where('phone', $data['customer_phone'])->first()
            ?? Customer::where('email', $data['customer_email'])->first()
            ?? Customer::create([
                'name' => $data['customer_name'],
                'phone' => $data['customer_phone'],
                'email' => $data['customer_email'],
            ]);
    }
}
