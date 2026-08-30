<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\Watchdog\AdminAlerts;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Public, embeddable CUSTOMER ACCOUNT widget (mirrors ETO's customer account):
 * a customer looks up and manages their own bookings. To stay secure without a
 * whole new login system, they verify by proving they hold a booking — their
 * reference PLUS the phone or email on it. Once verified their customer id is
 * held in the session and every booking on that record is shown.
 *
 * Managing a booking (cancel / change) NEVER auto-acts and never messages the
 * customer — it raises a request that notifies the office to handle, keeping the
 * "nothing auto-sends / calendar untouched" rules intact.
 */
class CustomerAccountController extends Controller
{
    private const SESSION_KEY = 'widget_customer_id';

    public function __construct(private readonly AdminAlerts $adminAlerts) {}

    private function frameAncestors(): string
    {
        return "frame-ancestors 'self' https://centralexecutivetransfers.co.uk "
            .'https://*.centralexecutivetransfers.co.uk http://localhost:* http://127.0.0.1:*';
    }

    /** The account page: a lookup form, or the customer's bookings once verified. */
    public function show(Request $request): \Illuminate\Http\Response
    {
        $customerId = $request->session()->get(self::SESSION_KEY);
        $bookings = $customerId
            ? Booking::where('customer_id', $customerId)->with(['vehicleType', 'driver'])->orderByDesc('pickup_at')->get()
            : collect();

        return response()
            ->view('widget.account', [
                'verified' => (bool) $customerId,
                'bookings' => $bookings,
            ])
            ->header('Content-Security-Policy', $this->frameAncestors());
    }

    /** Verify ownership: a booking reference + the phone/email held on it. */
    public function verify(Request $request): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:32'],
            'contact' => ['required', 'string', 'max:160'],
        ]);

        $ref = trim($data['reference']);
        $booking = Booking::where('reference', $ref)->orWhere('external_reference', $ref)->first();

        if (! $booking || ! $this->contactMatches($booking, $data['contact'])) {
            return back()->with('account_error', 'We couldn’t match that reference and contact. Please check and try again.');
        }

        $request->session()->put(self::SESSION_KEY, $booking->customer_id);

        return redirect()->route('widget.account');
    }

    /** Raise a change/cancellation REQUEST on the customer's booking (office acts). */
    public function requestChange(Request $request, Booking $booking): \Illuminate\Http\RedirectResponse
    {
        $customerId = $request->session()->get(self::SESSION_KEY);
        abort_if(! $customerId || $booking->customer_id !== (int) $customerId, 403);

        $data = $request->validate([
            'type' => ['required', 'in:cancel,change'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        $name = $booking->customer?->name ?? 'A customer';
        $what = $data['type'] === 'cancel' ? 'cancellation' : 'change';
        $when = $booking->pickup_at?->format('D d M, H:i');
        $body = $name.' requested a '.$what.' on '.$booking->reference.' ('.$when.')'
            .(filled($data['message'] ?? null) ? ': '.$data['message'] : '').'. Handle it in the app.';

        \App\Models\WatchdogEvent::log('web_booking', 'Customer '.$what.' request — '.$booking->reference, 'warning', $booking);
        $this->adminAlerts->notify('web_booking', '✏️ Customer '.$what.' request — '.$name, $body, 'warning', $booking);

        // Record it on the booking so the office sees it on the booking page too.
        $booking->forceFill(['meta' => array_merge($booking->meta ?? [], [
            'customer_requests' => array_merge($booking->meta['customer_requests'] ?? [], [[
                'type' => $data['type'],
                'message' => $data['message'] ?? null,
                'at' => now()->toIso8601String(),
            ]]),
        ])])->save();

        return back()->with('account_status', 'Thanks — we’ve sent your '.$what.' request to the office. We’ll be in touch to confirm.');
    }

    public function logout(Request $request): \Illuminate\Http\RedirectResponse
    {
        $request->session()->forget(self::SESSION_KEY);

        return redirect()->route('widget.account');
    }

    /** Does the given phone/email match the contact held on this booking? */
    private function contactMatches(Booking $booking, string $contact): bool
    {
        $contact = strtolower(trim($contact));

        // Email match (booking's customer email).
        $email = strtolower((string) ($booking->customer?->email ?? ''));
        if ($email !== '' && $email === $contact) {
            return true;
        }

        // Phone match — compare on digits only, so formatting/+44/0 differences
        // don't matter.
        $digits = fn (string $s) => preg_replace('/\D+/', '', $s);
        $given = ltrim($digits($contact), '0');
        $onFile = ltrim($digits((string) ($booking->customerContactNumber() ?? '')), '0');
        // Normalise a leading 44 so 07… and +447… line up.
        $norm = fn (string $d) => Str::start(preg_replace('/^44/', '', $d), '');

        return $given !== '' && $norm($given) === $norm($onFile);
    }
}
