<?php

namespace App\Console\Commands;

use App\Models\PricingZone;
use App\Models\VehicleType;
use App\Services\Pricing\FixedPriceService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Imports CET's fixed-price matrix from a CSV exported from ETO, so the full
 * (and authoritative) price list is loaded rather than hand-transcribed.
 *
 * Expected header (case-insensitive), one row per zone→destination:
 *   zone, destination, deposit, Estate, Executive, 8 Seater, 8 Seater XL,
 *   Executive 8 Seater, Luxury
 * Blank price cells are skipped. Re-running updates existing prices.
 */
class ImportFixedPrices extends Command
{
    protected $signature = 'cet:import-fixed-prices {path : Path to the CSV file}';

    protected $description = 'Import the fixed-price matrix from an ETO CSV export';

    public function handle(FixedPriceService $service): int
    {
        $path = $this->argument('path');
        if (! is_readable($path)) {
            $this->error("Cannot read file: {$path}");

            return self::FAILURE;
        }

        $rows = array_map('str_getcsv', file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $header = array_map(fn ($h) => strtolower(trim($h)), array_shift($rows));

        $vehicleSlugs = VehicleType::pluck('id', 'slug');
        $created = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $data = array_combine($header, array_pad($row, count($header), null));
            $zoneName = trim($data['zone'] ?? '');
            $destination = trim($data['destination'] ?? '');

            if ($zoneName === '' || $destination === '') {
                $skipped++;

                continue;
            }

            $zone = PricingZone::firstOrCreate(
                ['slug' => Str::slug($zoneName)],
                ['name' => $zoneName],
            );
            $deposit = (float) ($data['deposit'] ?? 0);

            foreach (FixedPriceService::COLUMN_TO_SLUG as $column => $slug) {
                $value = $data[$column] ?? null;
                if ($value === null || trim((string) $value) === '' || ! is_numeric($value) || (float) $value <= 0) {
                    continue;
                }
                if (! isset($vehicleSlugs[$slug])) {
                    continue;
                }
                $service->upsert($zone, $destination, VehicleType::find($vehicleSlugs[$slug]), (float) $value, $deposit);
                $created++;
            }
        }

        $this->info("Imported {$created} fixed price(s); skipped {$skipped} row(s).");

        return self::SUCCESS;
    }
}
