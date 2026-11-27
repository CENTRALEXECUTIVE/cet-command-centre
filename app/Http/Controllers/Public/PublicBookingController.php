<?php

namespace App\Http\Controllers\Public;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\VehicleType;
use App\Models\Voucher;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\VatService;
use App\Services\Pricing\FareCalculator;
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
        private readonly FareCalculator $fares,
        private readonly PaymentGateway $payments,
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
            'surcharges' => \App\Support\Surcharges::rates(),
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
            'pickup_postcode' => ['nullable', 'string', 'max:12'],
            'destination_postcode' => ['nullable', 'string', 'max:12'],
            'pickup_at' => ['nullable', 'date'],
        ]);

        $pickupAt = $this->parsePickup($data['pickup_at'] ?? null) ?? now();
        $pickup = $this->withPostcode($data['pickup'], $data['pickup_postcode'] ?? null);
        $destination = $this->withPostcode($data['destination'], $data['destination_postcode'] ?? null);

        $options = $this->activeVehicleTypes()->map(function (VehicleType $type) use ($pickup, $destination, $pickupAt) {
            $fare = $this->fares->calculate($pickup, $destination, $type, $pickupAt);
            $price = $fare['subtotal']; // base + any holiday/rush surcharge (no extras yet)
            if (! empty($fare['office_quote'])) {
                $price = null; // far from home at both ends: the office prices it (20 Nov 2026)
                $fare['poa'] = true;
            }

            return [
                'id' => $type->id,
                'name' => $type->name,
                'passengers' => $type->passenger_capacity,
                'luggage' => $type->luggage_capacity,
                'price' => $price,
                'formatted' => $price !== null ? '£'.number_format($price, 0) : 'Price on request',
                'poa' => $fare['poa'],
                'fixed' => $fare['fixed'],
                'surcharge' => $fare['surcharge'],
            ];
        })->values();

        // Surcharge note is the same for all vehicles (date-driven).
        $surcharge = $this->fares->holidayFactor($pickupAt);

        return response()->json(['options' => $options, 'surcharge' => $surcharge]);
    }

    /**
     * Take the booking and send the customer to pay in full. Creates a PENDING
     * web booking; the Square webhook confirms it on payment. A firm price is
     * required to pay online — "price on request" jobs are submitted as an
     * enquiry for the office to price and confirm.
     */
    public function store(Request $request): RedirectResponse
    {
        try {
            $started = now()->subSecond();
            $response = $this->storeBooking($request);
            $b = \App\Models\Booking::where('source', 'web')->where('created_at', '>=', $started)->latest('id')->first();
            if (\App\Support\UnsavedBookingBackup::isSpam($request)) {
                \App\Models\BookingAttempt::record('book', 'spam', $request);

                return $response;
            }
            // Sent back with a message to fix (e.g. an invalid discount code) = refused, not lost.
            if (! $b && $request->hasSession() && $request->session()->has('errors')) {
                \App\Models\BookingAttempt::record('book', 'refused', $request, ['details' => ['errors' => $request->session()->get('errors')->toArray()]]);

                return $response;
            }
            if (! $b) {
                \App\Support\UnsavedBookingBackup::send($request, 'no booking made');
            }
            \App\Models\BookingAttempt::record('book', $b ? (! empty($b->meta['quote_request']) ? 'quote_request' : 'booked') : 'submitted', $request,
                $b ? ['booking_id' => $b->id, 'reference' => $b->reference, 'details' => ['price' => $b->quoted_price]] : []);

            return $response;
        } catch (\Illuminate\Validation\ValidationException $e) {
            // The customer sees the reason in a red box; the directors get told at once.
            \App\Support\RejectedBookingAlert::send($request, $e, 'public /book page');
            \App\Models\BookingAttempt::record('book', 'refused', $request, ['details' => ['errors' => $e->errors()]]);
            throw $e;
        } catch (\Throwable $e) {
            \App\Models\BookingAttempt::record('book', 'error', $request, ['details' => ['message' => mb_substr($e->getMessage(), 0, 500)]]);
            \App\Support\UnsavedBookingBackup::send($request, 'the booking form hit an error');
            throw $e;
        }
    }

    private function storeBooking(Request $request): RedirectResponse
    {
        // Honeypot: a hidden field real customers never fill.
        if (filled($request->input('company'))) {
            return redirect()->route('public.book')->with('booked', 'thanks');
        }

        // Minimum notice: no online booking within N hours of now (office-only below that).
        $minLeadHours = (int) config('cet.public_min_lead_hours', 8);
        $minPickup = now()->addHours($minLeadHours);

        \App\Support\FullName::merge($request, 'customer_first_name', 'customer_surname', 'customer_name');

        // A PHONE NUMBER NEVER BLOCKS A BOOKING (directors, 27 Nov 2026): a number that looks
        // wrong is kept and flagged for the office; every other check stays as it was.
        $passNotes = [];
        if (filled($request->input('customer_phone')) && \App\Support\Phone::problem($request->input('customer_phone'))) {
            $passNotes[] = 'Phone number looked wrong — check it: '.mb_substr((string) $request->input('customer_phone'), 0, 32);
        }
        $phoneCheck = fn ($attr, $v, $fail) => null;

        $data = $request->validate([
            'customer_first_name' => ['nullable', 'string', 'max:60'],
            'customer_surname' => ['nullable', 'string', 'max:60', 'required_with:customer_first_name'],
            'pickup_address' => ['required', 'string', 'max:500'],
            'destination_address' => ['required', 'string', 'max:500'],
            'pickup_postcode' => ['required', 'string', 'max:12'],
            'destination_postcode' => ['nullable', 'string', 'max:12'],
            'pickup_at' => ['required', 'date', 'after_or_equal:'.$minPickup->format('Y-m-d H:i:s')],
            'vehicle_type_id' => ['required', Rule::exists('vehicle_types', 'id')],
            'passengers' => ['required', 'integer', 'min:1', 'max:60'],
            'suitcases' => ['nullable', 'integer', 'min:0', 'max:30'],
            'hand_luggage' => ['nullable', 'integer', 'min:0', 'max:30'],
            'flight_number' => ['nullable', 'string', 'max:32'],
            'customer_name' => ['required', 'string', 'max:120', \App\Support\FullName::rule()],
            'customer_email' => ['required', 'email', 'max:160'],
            'customer_phone' => ['required', 'string', 'max:32', $phoneCheck],
            'notes' => ['nullable', 'string', 'max:1000'],
            'vat_invoice' => ['nullable', 'boolean'],
            'voucher' => ['nullable', 'string', 'max:40'],
            // Extras (mirrors ETO Item Surcharge).
            'meet_greet' => ['nullable', 'boolean'],
            'wheelchair' => ['nullable', 'boolean'],
            'ribbons' => ['nullable', 'boolean'],
            'child_seats' => ['nullable', 'integer', 'min:0', 'max:20'],
            'booster_seats' => ['nullable', 'integer', 'min:0', 'max:20'],
            'infant_seats' => ['nullable', 'integer', 'min:0', 'max:20'],
            'stopovers' => ['nullable', 'integer', 'min:0', 'max:20'],
        ], [
            'pickup_at.after_or_equal' => "We need at least {$minLeadHours} hours’ notice to book online — please call the office for anything sooner.",
        ], ['customer_phone' => 'phone', 'customer_email' => 'email', 'pickup_postcode' => 'pick-up postcode',
            'customer_surname.required_with' => 'Please enter your surname.']);

        $vehicleType = VehicleType::findOrFail($data['vehicle_type_id']);
        $pickupAt = $this->parsePickup($data['pickup_at']);
        $needsInvoice = (bool) ($data['vat_invoice'] ?? false);

        // Full addresses always carry the postcode (used for zone-accurate pricing
        // and given to the driver). The pick-up postcode is mandatory.
        $pickupFull = $this->withPostcode($data['pickup_address'], $data['pickup_postcode']);
        $destinationFull = $this->withPostcode($data['destination_address'], $data['destination_postcode'] ?? null);

        // Base + holiday surcharge + extras, all VAT-inclusive.
        // Meet & greet only on an airport PICKUP (an arrival) — a ticked box on a
        // drop-off to the airport is never charged.
        $extraOptions = $this->extraOptions($data);
        $extraOptions['meet_greet'] = $extraOptions['meet_greet'] && \App\Support\AirportMatcher::isAirport($pickupFull);
        $fare = $this->fares->calculate(
            $pickupFull, $destinationFull, $vehicleType, $pickupAt,
            $extraOptions,
        );
        $base = $fare['base'];
        $subtotal = $fare['subtotal'];
        if (! empty($fare['office_quote'])) {
            // Far from home at both ends: a request, no price taken — the office prices it.
            $base = null;
            $subtotal = null;
        }

        // Voucher (validated here; only consumed once payment succeeds).
        $voucher = Voucher::findByCode($data['voucher'] ?? null);
        if (($data['voucher'] ?? '') !== '' && (! $voucher || ! $voucher->isRedeemable())) {
            return back()->withInput()->withErrors(['voucher' => 'That voucher code isn’t valid or has expired.']);
        }
        $discount = ($voucher && $subtotal !== null) ? $voucher->discountOn($subtotal) : 0.0;
        $afterDiscount = $subtotal === null ? null : round($subtotal - $discount, 2);

        // A business that needs a VAT invoice has 20% added on top; a private
        // customer pays the VAT-inclusive total.
        $charge = $afterDiscount === null
            ? null
            : ($needsInvoice ? round($afterDiscount * (1 + $this->vat->rate()), 2) : $afterDiscount);

        $customer = $this->resolveCustomer($data);

        $booking = Booking::create([
            'reference' => Booking::generateReference(),
            'customer_id' => $customer->id,
            'vehicle_type_id' => $vehicleType->id,
            'pickup_at' => $pickupAt,
            'pickup_address' => $pickupFull,
            'destination_address' => $destinationFull,
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
                'passed_through' => $passNotes ?: null,
                'contact_override' => $customer->typedPhoneIfDifferent($data['customer_phone'] ?? null),
                'lead_name' => $data['customer_name'],
                'pickup_postcode' => strtoupper(trim($data['pickup_postcode'])),
                'destination_postcode' => strtoupper(trim((string) ($data['destination_postcode'] ?? ''))),
                'suitcases' => (int) ($data['suitcases'] ?? 0),
                'hand_luggage' => (int) ($data['hand_luggage'] ?? 0),
                'web_quote_basis' => $fare['fixed'] ? 'Fixed price' : ($fare['base'] === null ? 'Price on request' : 'Distance'),
                'vat_invoice_requested' => $needsInvoice,
                // VAT invoice → Central Executive Transfers; no VAT → sister company
                // Central Executive Chauffeurs (its Square account takes the money).
                'billing_entity' => $needsInvoice ? 'transfers' : 'chauffeurs',
                'list_price' => $base,
                'fare_surcharge' => $fare['surcharge'],
                'fare_extras' => $fare['extras'],
                'fare_extras_total' => $fare['extras_total'],
                'voucher' => $voucher ? ['id' => $voucher->id, 'code' => $voucher->code, 'discount' => $discount, 'label' => $voucher->label()] : null,
            ], fn ($v) => $v !== null && $v !== '' && $v !== false && $v !== []),
        ]);

        // Alert the office (a paid one will alert again on payment).
        \App\Models\WatchdogEvent::log('web_booking', 'New web booking — '.$customer->name, 'info', $booking);
        $this->adminAlerts->notify('web_booking',
            '🌐 New web booking — '.$customer->name,
            $customer->name.' — '.$pickupAt->format('D d M, H:i').', '.Str::limit($data['pickup_address'], 24)
                .' → '.Str::limit($data['destination_address'], 24)
                .($charge !== null ? '. £'.number_format($charge, 2).' to pay.' : '. Price on request.'),
            'info', $booking);

        // Email the office too (mirrors ETO's "New booking" notification).
        if ($ops = $booking->officeInbox()) {
            try {
                \Illuminate\Support\Facades\Mail::to($ops)->send(new \App\Mail\OfficeBookingMail($booking->fresh()));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[web] office booking email failed: '.$e->getMessage());
            }
        }

        // Firm price + Square live → straight to pay (the paid receipt email is
        // sent by the webhook once payment succeeds).
        if ($charge !== null && $charge > 0 && $this->payments->enabled()) {
            $url = $this->payments->createCheckoutUrl($booking, $charge, route('public.book.thanks'));
            if ($url) {
                return redirect()->away($url);
            }
        }

        // Enquiry (price on request, or online payment unavailable): email the
        // customer their request confirmation now.
        if ($booking->customerEmailAllowed()) {
            try {
                \Illuminate\Support\Facades\Mail::to($customer->email)
                    ->send(new \App\Mail\BookingConfirmationMail($booking->fresh(), paid: false));
                $booking->forceFill(['meta' => array_merge($booking->meta ?? [], [
                    'customer_email_sent' => now()->toIso8601String(),
                ])])->save();
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[web] enquiry email failed: '.$e->getMessage());
            }
        }

        // Reaching here means NO payment was taken (price on request, online payment
        // off, or the checkout link failed) — never tell them they've paid (24 Nov 2026).
        return redirect()->route('public.book.thanks')->with('enquiry', true);
    }

    /** Post-payment / enquiry landing. Payment is confirmed by the webhook. */
    public function thanks(Request $request): \Illuminate\View\View
    {
        return view('public.thanks', ['enquiry' => (bool) $request->session()->get('enquiry', false)]);
    }

    /** Parse the datetime-local pickup value in the app timezone. */
    private function parsePickup(?string $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d\TH:i', $value, config('app.timezone'))
                ?: Carbon::parse($value, config('app.timezone'));
        } catch (\Throwable) {
            return Carbon::parse($value, config('app.timezone'));
        }
    }

    /** The extras selections from the validated form, for FareCalculator. */
    private function extraOptions(array $data): array
    {
        return [
            'meet_greet' => (bool) ($data['meet_greet'] ?? false),
            'wheelchair' => (bool) ($data['wheelchair'] ?? false),
            'ribbons' => (bool) ($data['ribbons'] ?? false),
            'child_seats' => (int) ($data['child_seats'] ?? 0),
            'booster_seats' => (int) ($data['booster_seats'] ?? 0),
            'infant_seats' => (int) ($data['infant_seats'] ?? 0),
            'stopovers' => (int) ($data['stopovers'] ?? 0),
        ];
    }

    /**
     * Combine an address with its postcode for pricing and the driver, without
     * doubling up if the address already ends in that postcode.
     */
    private function withPostcode(string $address, ?string $postcode): string
    {
        $address = trim($address);
        $postcode = strtoupper(trim((string) $postcode));
        if ($postcode === '' || stripos($address, $postcode) !== false) {
            return $address;
        }

        return $address.', '.$postcode;
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
        return Customer::matchForWebBooking($data['customer_name'], $data['customer_phone'] ?? null, $data['customer_email'] ?? null);
    }
}
