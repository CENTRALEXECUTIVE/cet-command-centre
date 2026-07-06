@extends('layouts.app')
@section('title', 'Booking ' . $booking->reference)

@section('content')
    <h1 class="page-title">
        Booking <span class="mono">{{ $booking->reference }}</span>
        <span class="badge badge-{{ $booking->status->value }}">{{ $booking->status->label() }}</span>
    </h1>
    <p class="page-sub">Created {{ $booking->created_at->format('D d M Y, H:i') }}
        @if($booking->createdBy) by {{ $booking->createdBy->name }} @endif</p>

    @if(session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @if(auth()->user()->isAdmin() && ! $booking->status->isTerminal())
        <div class="toolbar" style="margin-bottom:16px">
            <a href="{{ route('bookings.edit', $booking) }}" class="btn btn-primary" style="padding:9px 16px">✏️ Edit booking</a>
            <button type="button" class="btn btn-ghost" style="padding:9px 16px;color:#b32020" onclick="document.getElementById('cancel-box').style.display='block';this.style.display='none'">✕ Cancel booking</button>
        </div>
        <div id="cancel-box" class="card" style="display:none;border-left:4px solid #b32020;background:rgba(179,32,32,.05);margin-bottom:16px">
            <form method="POST" action="{{ route('bookings.cancel', $booking) }}">
                @csrf
                <label for="cancellation_reason" style="font-weight:600">Reason for cancellation <span class="req">*</span></label>
                <input id="cancellation_reason" name="cancellation_reason" required placeholder="e.g. Customer cancelled — no charge" style="margin:6px 0 10px">
                <button type="submit" class="btn" style="background:#b32020;color:#fff;padding:9px 16px">Confirm cancellation</button>
                <button type="button" class="btn btn-ghost" style="padding:9px 16px" onclick="document.getElementById('cancel-box').style.display='none'">Keep booking</button>
                <p class="hint" style="margin:8px 0 0">The calendar event isn't removed automatically — take it off Google Calendar yourself if it was pushed there.</p>
            </form>
        </div>
    @endif

    @if($booking->status === \App\Enums\BookingStatus::Cancelled && ! empty($booking->meta['cancellation_reason']))
        <div class="alert alert-error">Cancelled — {{ $booking->meta['cancellation_reason'] }}
            @if(!empty($booking->meta['cancelled_at'])) <span class="muted">({{ \Illuminate\Support\Carbon::parse($booking->meta['cancelled_at'])->format('d M Y, H:i') }})</span>@endif
        </div>
    @endif

    <div class="grid grid-2">
        <div class="card">
            <h2>Journey</h2>
            <table>
                <tr><th>Pickup</th><td>{{ $booking->pickup_at->format('D d M Y, H:i') }}</td></tr>
                <tr><th>From</th><td>{{ $booking->pickup_address }}</td></tr>
                @foreach($booking->stops as $stop)
                    <tr><th>Via {{ $stop->sequence }}</th><td>{{ $stop->address }}</td></tr>
                @endforeach
                <tr><th>To</th><td>{{ $booking->destination_address }}</td></tr>
                @if($booking->airport)<tr><th>Airport</th><td>{{ $booking->airport->code }} — {{ $booking->airport->name }}</td></tr>@endif
                @if($booking->flight_number)
                    <tr><th>Flight</th><td>
                        <span class="mono">{{ $booking->flight_number }}</span>
                        <a href="{{ $booking->flightRadarUrl() }}" data-flightradar target="_blank" rel="noopener" class="btn" style="background:#fc3d02;color:#fff;padding:3px 10px;font-size:12px;margin-left:8px">✈ Flightradar24</a>
                        <a href="{{ $booking->flightSearchUrl() }}" target="_blank" rel="noopener" class="btn btn-ghost" style="padding:3px 10px;font-size:12px">Live status</a>
                    </td></tr>
                @endif
                <tr><th>Passengers</th><td>{{ $booking->passengers }} &middot; {{ $booking->luggage }} bags</td></tr>
                <tr><th>Type</th><td>{{ ucfirst(str_replace('_',' ',$booking->journey_type)) }}{{ $booking->is_return_leg ? ' (return leg)' : '' }}</td></tr>
                @if($booking->special_requests)<tr><th>Notes</th><td>{{ $booking->special_requests }}</td></tr>@endif
            </table>
        </div>

        <div class="card">
            <h2>Service &amp; Payment</h2>
            <table>
                <tr><th>Customer</th><td>@if($booking->customer && auth()->user()->isAdmin())<a href="{{ route('customers.show', $booking->customer) }}">{{ $booking->customer->name }}</a>@else{{ $booking->customer?->name }}@endif</td></tr>
                <tr><th>Vehicle</th><td>{{ $booking->vehicleType?->name }}</td></tr>
                <tr><th>Driver</th><td>{{ $booking->driver?->name ?? 'Awaiting allocation' }}</td></tr>
                @if($booking->corporateAccount)
                    <tr><th>Account</th><td>{{ $booking->corporateAccount->name }}</td></tr>
                    @if($booking->cost_code)<tr><th>Cost code</th><td>{{ $booking->cost_code }}</td></tr>@endif
                @endif
                <tr><th>Payment</th><td>
                    {{ $booking->payment_method->emoji() }} {{ $booking->payment_method->label() }}
                    @if($booking->payment_status === 'paid')
                        <span class="badge badge-complete">Paid</span>
                    @else
                        <span class="badge badge-pending">{{ ucfirst($booking->payment_status ?? 'pending') }}</span>
                    @endif
                </td></tr>
                @if($booking->quoted_price)<tr><th>Quoted</th><td>£{{ number_format($booking->quoted_price, 2) }}</td></tr>@endif
                @if($booking->final_price)<tr><th>Final</th><td>£{{ number_format($booking->final_price, 2) }}</td></tr>@endif
            </table>
            @if(auth()->user()->isAdmin() && $booking->payment_status !== 'paid' && ! $booking->status->isTerminal())
                <form method="POST" action="{{ route('payments.paid', $booking) }}" style="margin-top:8px">@csrf
                    <button class="btn btn-primary" style="padding:6px 14px;font-size:13px">Mark paid</button>
                </form>
            @endif
        </div>
    </div>

    @if($booking->calendarEvent)
        <div class="card">
            <h2>Calendar Event</h2>
            <table>
                <tr><th>Title</th><td class="mono">{{ $booking->calendarEvent->title }}</td></tr>
                <tr><th>Location</th><td>{{ $booking->calendarEvent->location }}</td></tr>
                <tr><th>Start → End</th><td>{{ $booking->calendarEvent->start_at->format('H:i') }} → {{ $booking->calendarEvent->end_at->format('H:i') }}</td></tr>
                <tr><th>Sync</th><td>{{ ucfirst($booking->calendarEvent->sync_status) }}</td></tr>
            </table>
        </div>
    @endif

    @if($booking->payments->isNotEmpty())
        <div class="card">
            <h2>Payment</h2>
            <table>
                @foreach($booking->payments as $payment)
                    <tr>
                        <th>{{ ucfirst($payment->method) }}</th>
                        <td>
                            £{{ number_format($payment->amount, 2) }} ·
                            <span class="badge badge-{{ $payment->status === 'paid' ? 'complete' : 'pending' }}">{{ ucfirst(str_replace('_',' ',$payment->status)) }}</span>
                            @if($payment->tide_payment_link)
                                · <a href="{{ $payment->tide_payment_link }}" target="_blank" rel="noopener">Tide payment link</a>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </table>
        </div>
    @endif

    @if($booking->statusHistory->isNotEmpty())
        <div class="card">
            <h2>Status History</h2>
            <table>
                <thead><tr><th>When</th><th>Change</th><th>By</th><th>GPS</th></tr></thead>
                <tbody>
                    @foreach($booking->statusHistory->sortByDesc('created_at') as $h)
                        <tr>
                            <td>{{ $h->created_at?->format('d M H:i:s') }}</td>
                            <td>{{ $h->from_status ?? '—' }} → <strong>{{ $h->to_status }}</strong></td>
                            <td>{{ $h->changedBy?->name ?? 'System' }}</td>
                            <td class="mono">{{ $h->gps_latitude ? $h->gps_latitude.', '.$h->gps_longitude : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if(auth()->user()->isAdmin())
        @php
            $chan = ['whatsapp' => '🟢 WhatsApp', 'sms' => '💬 SMS', 'email' => '✉️ Email'];
            $mstatus = ['sent' => 'complete', 'queued' => 'pending', 'failed' => 'cancelled'];
        @endphp
        <div class="card">
            <h2>Customer Comms</h2>
            <p class="hint" style="margin-top:-8px">The button opens WhatsApp with the number <em>and</em> message ready — hit send, then mark it done. <strong>Send from WhatsApp Business</strong> (use a device signed into the business number, so it goes from the right account).</p>

            @php
                $md = $booking->meta['driver_details'] ?? null;
                $hasDriver = (is_array($md) && !empty($md['name'])) || $booking->driver;
            @endphp
            <details {{ $hasDriver ? '' : 'open' }} style="border:1px solid {{ $hasDriver ? 'var(--line)' : '#FBBA2A' }};border-radius:10px;padding:10px 14px;margin-bottom:14px;background:{{ $hasDriver ? 'transparent' : 'rgba(251,186,42,.06)' }}">
                <summary style="cursor:pointer;font-weight:700;font-size:14px">🚗 Driver for this job
                    @if($hasDriver)<span class="muted" style="font-weight:400">— {{ is_array($md) && !empty($md['name']) ? $md['name'] : $booking->driver?->name }} (tap to change)</span>
                    @else<span class="badge badge-pending" style="margin-left:6px">add before sending</span>@endif
                </summary>
                <form method="POST" action="{{ route('bookings.driver-details', $booking) }}" style="margin-top:12px">
                    @csrf
                    <div class="field" style="margin-bottom:10px">
                        <label for="driver-pick">Pick a saved driver <span class="muted">(optional — prefills the boxes)</span></label>
                        <select id="driver-pick">
                            <option value="">— Type a driver below, or pick one —</option>
                            @foreach($jobDrivers as $d)
                                <option value="{{ $loop->index }}"
                                    data-name="{{ $d['name'] }}" data-phone="{{ $d['phone'] }}"
                                    data-reg="{{ $d['reg'] }}" data-car="{{ $d['car'] }}">{{ $d['name'] }}@if($d['reg']) · {{ $d['reg'] }}@endif</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="grid grid-2">
                        <div class="field"><label for="d-name">Driver name <span class="req">*</span></label><input id="d-name" name="name" value="{{ old('name', $md['name'] ?? '') }}" required></div>
                        <div class="field"><label for="d-phone">Contact number</label><input id="d-phone" name="phone" value="{{ old('phone', $md['phone'] ?? '') }}" placeholder="07…"></div>
                        <div class="field"><label for="d-reg">Vehicle reg</label><input id="d-reg" name="reg" value="{{ old('reg', $md['reg'] ?? '') }}" style="text-transform:uppercase"></div>
                        <div class="field"><label for="d-car">Make &amp; model</label><input id="d-car" name="car" value="{{ old('car', $md['car'] ?? '') }}" placeholder="e.g. Black Mercedes V Class"></div>
                    </div>
                    <button class="btn btn-primary" style="padding:7px 16px;font-size:13px">Save driver details</button>
                </form>
            </details>

            @forelse($messages as $m)
                <div style="padding:10px 0;border-bottom:1px solid rgba(128,128,128,.12)">
                    <div style="display:flex;justify-content:space-between;gap:10px;align-items:baseline">
                        <strong style="font-size:13px">{{ ucfirst(str_replace('_',' ',$m->type)) }}</strong>
                        <span class="muted" style="font-size:12px">
                            <span class="badge badge-{{ $mstatus[$m->status] ?? 'pending' }}">{{ $m->status === 'queued' ? 'To send' : ucfirst($m->status) }}</span>
                            @if($m->isReminder() && $m->scheduled_for && $m->status !== 'sent') · due {{ $m->scheduled_for->format('D d M, H:i') }}
                            @elseif($m->sent_at) · sent {{ $m->sent_at->format('d M H:i') }}@endif
                        </span>
                    </div>
                    <div class="msg-body" style="font-size:13px;color:#444;white-space:pre-line;margin-top:4px">{{ $m->body }}</div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">
                        @if($m->whatsAppLink())
                            <a href="{{ $m->whatsAppLink() }}" target="_blank" rel="noopener" class="btn" style="background:#25D366;color:#fff;padding:5px 12px;font-size:12px">📲 Send on WhatsApp</a>
                        @endif
                        <button type="button" class="btn btn-ghost copy-msg" style="padding:5px 12px;font-size:12px">⧉ Copy</button>
                        @if($m->status !== 'sent')
                            <form method="POST" action="{{ route('messages.sent', $m) }}">@csrf
                                <button class="btn btn-ghost" style="padding:5px 12px;font-size:12px">✓ Mark sent</button>
                            </form>
                        @endif
                    </div>
                </div>
            @empty
                <p class="muted">No messages yet for this booking.</p>
            @endforelse

            <form method="POST" action="{{ route('bookings.message', $booking) }}" style="margin-top:14px">
                @csrf
                <label for="body" style="font-weight:600">Send a message to {{ $booking->customer?->name ?? 'the customer' }}</label>
                <textarea id="body" name="body" required placeholder="Type a message…" style="margin:6px 0 8px;min-height:70px">{{ old('body') }}</textarea>
                <button type="submit" class="btn btn-dark" style="padding:8px 16px">Send</button>
                <span class="hint">Goes to {{ $booking->customer?->phone ?? $booking->customer?->email ?? 'no contact on file' }}.</span>
            </form>
        </div>
    @endif

    @if($auditLogs->isNotEmpty())
        <div class="card">
            <h2>Audit Trail</h2>
            <table>
                <thead><tr><th>When</th><th>Action</th><th>By</th><th>Changed</th></tr></thead>
                <tbody>
                    @foreach($auditLogs as $log)
                        <tr>
                            <td>{{ $log->created_at?->format('d M H:i:s') }}</td>
                            <td>{{ ucfirst($log->action) }}</td>
                            <td>{{ $log->user?->name ?? 'System' }}</td>
                            <td class="mono" style="font-size:11px">{{ $log->new_values ? implode(', ', array_keys($log->new_values)) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <a href="{{ route('bookings.index') }}" class="btn btn-ghost">← Back to bookings</a>

    @verbatim
    <script>
        // Prefill the driver boxes when a saved driver is picked.
        (function () {
            var pick = document.getElementById('driver-pick');
            if (!pick) return;
            pick.addEventListener('change', function () {
                var o = pick.options[pick.selectedIndex];
                if (!o || !o.value) return;
                document.getElementById('d-name').value = o.getAttribute('data-name') || '';
                document.getElementById('d-phone').value = o.getAttribute('data-phone') || '';
                document.getElementById('d-reg').value = o.getAttribute('data-reg') || '';
                document.getElementById('d-car').value = o.getAttribute('data-car') || '';
            });
        })();
        document.querySelectorAll('.copy-msg').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var body = btn.closest('div').parentNode.querySelector('.msg-body');
                var text = body ? body.textContent : '';
                navigator.clipboard.writeText(text).then(function () {
                    var old = btn.textContent; btn.textContent = '✓ Copied';
                    setTimeout(function () { btn.textContent = old; }, 1500);
                });
            });
        });
    </script>
    @endverbatim
    <script src="{{ asset('js/cet-flight.js') }}"></script>
@endsection
