<?php

namespace App\Http\Controllers\Widget;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\CorporateAccount;
use App\Models\CorporateContact;
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
    public function book(Request $request): \Illuminate\Http\Response
    {
        // The Minibus XL is shown to the customer ONLY when their party/luggage is
        // too big for the standard 8-Seater (revealed client-side); otherwise the
        // standard Minibus shows in its place. It's still in the list so the card
        // and its price are ready to appear the instant it's needed.
        $vehicleTypes = VehicleType::where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'name', 'slug', 'passenger_capacity', 'luggage_capacity']);

        // Already signed in this browser (via My Account or the widget)? Prefill and,
        // for a business account, unlock the "Account" payment option on load.
        $me = ($sid = $request->session()->get(CustomerAccountController::SESSION_KEY))
            ? Customer::find($sid) : null;
        $meAccount = $me ? $this->resolveAccountFor($me) : null;

        return response()
            ->view('widget.book', [
                'vehicleTypes' => $vehicleTypes,
                'done' => false,
                'surcharges' => \App\Support\Surcharges::rates(),
                'vatPercent' => app(\App\Services\Payments\VatService::class)->ratePercent(),
                'me' => $me ? ['name' => $me->name, 'email' => $me->email, 'phone' => $me->phone] : null,
                'meAccount' => $meAccount ? ['name' => $meAccount->name, 'code' => $meAccount->account_code] : null,
                'passwordLogin' => Customer::passwordLoginAvailable(),
            ])
            ->header('Content-Security-Policy', $this->frameAncestors());
    }

    /**
     * Is this email a recognised business-account contact? The widget calls this
     * as the customer types their email and, if so, reveals the "Account (monthly
     * invoice)" payment option — so only approved account users ever see it.
     */
    public function accountCheck(Request $request): JsonResponse
    {
        $account = $this->findAccountByEmail((string) $request->query('email', ''));

        return response()->json($account
            ? ['account' => true, 'name' => $account->name]
            : ['account' => false]);
    }

    /**
     * Sign a customer in from the booking widget (email + password) so they can
     * book on account. Sets the shared My-Account session and reports whether they
     * hold an active business account (which unlocks the "Account" payment option).
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:160'],
            'password' => ['required', 'string'],
        ]);

        $email = \Illuminate\Support\Str::lower(trim($data['email']));
        $customer = Customer::whereRaw('LOWER(email) = ?', [$email])->first();

        if (! $customer || ! $customer->checkPassword($data['password'])) {
            return response()->json(['ok' => false], 422);
        }

        $request->session()->put(CustomerAccountController::SESSION_KEY, $customer->id);
        $account = $this->resolveAccountFor($customer);

        return response()->json([
            'ok' => true,
            'name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'account' => $account ? ['name' => $account->name, 'code' => $account->account_code] : null,
        ]);
    }

    /** An active business account whose contact (or billing) email matches. */
    private function findAccountByEmail(?string $email): ?CorporateAccount
    {
        $email = strtolower(trim((string) $email));
        if ($email === '' || ! str_contains($email, '@')) {
            return null;
        }

        $contact = CorporateContact::whereRaw('LOWER(email) = ?', [$email])
            ->whereHas('corporateAccount', fn ($q) => $q->where('is_active', true))
            ->first();
        if ($contact) {
            return $contact->corporateAccount;
        }

        return CorporateAccount::where('is_active', true)
            ->whereRaw('LOWER(billing_email) = ?', [$email])
            ->first();
    }

    /** The account this customer may book on: their linked account, else by email. */
    private function resolveAccountFor(Customer $customer): ?CorporateAccount
    {
        if ($customer->corporate_account_id) {
            $linked = CorporateAccount::find($customer->corporate_account_id);
            if ($linked && $linked->is_active) {
                return $linked;
            }
        }

        return $this->findAccountByEmail($customer->email);
    }

    /**
     * Instant prices for EVERY vehicle for a journey (JSON), so the widget can show
     * a price on each card. Lightweight — the existing pricing engine, nothing saved.
     */
    public function prices(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pickup' => ['required', 'string', 'max:500'],
            'destination' => ['required', 'string', 'max:500'],
        ]);

        $options = VehicleType::where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'name', 'slug'])
            ->map(function (VehicleType $type) use ($data) {
                $q = $this->quotes->quote($data['pickup'], $data['destination'], $type);

                return [
                    'id' => $type->id,
                    'price' => $q['price'],
                    'fixed' => $q['fixed'],
                    'poa' => $q['price'] === null,
                    'formatted' => $q['price'] !== null ? '£'.number_format($q['price'], 0) : 'On request',
                ];
            })->values();

        return response()->json(['options' => $options]);
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

        // Minimum notice: no online booking within N hours of now (office-only below that).
        $minLeadHours = (int) config('cet.public_min_lead_hours', 8);
        $minPickup = now()->addHours($minLeadHours);

        $data = $request->validate([
            'journey_type' => ['nullable', Rule::in(['one_way', 'return', 'hourly'])],
            'pickup_address' => ['required', 'string', 'max:500'],
            'pickup_postcode' => ['nullable', 'string', 'max:12'],
            'destination_address' => ['required_unless:journey_type,hourly', 'nullable', 'string', 'max:500'],
            'destination_postcode' => ['nullable', 'string', 'max:12'],
            'pickup_at' => ['required', 'date', 'after_or_equal:'.$minPickup->format('Y-m-d H:i:s')],
            'return_pickup_at' => ['nullable', 'required_if:journey_type,return', 'date', 'after:pickup_at'],
            'hours' => ['nullable', 'required_if:journey_type,hourly', 'integer', 'min:1', 'max:24'],
            'vehicle_type_id' => ['required', Rule::exists('vehicle_types', 'id')],
            'passengers' => ['required', 'integer', 'min:1', 'max:60'],
            'suitcases' => ['nullable', 'integer', 'min:0', 'max:30'],
            'hand_luggage' => ['nullable', 'integer', 'min:0', 'max:30'],
            'flight_number' => ['nullable', 'string', 'max:32'],
            'flight_landing_at' => ['nullable', 'date'],
            'customer_name' => ['required', 'string', 'max:120'],
            'customer_phone' => ['nullable', 'string', 'max:32', 'required_without:customer_email'],
            'customer_email' => ['nullable', 'email', 'max:160', 'required_without:customer_phone'],
            'notes' => ['nullable', 'string', 'max:1000'],
            // ETO-style extras (captured for the office; guide price is confirmed).
            'meet_greet' => ['nullable', 'boolean'],
            'child_seats' => ['nullable', 'integer', 'min:0', 'max:2'],
            'booster_seats' => ['nullable', 'integer', 'min:0', 'max:2'],
            'infant_seats' => ['nullable', 'integer', 'min:0', 'max:2'],
            'stopovers' => ['nullable', 'integer', 'min:0', 'max:10'],
            'stops' => ['nullable', 'array', 'max:10'],
            'stops.*' => ['nullable', 'string', 'max:500'],
            'wheelchair' => ['nullable', 'boolean'],
            'ribbons' => ['nullable', 'boolean'],
            'vat_invoice' => ['nullable', 'boolean'],
            'payment_method' => ['nullable', Rule::in(['card', 'cash', 'account'])],
            'voucher' => ['nullable', 'string', 'max:40'],
            'accept_terms' => ['nullable', 'boolean'],
            'accept_privacy' => ['nullable', 'boolean'],
            'booking_for_other' => ['nullable', 'boolean'],
            'lead_passenger_name' => ['nullable', 'string', 'max:120'],
            'lead_passenger_phone' => ['nullable', 'string', 'max:32'],
            // Optional "create an account" at checkout (guests can still book).
            'create_account' => ['nullable', 'boolean'],
            'password' => ['nullable', 'string', 'min:8', 'max:72'],
        ], [
            'pickup_at.after_or_equal' => "We need at least {$minLeadHours} hours’ notice to book online — please call the office for anything sooner.",
        ], ['customer_phone' => 'phone', 'customer_email' => 'email']);

        $journeyType = $data['journey_type'] ?? 'one_way';
        $isHourly = $journeyType === 'hourly';
        $isReturn = $journeyType === 'return';

        // Via stops added on the main page (addresses the driver calls at between
        // pickup and drop-off). The count drives the per-stop surcharge, and the
        // addresses are stored in meta['stops'] where Booking::viaStops() reads them.
        $stops = array_values(array_filter(array_map(
            fn ($s) => trim((string) $s),
            (array) ($data['stops'] ?? []),
        ), fn ($s) => $s !== ''));
        $data['stopovers'] = count($stops);

        // Flight number AND landing time MUST be provided for airport journeys
        // (arrivals we track): a one-way pickup FROM an airport, or a return touching
        // an airport. On a return these can never be missed.
        $isAirportArrival = $this->isAirportArrival($journeyType, $data['pickup_address'], $data['destination_address'] ?? null);
        if ($isAirportArrival) {
            $flightErrors = [];
            if (blank($data['flight_number'] ?? null)) {
                $flightErrors['flight_number'] = 'Please provide the flight number for your airport journey.';
            }
            if (blank($data['flight_landing_at'] ?? null)) {
                $flightErrors['flight_landing_at'] = 'Please provide your flight’s landing time for your airport journey.';
            }
            if ($flightErrors) {
                throw \Illuminate\Validation\ValidationException::withMessages($flightErrors);
            }
        }

        // A return journey that touches an airport ALWAYS gets meet & greet — the
        // driver waits in arrivals with a name board, no matter what.
        if ($isReturn && $isAirportArrival) {
            $data['meet_greet'] = true;
        }

        // No cash bookings on a return journey — the customer pays by card (or on
        // account when signed in). A cash choice on a return is rejected.
        if ($isReturn && ($data['payment_method'] ?? null) === 'cash') {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'payment_method' => 'Return journeys can’t be booked as cash — please pay by card.',
            ]);
        }

        // Addresses always carry their postcode (accurate zone pricing + the driver).
        $pickupFull = $this->withPostcode($data['pickup_address'], $data['pickup_postcode'] ?? null);
        $destination = $isHourly
            ? 'As directed (hourly hire)'
            : $this->withPostcode($data['destination_address'], $data['destination_postcode'] ?? null);

        // We can only carry two child/booster/infant seats in total (backstop; the
        // form already caps it). Trim child → booster → infant if it's ever exceeded.
        $seatCap = 2;
        foreach (['child_seats', 'booster_seats', 'infant_seats'] as $seatField) {
            $used = (int) ($data['child_seats'] ?? 0) + (int) ($data['booster_seats'] ?? 0) + (int) ($data['infant_seats'] ?? 0);
            if ($used > $seatCap) {
                $data[$seatField] = max(0, (int) ($data[$seatField] ?? 0) - ($used - $seatCap));
            }
        }

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
            : $this->quotes->quote($pickupFull, $destination, $vehicleType);

        // A logged-in customer (verified in this browser session) is the booker —
        // this is what makes "book on account" possible: only a signed-in company
        // account can bill on account. Guests fall back to find-or-create.
        $sessionCustomer = ($sid = $request->session()->get(CustomerAccountController::SESSION_KEY))
            ? Customer::find($sid) : null;
        $customer = $sessionCustomer ?: $this->resolveCustomer($data);

        // "Create an account" at checkout — set a login password and sign them in,
        // so next time is faster (and a company contact can book on account).
        if (! empty($data['create_account']) && filled($data['password'] ?? null) && Customer::passwordLoginAvailable()) {
            $customer->setLoginPassword($data['password']);
            $request->session()->put(CustomerAccountController::SESSION_KEY, $customer->id);
        }

        // "Booking for someone else" — the booker is the account/contact; the named
        // passenger becomes the LEAD passenger (shown on the job, greeted, etc.).
        $leadName = null;
        $passengerLine = null;
        if (! empty($data['booking_for_other']) && filled($data['lead_passenger_name'] ?? null)) {
            $leadName = trim($data['lead_passenger_name']);
            $passengerLine = 'Passenger: '.$leadName
                .(filled($data['lead_passenger_phone'] ?? null) ? ' ('.trim($data['lead_passenger_phone']).')' : '')
                .' · Booked by '.$customer->name;
        }

        // ETO-style extras — captured for the office and folded into the notes the
        // office/driver see. The guide price is confirmed by the office, so nothing
        // is auto-charged; the seat counts also drive the calendar's child-seat mark.
        $extra = $this->extrasFrom($data);
        $composedNotes = $this->composedNotes($data['notes'] ?? null, $extra['labels']);
        if ($passengerLine) {
            $composedNotes = trim($passengerLine.($composedNotes ? "\n".$composedNotes : ''));
        }
        $flight = strtoupper(trim((string) ($data['flight_number'] ?? ''))) ?: null;
        // Customer-entered flight landing time (UK-local, like every other clock we
        // show — never converted; see the timezone rule). Stored for the office.
        $flightLandingAt = null;
        if (filled($data['flight_landing_at'] ?? null)) {
            $flightLandingAt = (Carbon::createFromFormat('Y-m-d\TH:i', $data['flight_landing_at'], config('app.timezone'))
                ?: Carbon::parse($data['flight_landing_at']))->format('Y-m-d H:i');
        }

        // Business/VAT invoice → billed by Central Executive Transfers (20% on top,
        // proper VAT invoice); no invoice → the sister company Central Executive
        // Chauffeurs takes it (its Square account). Stored so billing routes right.
        $needsInvoice = (bool) ($data['vat_invoice'] ?? false);

        // All-in price the customer sees: vehicle + extras, less any voucher, plus
        // VAT when a business invoice is wanted. Null for hourly / on-request.
        $rates = \App\Support\Surcharges::rates();
        $extrasTotal = ($extra['meet_greet'] ? (float) ($rates['meet_greet'] ?? 0) : 0)
            + $extra['child_seats'] * (float) ($rates['child_seat'] ?? 0)
            + $extra['booster_seats'] * (float) ($rates['booster_seat'] ?? 0)
            + $extra['infant_seats'] * (float) ($rates['infant_seat'] ?? 0)
            + $extra['stopovers'] * (float) ($rates['stopover'] ?? 0)
            + ($extra['wheelchair'] ? (float) ($rates['wheelchair'] ?? 0) : 0)
            + ($extra['ribbons'] ? (float) ($rates['ribbons_car'] ?? 0) : 0);

        $net = $quote['price'] === null ? null : round((float) $quote['price'] + $extrasTotal, 2);
        $voucher = \App\Models\Voucher::findByCode($data['voucher'] ?? null);
        $discount = ($voucher && $voucher->isRedeemable() && $net !== null) ? $voucher->discountOn($net) : 0.0;
        $afterDiscount = $net === null ? null : round($net - $discount, 2);
        $vatRate = app(\App\Services\Payments\VatService::class)->rate();

        // Account (monthly invoice): ONLY for a signed-in customer whose account is
        // an active business account. No payment is taken; the fare is billed on the
        // account (stored NET — VAT added on the invoice). Never trust the client's
        // "account" choice — it requires a real, logged-in company account here.
        $account = (($data['payment_method'] ?? null) === 'account' && $sessionCustomer)
            ? $this->resolveAccountFor($sessionCustomer)
            : null;
        $isAccount = $account !== null;
        if ($isAccount) {
            $needsInvoice = true; // account fares are invoiced with VAT
        }

        $charge = $afterDiscount === null ? null : ($needsInvoice ? round($afterDiscount * (1 + $vatRate), 2) : $afterDiscount);
        // Account jobs store the NET fare (fareVatBreakdown adds VAT for account);
        // card/cash store the all-in charge the customer pays.
        $quotedPrice = $isAccount ? $afterDiscount : $charge;

        $wantsCard = ! $isAccount && ($data['payment_method'] ?? 'cash') === 'card';
        // A discount code is recorded for the office even when we can't validate it
        // (they apply it on confirm); a valid one is already reflected in $charge.
        if (filled($data['voucher'] ?? null)) {
            $composedNotes = trim(($composedNotes ? $composedNotes."\n" : '').'Discount code: '.strtoupper(trim($data['voucher'])));
        }

        // Link the customer to the account so future bookings are recognised at once.
        if ($isAccount && (int) $customer->corporate_account_id !== (int) $account->id) {
            $customer->forceFill(['corporate_account_id' => $account->id])->save();
        }

        $booking = Booking::create([
            'reference' => Booking::generateReference(),
            'customer_id' => $customer->id,
            'corporate_account_id' => $account?->id,
            'vehicle_type_id' => $vehicleType->id,
            'journey_type' => $journeyType,
            'pickup_at' => $pickupAt,
            'pickup_address' => $pickupFull,
            'destination_address' => $destination,
            'passengers' => $data['passengers'],
            'flight_number' => $flight,
            'special_requests' => $composedNotes,
            'status' => BookingStatus::Pending->value,
            // Account jobs are billed monthly (driver collects nothing). Otherwise no
            // card is taken at booking time — the customer pays on the day, so the
            // DRIVER collects the fare in cash. If they later pay online via Square,
            // markFarePaid() records it and the driver-collect logic then shows
            // "collect nothing". The office can switch card/cash/account on confirm.
            // Returns are never cash (see the guard above) — they're a card job so
            // the driver never collects cash on a return; one-way keeps cash-collect.
            'payment_method' => $isAccount ? 'account' : ($isReturn ? 'card' : 'cash'),
            'payment_status' => 'pending',
            'source' => 'web',
            'quoted_price' => $quotedPrice,
            'meta' => array_filter([
                'web_booking' => true,
                'lead_name' => $leadName,
                'booked_by' => $leadName ? $customer->name : null,
                'suitcases' => (int) ($data['suitcases'] ?? 0),
                'hand_luggage' => (int) ($data['hand_luggage'] ?? 0),
                'driver_notes' => $composedNotes,
                'web_quote_basis' => $quote['basis'],
                'flight_landing_at' => $flightLandingAt,
                'hourly_hours' => $isHourly ? (int) $data['hours'] : null,
                'meet_greet' => $extra['meet_greet'] ?: null,
                'child_seats' => $extra['child_seats'] ?: null,
                'booster_seats' => $extra['booster_seats'] ?: null,
                'infant_seats' => $extra['infant_seats'] ?: null,
                'extra_stops' => $extra['stopovers'] ?: null,
                'stops' => $stops ?: null,
                'fare_extras_total' => $extrasTotal ?: null,
                'vat_invoice_requested' => $needsInvoice,
                'billing_entity' => $needsInvoice ? 'transfers' : 'chauffeurs',
                'payment_preference' => $isAccount ? 'account' : ($wantsCard ? 'card' : 'cash'),
                'account_booking' => $isAccount ?: null,
                'account_code' => $account?->account_code,
                'account_name' => $account?->name,
                'accepted_terms' => (bool) ($data['accept_terms'] ?? false) ?: null,
                'accepted_privacy' => (bool) ($data['accept_privacy'] ?? false) ?: null,
                'voucher_code' => filled($data['voucher'] ?? null) ? strtoupper(trim($data['voucher'])) : null,
            ], fn ($v) => $v !== null && $v !== '' && $v !== false),
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
                'destination_address' => $pickupFull,
                'passengers' => $data['passengers'],
                'flight_number' => null, // return leg has no inbound flight
                'special_requests' => $composedNotes,
                'status' => BookingStatus::Pending->value,
                // A return leg never collects on the day (the outbound carries the
                // fare) and returns are card-only, so it's a card job — never cash.
                'payment_method' => 'card',
                'payment_status' => 'pending',
                'source' => 'web',
                'meta' => array_filter([
                    'web_booking' => true,
                    'lead_name' => $leadName,
                    'booked_by' => $leadName ? $customer->name : null,
                    'suitcases' => (int) ($data['suitcases'] ?? 0),
                    'hand_luggage' => (int) ($data['hand_luggage'] ?? 0),
                    'driver_notes' => $composedNotes,
                    'meet_greet' => $extra['meet_greet'] ?: null,
                    'child_seats' => $extra['child_seats'] ?: null,
                    'booster_seats' => $extra['booster_seats'] ?: null,
                    'infant_seats' => $extra['infant_seats'] ?: null,
                    'extra_stops' => $extra['stopovers'] ?: null,
                    'vat_invoice_requested' => $needsInvoice,
                    'billing_entity' => $needsInvoice ? 'transfers' : 'chauffeurs',
                ], fn ($v) => $v !== null && $v !== '' && $v !== false),
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
            $name.' requested '.$when.$typeLabel.': '.\Illuminate\Support\Str::limit($pickupFull, 30)
                .' → '.\Illuminate\Support\Str::limit($destination, 30).'. Confirm it.',
            'info', $booking);

        // Email the office too (mirrors ETO's "New booking" notification), so the
        // office is aware of every web request — paid or not — and can chase up an
        // unpaid one. The customer receipt is separate (sent on payment).
        if ($ops = config('cet.ops_email')) {
            try {
                \Illuminate\Support\Facades\Mail::to($ops)->send(new \App\Mail\OfficeBookingMail($booking->fresh()));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[widget] office booking email failed: '.$e->getMessage());
            }
        }

        // Offer online card payment only when Square is live AND we have a firm
        // price to charge (a fixed-matrix fare). "Price on request" stays office-
        // confirmed first — never charge a guess. The booking stays 'cash' until
        // payment actually succeeds, so nothing is lost if the customer doesn't pay.
        $payUrl = null;
        if (! $isAccount && $this->payments->enabled() && $quote['fixed'] && ($charge ?? 0) > 0) {
            $payUrl = URL::temporarySignedRoute('widget.pay', now()->addHours(6), ['booking' => $booking->id]);
        }

        return response()
            ->view('widget.book', [
                'vehicleTypes' => collect(),
                'done' => true,
                'ref' => $booking->reference,
                'payUrl' => $payUrl,
                'payWanted' => $wantsCard,
                'payAmount' => $charge,
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

    /**
     * The extras the customer chose, as seat counts + a meet & greet flag, plus a
     * human-readable label list for the notes the office/driver see.
     *
     * @return array{meet_greet: bool, child_seats: int, booster_seats: int, infant_seats: int, stopovers: int, labels: array<int, string>}
     */
    private function extrasFrom(array $data): array
    {
        $meetGreet = (bool) ($data['meet_greet'] ?? false);
        $child = (int) ($data['child_seats'] ?? 0);
        $booster = (int) ($data['booster_seats'] ?? 0);
        $infant = (int) ($data['infant_seats'] ?? 0);
        $stops = (int) ($data['stopovers'] ?? 0);
        $wheelchair = (bool) ($data['wheelchair'] ?? false);
        $ribbons = (bool) ($data['ribbons'] ?? false);

        $labels = [];
        if ($meetGreet) {
            $labels[] = 'Meet & greet';
        }
        foreach ([[$child, 'child seat'], [$booster, 'booster seat'], [$infant, 'infant seat'], [$stops, 'extra stop']] as [$n, $word]) {
            if ($n > 0) {
                $labels[] = $n.'× '.$word.($n > 1 ? 's' : '');
            }
        }
        if ($wheelchair) {
            $labels[] = 'Wheelchair accessible';
        }
        if ($ribbons) {
            $labels[] = 'Wedding ribbons';
        }

        return [
            'meet_greet' => $meetGreet,
            'child_seats' => $child,
            'booster_seats' => $booster,
            'infant_seats' => $infant,
            'stopovers' => $stops,
            'wheelchair' => $wheelchair,
            'ribbons' => $ribbons,
            'labels' => $labels,
        ];
    }

    /** Does this journey arrive from an airport (so a flight number is required)? */
    private function isAirportArrival(string $journeyType, string $pickup, ?string $destination): bool
    {
        $pattern = '/\bairport\b|terminal|heathrow|gatwick|stansted|luton|\bt[1-5]\b/i';
        // One-way: only an arrival when the PICKUP is the airport (a departure to the
        // airport needs no flight). Return: either end may be the airport arrival.
        $haystack = $journeyType === 'return' ? $pickup.' '.(string) $destination : $pickup;

        return (bool) preg_match($pattern, $haystack);
    }

    /** The customer's free-text notes with the chosen extras appended, or null. */
    private function composedNotes(?string $notes, array $extraLabels): ?string
    {
        $parts = [];
        if (filled($notes)) {
            $parts[] = trim($notes);
        }
        if ($extraLabels) {
            $parts[] = 'Extras: '.implode(', ', $extraLabels);
        }

        return $parts ? implode("\n", $parts) : null;
    }

    /**
     * Combine an address with its postcode for pricing and the driver, without
     * doubling up if the address already contains that postcode.
     */
    private function withPostcode(?string $address, ?string $postcode): string
    {
        $address = trim((string) $address);
        $postcode = strtoupper(trim((string) $postcode));
        if ($postcode === '' || stripos($address, $postcode) !== false) {
            return $address;
        }

        return $address === '' ? $postcode : $address.', '.$postcode;
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
