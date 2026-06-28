<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Models\Airport;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use App\Models\VehicleType;
use App\Services\Calendar\GoogleCalendarService;
use App\Services\CalendarEventBuilder;
use App\Services\Pricing\FixedPriceService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Imports bookings from the operator's curated CET CSV — the authoritative source
 * with the correct passengers, luggage, stops, driver names and payment emojis —
 * and pushes each to the calendar in the exact layout. Matched by reference (no
 * duplicates). Rows marked "DELETED FROM CALENDAR" in Notes are skipped.
 *
 * Expected columns: Reference, Journey Date, Journey Time, Customer Name, Phone,
 * Email, Pickup, Via, Dropoff, Flight Number, Meet & Greet, Vehicle Type,
 * Passengers, Suitcases, Hand Luggage, Child Seats, Booster Seats, Total,
 * Payment Status, Payment Method, Driver, Notes.
 */
class ImportBookingsCsv extends Command
{
    protected $signature = 'cet:import-bookings-csv {path}';

    protected $description = 'Import the curated CET bookings CSV (full details + driver) and push to calendar';

    public function handle(FixedPriceService $fixedPrices, CalendarEventBuilder $builder, GoogleCalendarService $google): int
    {
        $handle = fopen($this->argument('path'), 'r');
        if ($handle === false) {
            $this->error('Cannot open '.$this->argument('path'));

            return self::FAILURE;
        }

        $header = array_map('trim', fgetcsv($handle));
        $idx = array_flip($header);
        $abdi = User::where('email', 'abdi@centralexecutivetransfers.co.uk')->first();
        $maj = User::where('email', 'maj@centralexecutivetransfers.co.uk')->first();

        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'pushed' => 0];

