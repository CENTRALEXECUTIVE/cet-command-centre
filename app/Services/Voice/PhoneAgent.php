<?php

namespace App\Services\Voice;

use App\Models\PhoneCall;
use App\Models\VehicleType;
use App\Services\Ai\AnthropicService;
use App\Services\Pricing\QuoteService;
use App\Services\Pricing\TripPricer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * The AI phone agent (directors, 23 Nov 2026). Answers the business line, answers
 * questions, quotes from the SAME price engine as the website (QuoteService +
 * TripPricer, never a guessed figure) and takes booking requests. It never creates
 * a booking: a request is saved on the PhoneCall and the office confirms it through
 * Paste a booking. It sends nothing to the caller (no texts). It hands over to a
 * director when asked, on a complaint, or whenever it is unsure.
 *
 * reply() returns ['say' => text to speak, 'action' => continue|transfer|hangup].
 */
class PhoneAgent
{
    public function __construct(
        private readonly AnthropicService $ai,
        private readonly QuoteService $quotes,
        private readonly TripPricer $pricer,
    ) {}

    public function greeting(): string
    {
        $h = (int) now()->format('G');
        $part = $h < 12 ? 'Good morning' : ($h < 18 ? 'Good afternoon' : 'Good evening');
        $name = config('cet.ai_voice.agent_name', 'Sophie');

        return "{$part}, Central Executive Transfers. You're through to {$name}, the virtual assistant. How can I help?";
    }

    /** @return array{say: string, action: string} */
    public function reply(PhoneCall $call): array
    {
        $messages = $this->history($call);
        $action = 'continue';
        $tools = $this->tools();

        for ($i = 0; $i < 4; $i++) {
            $resp = $this->ai->chat($messages, $this->system($call), $tools, array_filter([
                'model' => config('cet.ai_voice.model') ?: null,
                'max_tokens' => 350,
            ]));
            if (! $resp) {
                return $this->fallback($call);
            }
            $blocks = $resp['content'] ?? [];
            $uses = array_values(array_filter($blocks, fn ($b) => ($b['type'] ?? '') === 'tool_use'));
            $text = trim(implode(' ', array_map(fn ($b) => $b['text'] ?? '', array_filter($blocks, fn ($b) => ($b['type'] ?? '') === 'text'))));

            if (! $uses) {
                return ['say' => $text !== '' ? $text : 'Sorry, could you say that again?', 'action' => $action];
            }

            $results = [];
            foreach ($uses as $use) {
                [$result, $act] = $this->runTool($call, (string) $use['name'], (array) ($use['input'] ?? []));
                if ($act !== 'continue') {
                    $action = $act;
                }
                $results[] = ['type' => 'tool_result', 'tool_use_id' => $use['id'], 'content' => json_encode($result)];
            }
            $call->save();
            $messages[] = ['role' => 'assistant', 'content' => $blocks];
            $messages[] = ['role' => 'user', 'content' => $results];

            // A transfer / hang-up with words already spoken: no need for another round.
            if ($action !== 'continue' && $text !== '') {
                return ['say' => $text, 'action' => $action];
            }
        }

        return ['say' => 'Let me pass you to the team.', 'action' => $this->canTransfer() ? 'transfer' : 'continue'];
    }

    /** When the AI can't be reached: never leave a caller stuck. */
    private function fallback(PhoneCall $call): array
    {
        $call->needs_action = true;
        if ($this->canTransfer()) {
            return ['say' => "Sorry, I'm having a little trouble on the line. I'll put you through to the team now.", 'action' => 'transfer'];
        }
        $call->status = 'message';

        return ['say' => "Sorry, I'm having a little trouble on the line. The team will call you back on this number very shortly. Thank you for calling Central Executive Transfers.", 'action' => 'hangup'];
    }

    public function canTransfer(): bool
    {
        return $this->transferNumbers() !== [];
    }

    /** @return list<string> */
    public function transferNumbers(): array
    {
        $cfg = array_values(array_filter(array_map('trim', explode(',', (string) config('cet.ai_voice.transfer', '')))));
        if ($cfg) {
            return $cfg;
        }

        return \App\Models\User::where('role', \App\Enums\UserRole::Admin->value)->where('is_super_admin', true)
            ->where('is_active', true)->whereNotNull('phone')->pluck('phone')
            ->map(fn ($p) => \App\Support\Phone::e164((string) $p))->filter()->values()->all();
    }

