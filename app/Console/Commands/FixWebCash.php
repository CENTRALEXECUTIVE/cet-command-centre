<?php

namespace App\Console\Commands;

use App\Enums\PaymentMethod;
use App\Models\Booking;
use Illuminate\Console\Command;

/**
 * Online bookings used to be stamped payment_method => 'card' even though no
 * card is taken at booking time — so the customer paying cash on the day had
 * their driver told "collect nothing" and the fare was lost. The widget now
 * defaults to 'cash'; this backfills the bookings taken BEFORE that fix.
 *
 * Scope is deliberately tight so a genuine card job is never touched:
 *   - source = 'web' (only online-form bookings — never ETO/intake)
 *   - payment_method = card
 *   - NOT paid online (payment_status != paid AND no meta.square_payment)
 *   - still live (not cancelled / no-show / completed — settled history is left
 *     alone so payroll isn't disturbed)
 *
 * Reports by default; pass --apply to make the change. Never touches the calendar.
 *
 * Usage:  php artisan cet:fix-web-cash          (report only)
 *         php artisan cet:fix-web-cash --apply  (apply)
 */
class FixWebCash extends Command
{
    protected $signature = 'cet:fix-web-cash {--apply : write the change (otherwise report only)}';

    protected $description = 'Set pre-fix web bookings to cash so drivers collect the fare';

    public function handle(): int
    {
        $bookings = Booking::query()
            ->where('source', 'web')
            ->where('payment_method', PaymentMethod::Card->value)
            ->where(fn ($q) => $q->whereNull('payment_status')->orWhere('payment_status', '!=', 'paid'))
            ->whereNotIn('status', ['cancelled', 'no_show', 'complete'])
            ->get()
            // Never touch a booking whose fare was actually paid online via Square.
            ->filter(fn (Booking $b) => empty($b->meta['square_payment'] ?? null));

        if ($bookings->isEmpty()) {
            $this->info('No web bookings need fixing — all good.');

            return self::SUCCESS;
        }

        $apply = $this->option('apply');
        foreach ($bookings as $b) {
            $when = optional($b->pickup_at)->format('D d M, H:i') ?? '—';
            $this->line(($apply ? 'Fixing ' : 'Would fix ')."{$b->reference}  {$when}  {$b->pickup_address} → {$b->destination_address}");
            if ($apply) {
                $b->payment_method = PaymentMethod::Cash;
                $b->save();
            }
        }

        $verb = $apply ? 'Set to cash' : 'WOULD set to cash';
        $this->info("{$verb}: {$bookings->count()} web booking(s).");
        if (! $apply) {
            $this->warn('Report only — nothing changed. Re-run with --apply to make the change.');
        }

        return self::SUCCESS;
    }
}
