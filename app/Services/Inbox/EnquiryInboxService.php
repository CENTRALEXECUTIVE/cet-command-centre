<?php

namespace App\Services\Inbox;

use App\Models\Customer;
use App\Models\EmailEnquiry;
use App\Models\VehicleType;
use App\Services\Ai\AnthropicService;
use App\Services\Pricing\QuoteService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Turns inbound customer enquiry emails into reviewable quotes: Claude extracts
 * the journey, QuoteService prices it, and a branded reply is drafted for the
 * operator to check and send. ETO booking-confirmation emails are left to the
 * OutlookBookingService — this only handles free-form customer enquiries.
 */
class EnquiryInboxService
{
    public function __construct(
        private readonly GraphMailClient $mail,
        private readonly AnthropicService $ai,
        private readonly QuoteService $quotes,
    ) {}

    /**
     * @return array{processed:int, created:int, skipped:int}
     */
    public function ingest(int $days = 14): array
    {
        $stats = ['processed' => 0, 'created' => 0, 'skipped' => 0];

        foreach ($this->mail->fetchRecent($days) as $message) {
            $stats['processed']++;

            if (blank($message['id']) || EmailEnquiry::where('graph_message_id', $message['id'])->exists()) {
                $stats['skipped']++;
                continue; // already captured
            }
            if ($this->looksLikeEtoBooking($message)) {
                $stats['skipped']++;
                continue; // handled by the booking pipeline
            }

            $extracted = $this->extract($message['subject'] ?? '', $message['body'] ?? '');
            if (! $extracted || empty($extracted['is_enquiry'])) {
                $stats['skipped']++;
                continue; // not a transfer enquiry
            }

            $this->createEnquiry($message, $extracted);
            $stats['created']++;
        }

        return $stats;
    }

    private function looksLikeEtoBooking(array $message): bool
    {
        $haystack = Str::lower(($message['subject'] ?? '').' '.($message['from'] ?? '').' '.($message['body'] ?? ''));

        return str_contains($haystack, 'booking reference')
            || str_contains($haystack, 'easytaxioffice')
            || str_contains($haystack, 'booking confirmation');
    }

    /**
     * Ask Claude to pull the journey out of a free-form email. Returns null when
     * AI is unavailable (the feature simply stays quiet until a key is set).
     *
     * @return array<string, mixed>|null
     */
    private function extract(string $subject, string $body): ?array
    {
        $system = 'You extract private-hire transfer enquiries from emails for a UK chauffeur firm. '
            .'Reply with ONLY a JSON object, no prose. Fields: is_enquiry (true only if the sender is '
            .'asking for a quote or to book a transfer), customer_name, pickup, destination, pickup_datetime '
            .'(ISO 8601 if a date/time is given, else null), passengers (integer or null), '
            .'vehicle (one of: executive, estate, minibus-8, v-class, or null), notes.';

        $prompt = "Subject: {$subject}\n\nEmail:\n".Str::limit($body, 4000);

        return $this->ai->completeJson($prompt, $system, ['max_tokens' => 700]);
    }

    private function createEnquiry(array $message, array $extracted): EmailEnquiry
    {
        $vehicleType = $this->resolveVehicleType($extracted['vehicle'] ?? null);
        [$amount, $basis] = $this->priceFor($extracted, $vehicleType);

        $name = $extracted['customer_name'] ?? $message['from_name'] ?? null;
        $customer = $this->matchCustomer($message['from'] ?? null);

        $enquiry = EmailEnquiry::create([
            'graph_message_id' => $message['id'],
            'from_email' => $message['from'] ?? null,
            'from_name' => $name,
            'subject' => $message['subject'] ?? null,
            'body' => $message['body'] ?? null,
            'extracted' => $extracted,
            'quote_amount' => $amount,
            'quote_basis' => $basis,
            'customer_id' => $customer?->id,
            'status' => $amount !== null ? 'quoted' : 'new',
        ]);

        $enquiry->update(['draft_reply' => $this->draftReply($enquiry, $vehicleType)]);

        return $enquiry;
    }

    /** @return array{0: ?float, 1: ?string} */
    private function priceFor(array $extracted, VehicleType $vehicleType): array
    {
        $pickup = trim((string) ($extracted['pickup'] ?? ''));
        $destination = trim((string) ($extracted['destination'] ?? ''));
        if ($pickup === '' || $destination === '') {
            return [null, 'Awaiting journey details'];
        }

        try {
            $quote = $this->quotes->quote($pickup, $destination, $vehicleType);

            return [$quote['price'], $quote['basis']];
        } catch (\Throwable) {
            return [null, 'Price on request'];
        }
    }

    private function draftReply(EmailEnquiry $enquiry, VehicleType $vehicleType): string
    {
        $ex = $enquiry->extracted ?? [];
        $first = Str::before((string) ($enquiry->from_name ?: 'there'), ' ') ?: 'there';
        $when = ! empty($ex['pickup_datetime'])
            ? optional($this->tryDate($ex['pickup_datetime']))->format('D d M Y, H:i')
            : null;

        $lines = [
            "Dear {$first},",
            '',
            'Thank you for your enquiry — we would be delighted to look after your transfer.',
            '',
        ];
        if (! empty($ex['pickup'])) {
            $lines[] = 'From: '.$ex['pickup'];
        }
        if (! empty($ex['destination'])) {
            $lines[] = 'To: '.$ex['destination'];
        }
        if ($when) {
            $lines[] = 'Date & time: '.$when;
        }
        if (! empty($ex['passengers'])) {
            $lines[] = 'Passengers: '.$ex['passengers'];
        }
        $lines[] = 'Vehicle: '.$vehicleType->name;
        $lines[] = '';

        if ($enquiry->quote_amount !== null) {
            $lines[] = 'The all-inclusive fare for this journey is £'.number_format((float) $enquiry->quote_amount, 2).'.';
        } else {
            $lines[] = 'We will confirm the exact fare for this journey shortly.';
        }
        $lines[] = '';
        $lines[] = 'To confirm, simply reply to this email and we will take care of the rest.';
        $lines[] = '';
        $lines[] = 'Kind regards,';
        $lines[] = 'Central Executive Transfers';
        $lines[] = config('cet.company.website', 'centralexecutivetransfers.co.uk');

        return implode("\n", $lines);
    }

    private function resolveVehicleType(?string $slug): VehicleType
    {
        $slug = trim((string) $slug);
        if ($slug !== '') {
            $match = VehicleType::where('slug', $slug)->orWhere('name', 'like', "%{$slug}%")->first();
            if ($match) {
                return $match;
            }
        }

        return VehicleType::where('slug', 'executive')->first() ?? VehicleType::orderBy('id')->firstOrFail();
    }

    private function matchCustomer(?string $email): ?Customer
    {
        return $email ? Customer::where('email', $email)->first() : null;
    }

    private function tryDate(?string $value): ?Carbon
    {
        try {
            return $value ? Carbon::parse($value) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