    /** Transcript -> alternating Anthropic messages (the greeting is the first agent line). */
    private function history(PhoneCall $call): array
    {
        $out = [['role' => 'user', 'content' => '[The call has connected. You have greeted the caller.]']];
        foreach ($call->transcript ?? [] as $line) {
            $role = $line['role'] === 'caller' ? 'user' : ($line['role'] === 'agent' ? 'assistant' : null);
            if (! $role) {
                continue;
            }
            $last = count($out) - 1;
            if ($out[$last]['role'] === $role) {
                $out[$last]['content'] .= "\n".$line['text'];
            } else {
                $out[] = ['role' => $role, 'content' => $line['text']];
            }
        }
        if (end($out)['role'] !== 'user') {
            $out[] = ['role' => 'user', 'content' => '[silence]'];
        }

        return $out;
    }

    /**
     * The agent's instructions. $platform 'twilio' = our own loop (date and caller known);
     * 'elevenlabs' = the realtime voice platform (static prompt: the agent calls
     * current_time for today's date and receives the caller ID as a tool parameter).
     */
    public function system(?PhoneCall $call = null, string $platform = 'twilio'): string
    {
        $now = now()->timezone(config('app.timezone'));
        $call ??= new PhoneCall;
        $vehicles = VehicleType::where('is_active', true)->orderBy('sort_order')->get()
            ->map(fn ($v) => "- {$v->name} (code {$v->slug}): up to {$v->passenger_capacity} passengers, {$v->luggage_capacity} large cases")
            ->implode("\n");
        $notes = [];
        if ($call->quotes) {
            $notes[] = 'Quotes already given on this call: '.json_encode($call->quotes);
        }
        if ($call->booking_fields) {
            $notes[] = 'Booking request already saved on this call: '.json_encode($call->booking_fields);
        }
        $name = config('cet.ai_voice.agent_name', 'Sophie');
        $transfer = $platform === 'elevenlabs' || $this->canTransfer() ? 'You CAN transfer the call to a director.' : 'Transfer is NOT available right now: take a message instead.';

        return <<<TXT
You are {$name}, the virtual phone assistant for Central Executive Transfers Ltd, an executive chauffeur company based in Sheffield (Operator Licence OP037, licensed by Sheffield City Council). You are speaking on a PHONE CALL. Everything you write is read aloud.

{$this->clockLine($call, $platform, $now)}

HOW YOU SPEAK
- British English, warm and calm, like a good hotel concierge. Short sentences. One question at a time.
- Never use lists, bullet points, emojis, symbols or markdown. Write numbers so they read well aloud ("one hundred and fifteen pounds", times like "half past six in the morning").
- Do not say "Absolutely", "Great question", "I'd be happy to", or repeat the caller's words back at length. No filler.
- Keep each reply under about 40 words unless reading back a booking.

WHAT WE OFFER
- Executive chauffeur transfers from Sheffield, Rotherham, Barnsley, Doncaster and Chesterfield to every UK airport, London, events, weddings and business meetings. Nationwide journeys too.
- Vehicles:
{$vehicles}
- Every flight is tracked. Airport collections include meet and greet: the driver waits in arrivals with a name board.
- Customers get their driver's details and a live tracking link before pickup.
- Chauffeurs are council licensed and enhanced DBS checked. Cars are cleaned before every job and carry water.
- Payment: card (a secure payment link from the office), cash on one-way journeys only (not on returns), or a business account.
- Business accounts: VAT-registered, monthly invoicing on 30-day terms. VAT invoices on request.
- Bookings can also be made online any time at centralexecutivetransfers.co.uk.

PRICES: STRICT RULES
- NEVER say a price you did not get from the get_quote tool on this call. Never estimate, round or guess.
- To quote you need: pickup (full address or postcode), destination, date and time, vehicle, and whether it is a return. Ask for whatever is missing.
- Quote the TOTAL the tool returns. If it says on_request, say the office will confirm the price and offer to take the booking request.
- Prices exclude VAT unless the caller wants a VAT invoice; if they do, call get_quote with vat_invoice true.

TAKING A BOOKING
- Collect: lead passenger name, contact number (confirm the caller ID or take another), email if they have one, pickup address, destination, date and pickup time, flight number for airport pickups, passengers, large cases and hand luggage, vehicle, payment method, return details, any child seats or notes, and who is booking if it is for someone else.
- Spell back names, postcodes and flight numbers. Read the whole booking back once and get a clear yes.
- Then call save_booking_request. Tell the caller: the office will confirm the booking and send the confirmation shortly. NEVER say the booking is confirmed yourself.
- Times are UK local time.

HAND OVER OR TAKE A MESSAGE
- Transfer the call to a director when the caller asks for a person, has a complaint, is asking about an existing booking, a lost item, a driver, money or a refund, or whenever you are not sure. {$transfer}
- If you cannot transfer, use take_message (name, best number, the message) and say the team will call back shortly.
- When the call is finished, say a short goodbye and end the call.

NEVER
- Invent policies, availability, driver names or times. If you do not know, say the office will confirm.
- Promise a specific driver or car registration. Share anyone else's booking or details.
TXT
            .($notes ? "\n\nCALL NOTES\n".implode("\n", $notes) : '');
    }

