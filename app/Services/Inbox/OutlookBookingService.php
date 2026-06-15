<?php

namespace App\Services\Inbox;

use App\Models\Booking;
use App\Models\VehicleType;
use App\Services\Ai\AnthropicService;
use App\Services\BookingService;
use App\Services\Pricing\FixedPriceService;

/**
 * Turns Outlook booking emails into bookings. Each unread email is parsed by
 * claude-opus-4-8 into structured fields, then created through BookingService
 * (so confirmations, calendar and rotation all fire as normal). Emails that
 * cannot be parsed into a booking are left unread for a human.
 */
class OutlookBookingService
{
    public function __construct(
        private readonly GraphMailClient $mail,
        private readonly AnthropicService $ai,
        private readonly BookingService $bookings,
        private readonly FixedPriceService $fixedPrices,
    ) {}

    /**
     * @return array{processed:int, created:int, skipped:int}
     */
    public function ingest(): array
    {
        $stats = ['processed' => 0, 'created' => 0, 'skipped' => 0];

        foreach ($this->mail->fetchUnread() as $message) {
            $stats['processed']++;
            $parsed = $this->parse($message['subject'], $message['body'], $message['from']);

            if ($parsed && $this->createFromParsed($parsed)) {
                $stats['created']++;
                $this->mail->markRead($message['id']);
            } else {
                $stats['skipped']++;
            }
        }

        return $stats;
    }

    /**
     * Extract booking fields from an email using the AI. Returns null when the
     * email is not a booking or the AI is unavailable.
     *
     * @return array<string, mixed>|null
     */
    public function parse(string $subject, string $body, ?string $from = null): ?array
    {
        $system = 'You extract chauffeur booking details from emails for Central Executive Transfers. '
            .'Respond ONLY with JSON. If the email is not a booking request, respond {"is_booking": false}. '
            .'Otherwise: {"is_booking": true, "customer_name": string, "customer_phone": string|null, '
            .'"customer_email": string|null, "pickup_address": string, "destination_address": string, '
            .'"pickup_at": "YYYY-MM-DD HH:MM", "passengers": number, "vehicle_type": string|null, '
            .'"flight_number": string|null}. vehicle_type is one of: Executive, Estate, V Class, '
            .'8 Seater, 8 Seater XL, Luxury.';

        $prompt = "From: {$from}\nSubject: {$subject}\n\n{$body}";
        $data = $this->ai->completeJson($prompt, $system, ['max_tokens' => 700]);

        if (! $data || empty($data['is_booking']) || empty($data['pickup_address']) || empty($data['destination_address'])) {
            return null;
        }

        $data['customer_email'] = $data['customer_email'] ?? $from;

        return $data;
    }

    /** @param array<string, mixed> $parsed */
    public function createFromParsed(array $parsed): ?Booking
    {
        $vehicleType = $this->resolveVehicleType($parsed['vehicle_type'] ?? null);
        if (! $vehicleType) {
            return null;
        }

        return $this->bookings->createFromForm([
            'customer_name' => $parsed['customer_name'] ?? 'Email customer',
            'customer_phone' => $parsed['customer_phone'] ?? null,
            'customer_email' => $parsed['customer_email'] ?? null,
            'vehicle_type_id' => $vehicleType->id,
            'journey_type' => 'one_way',
            'pickup_at' => $parsed['pickup_at'],
            'pickup_address' => $parsed['pickup_address'],
            'destination_address' => $parsed['destination_address'],
            'flight_number' => $parsed['flight_number'] ?? null,
            'passengers' => (int) ($parsed['passengers'] ?? 1),
            'payment_method' => 'card',
            'privacy_consent' => '1', // customer initiated the booking by email
            'source' => 'outlook',
        ], null);
    }

    private function resolveVehicleType(?string $label): ?VehicleType
    {
        $slug = ($label ? $this->fixedPrices->slugForColumn($label) : null) ?? 'executive';

        return VehicleType::where('slug', $slug)->first();
    }
}
