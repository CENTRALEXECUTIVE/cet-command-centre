<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Services\BookingStatusService;
use App\Services\Payments\SquareBookingPaymentService;
use App\Services\Payments\StripePaymentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Safety net for card payments: asks Stripe and Square directly for recent
 * completed fare payments and records any the webhook missed — then runs the
 * normal paid flow, so the customer's paid confirmation and the office's
 * "Payment received" email go out within minutes even if a webhook never
 * arrived (bad signature, wrong mode, hosting blip, mid-deploy error).
 *
 * Uses the exact webhook parsing, so the same rules apply: only FARE- payments
 * for a known booking, idempotent by payment id (a payment the webhook already
 * recorded is skipped, and every email sends at most once).
 */
class ReconcilePayments extends Command
{
    protected $signature = 'cet:reconcile-payments {--days=3 : How far back to look}';

    protected $description = 'Record card fare payments whose webhook never arrived, and send the paid emails';

    public function handle(StripePaymentService $stripe, SquareBookingPaymentService $square, BookingStatusService $status): int
    {
        $since = now()->subDays(max(1, (int) $this->option('days')));
        $found = 0;

        foreach ($stripe->recentCompletedSessions($since) as $session) {
            $booking = $stripe->recordFareFromWebhook(['type' => 'checkout.session.completed', 'data' => ['object' => $session]]);
            if ($booking) {
                $found++;
                Log::warning('[reconcile] Stripe payment the webhook missed — recorded now', ['booking' => $booking->reference, 'session' => $session['id'] ?? null]);
                $status->onFarePaid($booking->fresh());
                $this->line('Stripe: recorded '.$booking->reference);
            }
        }

        // The sister's own Square account only — without one, 'chauffeurs' is the
        // main account again and would just re-read the same payments.
        foreach ($square->sisterConfigured() ? ['transfers', 'chauffeurs'] : ['transfers'] as $entity) {
            foreach ($square->recentPayments($entity, $since) as $payment) {
                $booking = $square->recordFareFromWebhook(['data' => ['object' => ['payment' => $payment]]], $entity);
                if ($booking) {
                    $found++;
                    Log::warning('[reconcile] Square payment the webhook missed — recorded now', ['booking' => $booking->reference, 'entity' => $entity, 'payment' => $payment['id'] ?? null]);
                    $status->onFarePaid($booking->fresh());
                    $this->line('Square ('.$entity.'): recorded '.$booking->reference);
                }
            }
        }

        // Retry the paid emails for payments already recorded whose email didn't
        // go (a send failed, or the paid flow errored after the money was saved).
        // Each email still sends at most once per payment.
        $retried = 0;
        Booking::query()
            ->where('updated_at', '>=', $since)
            ->whereNotNull('meta->square_payment')
            ->get()
            // Only payments taken inside the window — never re-announce an old
            // payment just because its booking was edited recently.
            ->filter(fn (Booking $b) => filled($b->meta['square_payment']['at'] ?? null)
                && \Illuminate\Support\Carbon::parse($b->meta['square_payment']['at'])->gte($since))
            ->filter(fn (Booking $b) => $status->paidEmailsOutstanding($b))
            ->each(function (Booking $b) use ($status, &$retried) {
                $retried++;
                Log::warning('[reconcile] paid emails outstanding — retrying', ['booking' => $b->reference]);
                $status->onFarePaid($b);
            });

        $this->info(($found ? "Recorded {$found} missed payment(s)." : 'Nothing missed.').($retried ? " Retried paid emails for {$retried}." : ''));

        return self::SUCCESS;
    }
}