        $cols = count($header);
        while (($r = fgetcsv($handle)) !== false) {
            if (count(array_filter($r, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }
            $r = $this->repairRow($r, $cols);
            $get = fn (string $k) => isset($idx[$k]) ? trim((string) ($r[$idx[$k]] ?? '')) : '';

            $ref = $this->stripEmoji($get('Reference'));
            $notes = $get('Notes');
            // Skip empties, "DELETED" rows, and known ETO test references (rule 12).
            if ($ref === '' || in_array($ref, ['1T0OUV', 'MRHES3'], true) || stripos($notes, 'DELETED FROM CALENDAR') !== false) {
                $stats['skipped']++;

                continue;
            }

            $pickupAt = $this->parseDateTime($get('Journey Date'), $get('Journey Time'));
            if (! $pickupAt) {
                $stats['skipped']++;

                continue;
            }

            $rawName = $get('Customer Name');
            $name = trim(preg_replace('/\s*\(.*\)\s*/', '', $rawName)) ?: 'Customer';
            $booker = preg_match('/\((.*)\)/', $rawName, $m) ? trim($m[1]) : null;
            $phone = $get('Phone');
            $customer = $this->resolveCustomer($name, $phone, $get('Email'));

            $vehicle = $this->resolveVehicle($fixedPrices, $get('Vehicle Type'));
            $pickup = $get('Pickup');
            $dropoff = $get('Dropoff');
            $airportId = $this->detectAirport($pickup.' '.$dropoff);

            $driverCol = strtoupper($get('Driver'));
            $driverId = $driverCol === 'ABDI' ? $abdi?->id : ($driverCol === 'MAJ' ? $maj?->id : null);
            $driverTag = $driverCol !== '' ? $driverCol : strtoupper($vehicle->name);

            $childSeat = ((int) $get('Child Seats') > 0) || ((int) $get('Booster Seats') > 0) || str_contains($notes, '🚼');
            [$payMethod, $payStatus] = $this->payment($get('Payment Status'), $get('Payment Method'));
            $meetGreet = preg_match('/\b(yes|required|true)\b/i', $get('Meet & Greet')) === 1;

            $where = $this->airportCode($pickup.' '.$dropoff) ?: 'FREE ROAM';
            $meta = [
                'where' => $where,
                'lead_name' => $name, // lead passenger — always shown, never the booker/company (rule 9)
                'driver_tag' => $driverTag,
                'luggage_text' => $this->luggageText($get('Suitcases'), $get('Hand Luggage')),
                'stops' => $this->stops($get('Via')),
                'journey_label' => $this->journeyLabel($pickup, $dropoff, $airportId, $meetGreet),
                'payment_text' => $this->paymentText($get('Total'), $get('Payment Status'), $get('Payment Method')),
                'money_emoji' => $this->moneyEmoji($notes, $payStatus, $payMethod, str_ends_with($ref, 'b')),
                'child_seat' => $childSeat,
                'child_seats' => (int) $get('Child Seats'),
                'booster_seats' => (int) $get('Booster Seats'),
                'meet_and_greet' => $meetGreet,
                'contact_no' => $phone,
                'booker_name' => $booker,
            ];

            $fields = [
                'customer_id' => $customer->id,
                'vehicle_type_id' => $vehicle->id,
                'airport_id' => $airportId,
                'driver_id' => $driverId,
                'is_return_leg' => str_ends_with($ref, 'b'),
                'pickup_at' => $pickupAt,
                'pickup_address' => $pickup ?: 'Unknown',
                'destination_address' => $dropoff ?: 'Unknown',
                'flight_number' => $this->flightNumber($get('Flight Number')),
                'passengers' => (int) ($get('Passengers') ?: 1),
                'special_requests' => $this->cleanNotes($notes) ?: null,
                'payment_status' => $payStatus,
                'payment_method' => $payMethod,
                'meta' => $meta,
            ];

            $existing = Booking::where('source_system', 'eto')->where('external_reference', $ref)->first();
            if ($existing) {
                $existing->forceFill($fields)->save();
                $booking = $existing;
                $stats['updated']++;
            } else {
                $booking = Booking::create($fields + [
                    'reference' => Booking::generateReference(),
                    'source_system' => 'eto',
                    'external_reference' => $ref,
                    'status' => BookingStatus::Pending->value,
                    'source' => 'import',
                ]);
                $stats['created']++;
            }

            $event = $builder->buildFor($booking->fresh(['customer', 'vehicleType', 'airport', 'driver']));
            if ($google->push($event)) {
                $stats['pushed']++;
            }
        }
        fclose($handle);

        $this->info("Created {$stats['created']}, updated {$stats['updated']}, skipped {$stats['skipped']}; pushed {$stats['pushed']} to calendar.");

        return self::SUCCESS;
    }

    /**
     * Repair a row broken by an unquoted Drop-off address (commas inside it shift
     * the columns). The Drop-off is column 8; the 13 columns after it (Flight
     * Number … Notes) are comma-free and reliable, so rebuild Drop-off from the
     * overflow and keep the last 13 as the tail.
     *
     * @param  list<string>  $r
     * @return list<string>
     */
    private function repairRow(array $r, int $cols): array
    {
        $f = count($r);
        if ($f <= $cols) {
            return $r;
        }
        $tailLen = $cols - 9; // Flight Number … Notes
        $head = array_slice($r, 0, 8); // Reference … Via
        $dropoff = implode(', ', array_slice($r, 8, $f - 8 - $tailLen));
        $tail = array_slice($r, $f - $tailLen);

        return array_merge($head, [$dropoff], $tail);
    }

    private function resolveCustomer(string $name, string $phone, string $email): Customer
    {
        $customer = Customer::query()
            ->when($phone, fn ($q) => $q->orWhere('phone', $phone))
            ->when($email, fn ($q) => $q->orWhere('email', $email))
            ->first();

        return $customer ?? Customer::create(['name' => $name, 'phone' => $phone ?: null, 'email' => $email ?: null]);
    }

    private function resolveVehicle(FixedPriceService $fixedPrices, string $label): VehicleType
    {
        $map = [
            'executive' => 'executive', 'estate' => 'estate', 'v class' => 'v-class', 'minibus' => 'minibus-8',
            'minibus 8 seater' => 'minibus-8', '8 seater' => 'minibus-8', 'rolls royce' => 'rolls-royce-ghost',
        ];
        $slug = $map[strtolower(trim($label))] ?? $fixedPrices->slugForColumn($label) ?? 'executive';

        return VehicleType::where('slug', $slug)->first() ?? VehicleType::where('slug', 'executive')->firstOrFail();
    }

    /** Airport name/area aliases — for the airport-hotel rule (rule 8). */
    private const AIRPORT_ALIASES = [
        'MAN' => ['manchester airport', 'm90'],
        'LHR' => ['heathrow', 'tw6'],
        'LGW' => ['gatwick', 'rh6'],
        'STN' => ['stansted'],
        'EMA' => ['east midlands airport', 'castle donington', 'de74'],
        'LBA' => ['leeds bradford', 'ls19'],
        'BHX' => ['birmingham airport', 'birmingham international', 'b26'],
        'LPL' => ['liverpool john lennon', 'liverpool airport', 'l24'],
        'HUY' => ['humberside airport', 'dn39'],
    ];

    /** IATA code for the job — bracketed code, else an airport name (hotels at airports). */
    private function airportCode(string $haystack): ?string
    {
        if (preg_match('/\(([A-Z]{3})\)/', $haystack, $m)) {
            return $m[1];
        }
        $needle = strtolower($haystack);
        foreach (self::AIRPORT_ALIASES as $code => $aliases) {
            foreach ($aliases as $alias) {
                if (str_contains($needle, $alias)) {
                    return $code;
                }
            }
        }

        return null;
    }

    private function detectAirport(string $haystack): ?int
    {
        $code = $this->airportCode($haystack);

        return $code ? Airport::where('code', $code)->value('id') : null;
    }

    /** Strip spaces from flight codes (FR 5073 → FR5073); keep "Private Charter". */
    private function flightNumber(string $flight): ?string
    {
        $flight = trim($flight);
        if ($flight === '') {
            return null;
        }
        if (stripos($flight, 'charter') !== false) {
            return 'Private Charter';
        }

        return preg_replace('/\s+/', '', $flight);
    }

    private function parseDateTime(string $date, string $time): ?Carbon
    {
        $value = trim($date.' '.$time);
        foreach (['d/m/Y H:i', 'd/m/Y H:i:s', 'd/m/Y'] as $fmt) {
            try {
                return Carbon::createFromFormat($fmt, trim($value));
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    /** "2 Suitcases + 1 Hand Luggage" from separate counts. */
    private function luggageText(string $suitcases, string $hand): string
    {
        $parts = [];
        if ((int) $suitcases > 0) {
            $parts[] = (int) $suitcases.' Suitcase'.((int) $suitcases > 1 ? 's' : '');
        }
        if ((int) $hand > 0) {
            $parts[] = (int) $hand.' Hand Luggage';
        }

        return $parts ? implode(' + ', $parts) : 'None';
    }

    /** @return list<string> */
    private function stops(string $via): array
    {
        $via = trim($via);
        if ($via === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', preg_split('#\s*/\s*|\r\n|\r|\n#', $via))));
    }

    private function journeyLabel(string $pickup, string $dropoff, ?int $airportId, bool $meetGreet): string
    {
        $pickupAirport = $airportId && stripos($pickup, 'airport') !== false;
        $dropoffAirport = stripos($dropoff, 'airport') !== false;
        if ($pickupAirport) {
            return $meetGreet ? 'Arrival (Meet & Greet)' : 'Arrival';
        }
        if ($dropoffAirport) {
            return 'Departure';
        }

        return 'Transfer';
    }

    /** @return array{0:string,1:string} [payment_method, payment_status] */
    private function payment(string $status, string $method): array
    {
        $s = strtolower($status);
        $paid = str_contains($s, 'paid') && ! str_contains($s, 'pending') && ! str_contains($s, 'due');
        $m = strtolower($method.' '.$status);
        $payMethod = str_contains($m, 'cash') ? 'cash' : (str_contains($m, 'account') ? 'account' : 'card');

        return [$payMethod, $paid ? 'paid' : 'pending'];
    }

    private function paymentText(string $total, string $status, string $method): string
    {
        $bits = array_filter([$total, $status, $method ? "({$method})" : '']);

        return trim(implode(' ', $bits)) ?: 'Paid';
    }

    /** The exact title emoji: the operator's Notes win; otherwise derive from payment. */
    private function moneyEmoji(string $notes, string $payStatus, string $payMethod, bool $isReturn): string
    {
        if (str_contains($notes, '💰')) {
            return '💰';
        }
        if (str_contains($notes, '👀')) {
            return '👀';
        }
        if ($isReturn || $payStatus === 'paid' || $payMethod === 'account') {
            return '';
        }

        return $payMethod === 'cash' ? '💰' : '👀';
    }

    private function stripEmoji(string $ref): string
    {
        return trim(preg_replace('/[^\x20-\x7E]/u', '', $ref));
    }

    private function cleanNotes(string $notes): string
    {
        // Drop the emoji markers and internal instructions from the visible notes.
        $notes = preg_replace('/[💰👀🚼]/u', '', $notes);
        $notes = preg_replace('/DELETED FROM CALENDAR.*/i', '', $notes);

        return trim($notes);
    }
}
