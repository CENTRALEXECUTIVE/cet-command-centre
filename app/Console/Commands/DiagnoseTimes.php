<?php

namespace App\Console\Commands;

use App\Models\Booking;
use Illuminate\Console\Command;

/**
 * Prints the raw stored pickup time, source and how it displays for upcoming
 * bookings — so a time discrepancy can be diagnosed exactly (what's stored vs
 * what shows vs the app timezone). Read-only; changes nothing.
 */
class DiagnoseTimes extends Command
{
    protected $signature = 'cet:diagnose-times {--ref= : a specific booking reference} {--limit=10}';

    protected $description = 'Show stored vs displayed pickup times to diagnose timezone issues';

    public function handle(): int
    {
        $this->line('App timezone: '.config('app.timezone').' | now(): '.now()->format('Y-m-d H:i').' | now(UTC): '.now('UTC')->format('Y-m-d H:i'));
        $this->newLine();

        $query = Booking::query()->orderBy('pickup_at');
        if ($ref = $this->option('ref')) {
            $query->where('reference', $ref)->orWhere('external_reference', $ref);
        } else {
            $query->where('pickup_at', '>=', now()->subDay());
        }

        $rows = $query->limit((int) $this->option('limit'))->get()->map(fn (Booking $b) => [
            'ref' => $b->reference,
            'source_system' => $b->source_system ?? '—',
            'source' => $b->source ?? '—',
            'stored (raw)' => $b->getRawOriginal('pickup_at'),
            'displays' => $b->pickup_at?->format('D d M H:i'),
        ])->all();

        if (empty($rows)) {
            $this->warn('No matching bookings.');

            return self::SUCCESS;
        }

        $this->table(['Ref', 'source_system', 'source', 'stored (raw)', 'displays'], $rows);

        return self::SUCCESS;
    }
}
