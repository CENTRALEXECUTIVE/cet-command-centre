<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\Customer;
use App\Support\Phone;
use Illuminate\Console\Command;

/**
 * Finds bookings filed under the WRONG customer record — a booking whose lead
 * passenger is one person, but whose linked customer record (shared with that
 * person's OTHER bookings) is someone else entirely. This is the "why does
 * Huzayfa's booking say Neil?" class of bug: a booking stapled to an unrelated
 * saved customer, usually via a coincidental/typo phone match.
 *
 * Read-only by default (just reports). With --fix it re-files each suspect under
 * its OWN customer (found by the booking's own number, or created from the
 * booking's passenger name), leaving the shared record completely untouched.
 */
class CheckCustomerLinks extends Command
{
    protected $signature = 'cet:check-customer-links {--fix : re-file suspect bookings under their own customer}';

    protected $description = 'Find (and optionally fix) bookings filed under the wrong customer record';

    public function handle(): int
    {
        $fix = (bool) $this->option('fix');

        // How many bookings each customer record carries — a "shared" record used
        // by several bookings is the tell-tale of a wrong link.
        $counts = Booking::query()->whereNotNull('customer_id')
            ->selectRaw('customer_id, count(*) as c')->groupBy('customer_id')
            ->pluck('c', 'customer_id');

        $suspects = [];
        Booking::with('customer')->whereNotNull('customer_id')->chunkById(200, function ($bookings) use (&$suspects, $counts) {
            foreach ($bookings as $b) {
                $record = trim((string) ($b->customer?->name ?? ''));
                $passenger = trim((string) ($b->displayName() ?? ''));
                if ($record === '' || $passenger === '' || strcasecmp($passenger, 'Customer') === 0) {
                    continue;
                }
                // Only suspicious when the record is SHARED by other bookings AND the
                // passenger isn't that record's person. (A solo record whose name
                // differs is just a booker-vs-passenger booking — that's fine.)
                $shared = (int) ($counts[$b->customer_id] ?? 0) > 1;
                if ($shared && $this->normalise($record) !== $this->normalise($passenger)) {
                    $suspects[] = $b;
                }
            }
        });

        if (empty($suspects)) {
            $this->info('No wrongly-linked bookings found — every booking sits under its own customer.');

            return self::SUCCESS;
        }

        $this->warn(count($suspects).' booking(s) are filed under a shared customer record that is a different person:');
        $this->table(
            ['Ref', 'Passenger (booking)', 'Filed under (record)', 'Record phone', 'Booking number'],
            collect($suspects)->map(fn (Booking $b) => [
                $b->reference,
                $b->displayName(),
                $b->customer?->name,
                $b->customer?->phone,
                $this->bookingOwnNumber($b) ?? '—',
            ])->all(),
        );

        if (! $fix) {
            $this->line('');
            $this->info('Run again with --fix to re-file each under its own customer (the shared records are left untouched).');

            return self::SUCCESS;
        }

        $moved = 0;
        foreach ($suspects as $b) {
            $target = $this->ownCustomerFor($b);
            if ($target && $target->id !== $b->customer_id) {
                $b->forceFill(['customer_id' => $target->id])->save();
                $moved++;
                $this->line("  • {$b->reference}: re-filed under {$target->name}".($target->phone ? " ({$target->phone})" : '').'.');
            }
        }

        $this->info("Re-filed {$moved} booking(s). The shared customer records were not changed.");

        return self::SUCCESS;
    }

    /** Find or create the booking's OWN customer (by its own number, else by name). */
    private function ownCustomerFor(Booking $b): ?Customer
    {
        $name = trim((string) ($b->displayName() ?? '')) ?: 'Customer';
        $number = $this->bookingOwnNumber($b);

        if ($number) {
            $existing = Customer::where('phone', $number)->first();
            if ($existing) {
                return $existing;
            }
        }

        return Customer::create(array_filter([
            'name' => $name,
            'phone' => $number,
        ], fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * The booking's OWN contact number — but never the wrongly-linked record's
     * phone (that would just re-match the wrong customer). Null when the only
     * number we have is the shared record's.
     */
    private function bookingOwnNumber(Booking $b): ?string
    {
        $number = $b->customerContactNumber();
        $recordPhone = $b->customer?->phone;
        if ($number && $recordPhone && Phone::wa($number) === Phone::wa($recordPhone)) {
            return null; // it's the shared record's number, not the booking's own
        }

        return $number ?: null;
    }

    private function normalise(string $name): string
    {
        return strtolower(preg_replace('/\s+/', ' ', trim($name)));
    }
}
