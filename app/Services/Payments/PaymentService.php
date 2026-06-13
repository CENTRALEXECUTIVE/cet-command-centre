<?php

namespace App\Services\Payments;

use App\Enums\PaymentMethod;
use App\Models\Booking;
use App\Models\Payment;

/**
 * Creates the payment record for a booking and routes it by method:
 *  - card    → a Tide hosted payment link is generated (no card data stored)
 *  - cash    → flagged separately as outstanding cash (💰)
 *  - account → left for automatic VAT invoicing per corporate account
 */
class PaymentService
{
    public function __construct(private readonly TideService $tide) {}

    public function createForBooking(Booking $booking): Payment
    {
        $amount = (float) ($booking->final_price ?? $booking->quoted_price ?? 0);
        $method = $booking->payment_method ?? PaymentMethod::Card;

        return match ($method) {
            PaymentMethod::Card => $this->cardPayment($booking, $amount),
            PaymentMethod::Cash => $this->record($booking, PaymentMethod::Cash, $amount, 'pending'),
            PaymentMethod::Account => $this->record($booking, PaymentMethod::Account, $amount, 'pending'),
        };
    }

    private function cardPayment(Booking $booking, float $amount): Payment
    {
        $payment = $this->record($booking, PaymentMethod::Card, $amount, $amount > 0 ? 'link_sent' : 'pending');

        if ($amount > 0) {
            $payment->update([
                'tide_payment_link' => $this->tide->createPaymentLink($booking, $amount),
            ]);
        }

        return $payment;
    }

    private function record(Booking $booking, PaymentMethod $method, float $amount, string $status): Payment
    {
        return $booking->payments()->create([
            'method' => $method->value,
            'amount' => $amount,
            'status' => $status,
        ]);
    }

    /** Mark a payment paid (e.g. Tide webhook or cash collected). */
    public function markPaid(Payment $payment): Payment
    {
        $payment->update(['status' => 'paid', 'paid_at' => now()]);
        $payment->booking?->update(['payment_status' => 'paid']);

        return $payment;
    }
}
