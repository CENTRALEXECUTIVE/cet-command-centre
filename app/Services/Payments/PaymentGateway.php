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

        return $this->square->enabled($entity)
            ? $this->square->createCheckoutUrl($booking, $amount, $redirectUrl, $label)
            : null;
    }
}
