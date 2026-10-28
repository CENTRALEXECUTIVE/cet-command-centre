<?php

namespace App\Console\Commands;

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

        foreach (['transfers', 'chauffeurs'] as $entity) {
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

        $this->info($found ? "Recorded {$found} missed payment(s)." : 'Nothing missed.');

        return self::SUCCESS;
    }
}