    private function clockLine(PhoneCall $call, string $platform, Carbon $now): string
    {
        if ($platform === 'elevenlabs') {
            return 'At the start of the call, call current_time to learn today\'s date and the UK time. Always pass the caller ID ({{system__caller_id}}) and conversation ID ({{system__conversation_id}}) to the tools.';
        }

        return "Now: {$now->format('l j F Y, H:i')} UK time. Caller ID: {$call->from_number}.";
    }

    public function tools(): array
    {
        $slugs = VehicleType::where('is_active', true)->pluck('slug')->all() ?: ['executive'];

        return [
            ['name' => 'get_quote', 'description' => 'Exact price from the company price engine (same as the website). Use before saying ANY price.',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'pickup' => ['type' => 'string', 'description' => 'Pickup address or postcode'],
                    'destination' => ['type' => 'string'],
                    'pickup_date_time' => ['type' => 'string', 'description' => 'UK local, YYYY-MM-DD HH:MM'],
                    'vehicle' => ['type' => 'string', 'enum' => $slugs],
                    'return_date_time' => ['type' => 'string', 'description' => 'UK local YYYY-MM-DD HH:MM for the return pickup, if a return'],
                    'child_seats' => ['type' => 'integer'],
                    'vat_invoice' => ['type' => 'boolean'],
                ], 'required' => ['pickup', 'destination', 'pickup_date_time', 'vehicle']]],
            ['name' => 'save_booking_request', 'description' => 'Save the booking request for the office to confirm. Only after reading it back and the caller said yes.',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'lead_name' => ['type' => 'string'], 'contact_no' => ['type' => 'string'], 'email' => ['type' => 'string'],
                    'pickup_at' => ['type' => 'string', 'description' => 'UK local YYYY-MM-DD HH:MM'],
                    'pickup_address' => ['type' => 'string'], 'destination_address' => ['type' => 'string'],
                    'flight_number' => ['type' => 'string'], 'passengers' => ['type' => 'integer'],
                    'suitcases' => ['type' => 'integer'], 'hand_luggage' => ['type' => 'integer'],
                    'vehicle' => ['type' => 'string', 'enum' => $slugs],
                    'payment' => ['type' => 'string', 'enum' => ['card', 'cash', 'account']],
                    'price' => ['type' => 'number', 'description' => 'Total quoted by get_quote, if any'],
                    'return_pickup_at' => ['type' => 'string', 'description' => 'UK local YYYY-MM-DD HH:MM for the return pickup, if a return'],
                    'return_pickup_address' => ['type' => 'string', 'description' => 'Where the return starts, if different from the drop-off'],
                    'return_flight_number' => ['type' => 'string'],
                    'flight_landing_at' => ['type' => 'string', 'description' => 'UK local YYYY-MM-DD HH:MM the flight lands (airport pickups)'],
                    'child_seats' => ['type' => 'integer'],
                    'vat_invoice' => ['type' => 'boolean', 'description' => 'True if they need a VAT invoice for a business'],
                    'company_name' => ['type' => 'string', 'description' => 'For a VAT invoice'],
                    'company_vat_number' => ['type' => 'string', 'description' => 'For a VAT invoice'],
                    'company_address' => ['type' => 'string', 'description' => 'For a VAT invoice'],
                    'booked_by' => ['type' => 'string'],
                    'notes' => ['type' => 'string', 'description' => 'Anything else: meet & greet, special requests'],
                ], 'required' => ['lead_name', 'contact_no', 'pickup_at', 'pickup_address', 'destination_address', 'vehicle']]],
            ['name' => 'take_message', 'description' => 'Take a message for the team to call back.',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'name' => ['type' => 'string'], 'callback_number' => ['type' => 'string'], 'message' => ['type' => 'string'],
                ], 'required' => ['message']]],
            ['name' => 'transfer_to_person', 'description' => 'Put the caller through to a director.',
                'input_schema' => ['type' => 'object', 'properties' => ['reason' => ['type' => 'string']], 'required' => ['reason']]],
            ['name' => 'end_call', 'description' => 'End the call after saying goodbye.',
                'input_schema' => ['type' => 'object', 'properties' => (object) []]],
        ];
    }

    /** @return array{0: array<string, mixed>, 1: string} [result for the model, action] */
    public function runTool(PhoneCall $call, string $name, array $in): array
    {
        try {
            switch ($name) {
                case 'get_quote':
                    $q = $this->quote($in);
                    $call->quotes = array_merge($call->quotes ?? [], [array_merge(array_intersect_key($in, array_flip(['pickup', 'destination', 'pickup_date_time', 'vehicle', 'return_date_time', 'vat_invoice'])), $q)]);

                    return [$q, 'continue'];
                case 'save_booking_request':
                    $call->booking_fields = $in;
                    $call->needs_action = true;

                    return [['saved' => true, 'tell_caller' => 'The office will confirm the booking and send the confirmation shortly.'], 'continue'];
                case 'take_message':
                    $call->message = trim(($in['name'] ?? '').' '.($in['callback_number'] ?? '').': '.($in['message'] ?? ''));
                    $call->needs_action = true;
                    $call->status = 'message';

                    return [['saved' => true], 'continue'];
                case 'transfer_to_person':
                    $call->say('system', 'Transfer: '.($in['reason'] ?? ''));
                    if (! $this->canTransfer()) {
                        return [['transferred' => false, 'do_instead' => 'take_message'], 'continue'];
                    }
                    $call->needs_action = true;

                    return [['transferring' => true, 'say' => 'Tell the caller you are putting them through now.'], 'transfer'];
                case 'end_call':
                    return [['ok' => true], 'hangup'];
            }
        } catch (\Throwable $e) {
            Log::warning('Phone agent tool failed', ['tool' => $name, 'error' => $e->getMessage()]);

            return [['error' => 'That did not work. Offer to take the booking request so the office can confirm.'], 'continue'];
        }

        return [['error' => 'unknown tool'], 'continue'];
    }

    /** Same figures as the website widget (BookingWidgetController::quote). */
    public function quote(array $in): array
    {
        $type = VehicleType::where('slug', $in['vehicle'] ?? 'executive')->first();
        if (! $type) {
            return ['on_request' => true, 'reason' => 'unknown vehicle'];
        }
        $tz = config('app.timezone');
        $at = $this->parseTime($in['pickup_date_time'] ?? null, $tz);
        if (! $at) {
            return ['error' => 'Need the date and time as YYYY-MM-DD HH:MM'];
        }
        $ret = $this->parseTime($in['return_date_time'] ?? null, $tz);
        $q = $this->quotes->quote((string) $in['pickup'], (string) $in['destination'], $type, $at);
        $trip = $this->pricer->price($type, [
            'pickup' => (string) $in['pickup'], 'destination' => (string) $in['destination'],
            'pickup_at' => $at, 'journey_type' => $ret ? 'return' : 'one_way',
            'return_pickup_at' => $ret ?? $at, 'meet_greet' => true,
            'child_seats' => (int) ($in['child_seats'] ?? 0),
            'vat' => (bool) ($in['vat_invoice'] ?? false),
        ]);
        if ($q['price'] === null || $trip['poa'] || ! empty($q['office_quote']) || ! empty($trip['office_quote'])) {
            return ['on_request' => true, 'say' => 'The office will confirm the price for this journey.'];
        }

        return [
            'total_gbp' => round((float) $trip['total'], 2),
            'includes_vat' => (bool) ($in['vat_invoice'] ?? false),
            'return' => (bool) $ret,
            'vehicle' => $type->name,
            'surcharge_note' => $q['surcharge_label'] ?? null,
        ];
    }

    private function parseTime(?string $v, string $tz): ?Carbon
    {
        if (blank($v)) {
            return null;
        }
        try {
            return Carbon::createFromFormat('Y-m-d H:i', trim($v), $tz);
        } catch (\Throwable) {
            return null;
        }
    }
}
