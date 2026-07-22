<?php

namespace App\Services\Import;

use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use App\Models\Airport;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\VehicleType;
use App\Services\Pricing\FixedPriceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Imports historical bookings from an ETO (EasyTaxiOffice) CSV export.
 *
 * Bookings are created directly (NOT via BookingService) so importing historical
 * data does not fire live side effects — no WhatsApp confirmations, calendar
 * events or rotation moves. Rows are de-duplicated on the ETO reference so a
 * re-run is safe, and original timestamps are preserved for the parallel run.
 */
class EtoBookingImporter
{
    /** ETO status label → internal BookingStatus. "Request quote" is skipped. */
    private const STATUS_MAP = [
        'completed' => BookingStatus::Complete,
        'confirmed' => BookingStatus::Pending,
        'cancelled' => BookingStatus::Cancelled,
        'incomplete' => BookingStatus::NoShow,
    ];

    public function __construct(private readonly FixedPriceService $fixedPrices) {}

    /**
     * @return array{imported:int, updated:int, duplicates:int, skipped:int, errors:array<int,string>}
     */
    public function import(string $path, bool $dryRun = false): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return ['imported' => 0, 'updated' => 0, 'duplicates' => 0, 'skipped' => 0, 'errors' => ["Cannot open {$path}"]];
        }

        $header = null;
        $stats = ['imported' => 0, 'updated' => 0, 'duplicates' => 0, 'skipped' => 0, 'errors' => []];
        $line = 0;

        while (($row = fgetcsv($handle, 0, ';')) !== false) {
            $line++;
            if ($header === null) {
                $header = array_map(fn ($h) => trim($h), $row);

                continue;
            }
            if (count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue; // blank line
            }

            try {
                $data = $this->assoc($header, $row);
                $outcome = $dryRun ? $this->validateRow($data) : $this->importRow($data);
                $stats[$outcome]++;
            } catch (\Throwable $e) {
                $stats['errors'][] = "Line {$line}: {$e->getMessage()}";
            }
        }

        fclose($handle);

        return $stats;
    }

    /** @return 'imported'|'updated'|'duplicates'|'skipped' */
    private function importRow(array $data): string
    {
        $status = $this->mapStatus($data['Status'] ?? '');
        if (! $status) {
            return 'skipped'; // e.g. "Request quote"
        }

        $reference = $this->clean($data['Reference number'] ?? '');
        $existing = $reference
            ? Booking::where('source_system', 'eto')->where('external_reference', $reference)->first()
            : null;

        // The ETO export is the authoritative FINANCIAL record. If we already
        // have this booking (from the calendar/live feed), update its money and
        // status figures from the export rather than skip it — but leave the
        // calendar-sourced details (customer, addresses, driver, times) alone.
        if ($existing) {
            return $this->updateFinancials($existing, $data, $status);
        }

        return DB::transaction(function () use ($data, $status, $reference) {
            $customer = $this->resolveCustomer($data);
            $vehicleType = $this->resolveVehicleType($data['Vehicle type'] ?? '');
            $pickupAt = $this->parseDate($data['Journey date'] ?? '') ?? now();
            [$method, $paymentStatus] = $this->mapPayment($data['Payments'] ?? '', $status);
            $total = $this->money($data['Total'] ?? null);

            $booking = new Booking([
                'reference' => Booking::generateReference(),
                'source_system' => 'eto',
                'external_reference' => $reference ?: null,
                'customer_id' => $customer->id,
                'vehicle_type_id' => $vehicleType->id,
                'airport_id' => $this->detectAirport($data),
                'pickup_at' => $pickupAt,
                'pickup_address' => $this->decode($data['Pickup'] ?? '') ?: 'Unknown',
                'destination_address' => $this->decode($data['Dropoff'] ?? '') ?: 'Unknown',
                'flight_number' => $this->clean($data['Arrival flight number'] ?? '') ?: $this->clean($data['Departure flight number'] ?? '') ?: null,
                'passengers' => max(1, (int) $this->clean($data['Passengers'] ?? '1')),
                'luggage' => $this->suitcases($data) + $this->handLuggage($data),
                'status' => $status->value,
                'quoted_price' => $total,
                'final_price' => $status === BookingStatus::Complete ? $total : null,
                'payment_method' => $method,
                'payment_status' => $paymentStatus,
                'source' => $this->mapSource($data['Source'] ?? ''),
                'meta' => array_filter([
                    'eto_status' => $this->clean($data['Status'] ?? ''),
                    'eto_source' => $this->clean($data['Source'] ?? ''),
                    'eto_vehicle' => $this->clean($data['Vehicle type'] ?? ''),
                    'eto_driver' => $this->clean($data['Driver'] ?? ''),
                    'eto_vehicle_reg' => $this->clean($data['Vehicle'] ?? ''),
                    'eto_customer' => $this->clean($data['Customer'] ?? ''),
                    'eto_via' => $this->decode($data['Via'] ?? '') ?: null,
                    'eto_meet_greet' => $this->clean($data['Meet & Greet'] ?? ''),
                    'total_amount' => $total,
                    // Keep the two luggage counts separate — both are needed.
                    'suitcases' => $this->suitcases($data),
                    'hand_luggage' => $this->handLuggage($data),
                ], fn ($v) => $v !== null && $v !== ''),
            ]);

            // Preserve the original audit dates.
            $booking->created_at = $this->parseDate($data['Created at'] ?? '') ?? $pickupAt;
            $booking->updated_at = $this->parseDate($data['Updated at'] ?? '') ?? $booking->created_at;
            $booking->save();

            return 'imported';
        });
    }

    /**
     * Update only the financial + status figures on an existing booking from the
     * authoritative ETO export. Never overwrites a price with a blank, and never
     * touches customer/addresses/driver/pickup (the calendar owns those).
     *
     * @return 'updated'
     */
    private function updateFinancials(Booking $booking, array $data, BookingStatus $status): string
    {
        [, $paymentStatus] = $this->mapPayment($data['Payments'] ?? '', $status);
        $total = $this->money($data['Total'] ?? null);

        $fields = [
            'status' => $status->value,
            'payment_status' => $paymentStatus,
            'meta' => array_merge($booking->meta ?? [], array_filter([
                'eto_status' => $this->clean($data['Status'] ?? ''),
                'eto_driver' => $this->clean($data['Driver'] ?? ''),
                'total_amount' => $total,
                // Backfill the split luggage counts onto existing bookings too.
                'suitcases' => $this->suitcases($data),
                'hand_luggage' => $this->handLuggage($data),
            ], fn ($v) => $v !== null && $v !== '')),
        ];
        if ($total !== null) {
            $fields['quoted_price'] = $total;
            if ($status === BookingStatus::Complete) {
                $fields['final_price'] = $total;
            }
        }

        $booking->forceFill($fields)->save();

        return 'updated';
    }

    /** Dry-run validation: returns the outcome without writing. */
    private function validateRow(array $data): string
    {
        $status = $this->mapStatus($data['Status'] ?? '');
        if (! $status) {
            return 'skipped';
        }
        $reference = $this->clean($data['Reference number'] ?? '');
        if ($reference && Booking::where('source_system', 'eto')->where('external_reference', $reference)->exists()) {
            return 'updated'; // existing booking → financials will be refreshed
        }
        $this->resolveVehicleType($data['Vehicle type'] ?? ''); // throws if unmappable

        return 'imported';
    }

    private function resolveCustomer(array $data): Customer
    {
        $phone = $this->clean($data['Phone number'] ?? '');
        $email = $this->clean($data['Email'] ?? '');
        $name = $this->clean($data['Lead passenger name'] ?? '')
            ?: $this->clean($data['Passenger name'] ?? '')
            ?: $this->clean($data['Customer'] ?? '')
            ?: 'Unknown';

        $customer = Customer::query()
            ->when($phone, fn ($q) => $q->orWhere('phone', $phone))
            ->when($email, fn ($q) => $q->orWhere('email', $email))
            ->first();

        return $customer ?? Customer::create(['name' => $name, 'phone' => $phone ?: null, 'email' => $email ?: null]);
    }

    private function resolveVehicleType(string $label): VehicleType
    {
        $slug = $this->fixedPrices->slugForColumn($label) ?? 'executive';

        $vt = VehicleType::where('slug', $slug)->first();
        if (! $vt) {
            throw new \RuntimeException("No vehicle type for '{$label}' (slug {$slug}).");
        }

        return $vt;
    }

    private function mapStatus(string $label): ?BookingStatus
    {
        return self::STATUS_MAP[strtolower(trim($label))] ?? null;
    }

    /** @return array{0:string,1:string} [payment_method, payment_status] */
    private function mapPayment(string $payments, BookingStatus $status): array
    {
        $text = strtolower($payments);
        $method = match (true) {
            str_contains($text, 'cash') => PaymentMethod::Cash->value,
            str_contains($text, 'account'), str_contains($text, 'invoice') => PaymentMethod::Account->value,
            str_contains($text, 'stripe'), str_contains($text, 'card') => PaymentMethod::Card->value,
            default => PaymentMethod::Card->value,
        };

        $paymentStatus = match (true) {
            str_contains($text, 'pending') => 'balance_remaining',
            str_contains($text, 'paid') => 'paid',
            default => 'pending',
        };

        return [$method, $paymentStatus];
    }

    private function mapSource(string $source): string
    {
        return match (strtolower(trim($source))) {
            'website', 'central executive transfers', 'central travels', 'direct' => 'web',
            'email' => 'email',
            'whatsapp' => 'whatsapp',
            default => 'phone',
        };
    }

    private function detectAirport(array $data): ?int
    {
        $haystack = ($data['Pickup'] ?? '').' '.($data['Dropoff'] ?? '');
        if (preg_match('/\(([A-Z]{3})\)/', $haystack, $m)) {
            return Airport::where('code', $m[1])->value('id');
        }

        return null;
    }

    private function parseDate(?string $value): ?Carbon
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        foreach (['d/m/Y H:i', 'd/m/Y H:i:s', 'd/m/Y'] as $format) {
            try {
                // ETO exports the booking's UK LOCAL time — the exact time on the
                // customer email and the calendar. Parse it as-is in the app
                // timezone (NO UTC conversion), so 06:45 stays 06:45.
                return Carbon::createFromFormat($format, $value, config('app.timezone'));
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    private function money(mixed $value): ?float
    {
        $value = trim((string) $value);

        return $value === '' || ! is_numeric($value) ? null : (float) $value;
    }

    /** Number of suitcases (hold luggage) from the ETO row. */
    private function suitcases(array $data): int
    {
        return max(0, (int) $this->clean($data['Suitcases'] ?? '0'));
    }

    /** Number of hand-luggage items from the ETO row. */
    private function handLuggage(array $data): int
    {
        return max(0, (int) $this->clean($data['Hand luggage'] ?? '0'));
    }

    /** @return array<string, string> */
    private function assoc(array $header, array $row): array
    {
        return array_combine($header, array_pad($row, count($header), ''));
    }

    private function clean(string $value): string
    {
        $value = trim($value);

        return $value === '' ? '' : $value;
    }

    private function decode(string $value): string
    {
        return trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5));
    }
}
