<?php

namespace App\Services\Payments;

use App\Models\Booking;

/**
 * Routes a booking's card payment to the right provider by billing entity: the
 * non-VAT sister company (Central Executive Transfers PVT LTD) is taken via
 * Stripe; the VAT-registered company via Square. One front door so the public
 * widget, the office transaction link and anything else all route the same way.
 *
 * The method signature mirrors SquareBookingPaymentService::createCheckoutUrl so
 * callers can swap this in with no other changes.
 */
class PaymentGateway
{
    public function __construct(
        private readonly SquareBookingPaymentService $square,
        private readonly StripePaymentService $stripe,
    ) {}

    /** True when ANY card provider is configured (shows the "pay by card" option). */
    public function enabled(): bool
    {
        return $this->square->enabled('transfers') || $this->stripe->enabled();
    }

    /** True when THIS booking's entity has a working provider. */
    public function enabledForBooking(Booking $booking): bool
    {
        $entity = $booking->billingEntity();

        return ($entity === 'chauffeurs' && $this->stripe->enabled())
            || $this->square->enabled($entity);
    }

    /**
     * A hosted checkout URL to pay this booking's fare via the correct provider,
     * or null if that entity has no provider configured. billingEntity() only
     * resolves to the sister ('chauffeurs') when its provider is ready, so there's
     * no dead-end routing.
     */
    public function createCheckoutUrl(Booking $booking, float $amount, ?string $redirectUrl = null, ?string $label = null): ?string
    {
        $entity = $booking->billingEntity();

        if ($entity === 'chauffeurs' && $this->stripe->enabled()) {
            return $this->stripe->createCheckoutUrl($booking, $amount, $redirectUrl, $label);
        }

        // A PVT LTD (non-VAT) booking with no Stripe and no sister Square account
        // would be paid into the VAT company's Square while its invoice and email
        // say PVT LTD. Still take the payment (never lose the booking) but say so.
        if ($entity === 'chauffeurs' && ! $this->square->sisterConfigured()) {
            \Illuminate\Support\Facades\Log::warning('[payments] PVT booking '.$booking->reference.' charged via the Ltd Square account — Stripe is not configured');
            \App\Models\WatchdogEvent::log(
                'payment_entity_fallback',
                '⚠ Non-VAT booking '.($booking->external_reference ?: $booking->reference).' is being paid into the Ltd (VAT) Square account — Stripe isn’t set up. Check Settings → payments.',
                'warning',
                $booking,
            );
        }

        return $this->square->enabled($entity)
            ? $this->square->createCheckoutUrl($booking, $amount, $redirectUrl, $label)
            : null;
    }
}
