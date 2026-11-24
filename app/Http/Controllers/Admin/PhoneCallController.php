<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PhoneCall;
use App\Models\VehicleType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Calls answered by the AI phone agent. Booking requests are confirmed via Paste a booking. */
class PhoneCallController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->isAdmin(), 403);
        $calls = PhoneCall::latest()->paginate(30);

        return view('admin.phone-calls.index', [
            'calls' => $calls,
            'open' => PhoneCall::where('needs_action', true)->whereNull('reviewed_at')->count(),
        ]);
    }

    public function show(Request $request, PhoneCall $call): View
    {
        abort_unless($request->user()->isAdmin(), 403);

        return view('admin.phone-calls.show', ['call' => $call, 'fields' => $this->intakeFields($call)]);
    }

    /** Everything to paste into the ElevenLabs agent (super-admins: shows the webhook secret). */
    public function setup(Request $request, \App\Services\Voice\PhoneAgent $agent): View
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $secret = (string) config('cet.webhook_secret');
        $schemas = collect($agent->tools())->keyBy('name');
        $tool = fn (string $name, string $route, string $desc) => [
            'name' => $name, 'description' => $desc,
            'url' => route($route, ['secret' => $secret]),
            'params' => collect($schemas[$name]['input_schema']['properties'] ?? [])
                ->map(fn ($p, $k) => ['name' => $k, 'type' => $p['type'] ?? 'string', 'description' => $p['description'] ?? '',
                    'enum' => $p['enum'] ?? null, 'required' => in_array($k, $schemas[$name]['input_schema']['required'] ?? [], true)])
                ->values()->all(),
        ];
        $tools = [
            ['name' => 'current_time', 'description' => "Today's date and the UK time. Call it at the start of the call and before working out any date.",
                'url' => route('webhooks.voice-tools.time', ['secret' => $secret]), 'params' => []],
            $tool('get_quote', 'webhooks.voice-tools.quote', 'Exact price from the company price engine (same as the website). Use before saying ANY price.'),
            $tool('save_booking_request', 'webhooks.voice-tools.booking', 'Save the booking request for the office to confirm. Only after reading it back and the caller said yes.'),
            $tool('take_message', 'webhooks.voice-tools.message', 'Take a message for the team to call back.'),
        ];

        return view('admin.phone-calls.setup', [
            'prompt' => $agent->system(null, 'elevenlabs'),
            'firstMessage' => 'Central Executive Transfers, you\'re through to '.config('cet.ai_voice.agent_name', 'Sophie').', the virtual assistant. How can I help?',
            'tools' => $tools,
            'transfer' => $agent->transferNumbers(),
            'postCall' => route('webhooks.elevenlabs', ['secret' => $secret]),
            'hasSecret' => $secret !== '',
        ]);
    }

    public function reviewed(Request $request, PhoneCall $call): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $call->update(['reviewed_at' => now(), 'needs_action' => false]);

        return redirect()->route('phone-calls.index')->with('status', 'Marked as dealt with.');
    }

    /** The booking request in the shape Paste a booking's preview expects. */
    public function intakeFields(PhoneCall $call): ?array
    {
        $f = $call->booking_fields;
        if (! $f) {
            return null;
        }
        $vehicle = VehicleType::where('slug', $f['vehicle'] ?? '')->value('name') ?? 'Executive';
        $notes = array_filter([
            $f['notes'] ?? null,
            ! empty($f['return_pickup_at']) ? 'Return pickup: '.$f['return_pickup_at'] : null,
            'Taken by the AI phone agent from '.($call->from_number ?: 'unknown number').'.',
        ]);

        return [
            'lead_name' => $f['lead_name'] ?? '', 'contact_no' => $f['contact_no'] ?? '', 'email' => $f['email'] ?? '',
            'pickup_at' => $f['pickup_at'] ?? '', 'pickup_address' => $f['pickup_address'] ?? '',
            'destination_address' => $f['destination_address'] ?? '', 'flight_number' => $f['flight_number'] ?? '',
            'passengers' => (int) ($f['passengers'] ?? 1), 'suitcases' => (int) ($f['suitcases'] ?? 0),
            'hand_luggage' => (int) ($f['hand_luggage'] ?? 0), 'vehicle' => $vehicle,
            'payment' => $f['payment'] ?? 'card', 'price' => $f['price'] ?? '', 'booked_by' => $f['booked_by'] ?? '',
            'notes' => implode(' ', $notes),
        ];
    }
}
