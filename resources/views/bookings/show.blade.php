@extends('layouts.app')
@section('title', 'Booking ' . $booking->reference)

@section('content')
    {{-- Hero: the four things the office needs at a glance — when, who,
         where-code, and state — in the brand black/gold. --}}
    <div class="booking-hero">
        <div class="bh-top">
            <div>
                <div class="bh-when">{{ $booking->pickup_at->format('D d M Y') }} · <span class="gold">{{ $booking->pickup_at->format('H:i') }}</span></div>
                <div class="bh-who">{{ $booking->displayCustomerName() ?? 'Customer' }}
                    <span class="badge badge-{{ $booking->status->value }}" id="hero-status">{{ $booking->status->label() }}</span>
                </div>
            </div>
            <div class="bh-refs">
                <div class="mono">{{ $booking->reference }}</div>
                @if($booking->external_reference)<div class="mono gold">ETO {{ $booking->external_reference }}</div>@endif
            </div>
        </div>
        <div class="bh-chips">
            @if($booking->airport)<span class="bh-chip">✈ {{ $booking->airport->code }}</span>@endif
            @if($booking->displayVehicleType())<span class="bh-chip">🚘 {{ $booking->displayVehicleType() }}</span>@endif
            <span class="bh-chip">👤 {{ $booking->driver?->name ?? ($booking->meta['driver_details']['name'] ?? 'No driver yet') }}</span>
            <span class="bh-chip">{{ $booking->passengerCount() }} pax · {{ $booking->luggageShort() }}</span>
            @if($booking->displayPayment())
                <span class="bh-chip {{ str_contains(strtolower($booking->displayPayment()), 'paid') ? 'ok' : '' }}">💳 {{ $booking->displayPayment() }}</span>
            @elseif($booking->payment_status === 'paid')
                <span class="bh-chip ok">💳 Paid</span>
            @else
                <span class="bh-chip warn">💳 {{ ucfirst($booking->payment_status ?? 'pending') }}</span>
            @endif
            @if($booking->displayFlightNumber())<span class="bh-chip">🛬 {{ $booking->displayFlightNumber() }}</span>@endif
        </div>
        <div class="bh-meta">Created {{ $booking->created_at->format('D d M Y, H:i') }}@if($booking->createdBy) by {{ $booking->createdBy->name }}@endif</div>
    </div>

    @if(session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @if(auth()->user()->isAdmin() && !empty($booking->meta['audit_issues']))
        <div class="card" style="border-left:4px solid #b8860b;background:rgba(184,134,11,.08);margin-bottom:16px">
            <strong>⚠ Flagged by the ETO audit{{ !empty($booking->meta['audited_at']) ? ' · '.\Illuminate\Support\Carbon::parse($booking->meta['audited_at'])->format('D d M, H:i') : '' }}</strong>
            <ul style="margin:6px 0 0;padding-left:18px">
                @foreach($booking->meta['audit_issues'] as $issue)<li>{{ $issue }}</li>@endforeach
            </ul>
        </div>
    @endif

    @if(auth()->user()->isAdmin() && session('scanChanges'))
        <div class="card" style="border-left:4px solid #1f7a44;background:rgba(31,122,68,.07);margin-bottom:16px">
            <strong>Corrected to match the live calendar:</strong>
            <table style="margin-top:6px;max-width:640px">
                @foreach(session('scanChanges') as $field => $c)
                    <tr><th style="white-space:nowrap">{{ $field }}</th>
                        <td><span class="muted" style="text-decoration:line-through">{{ $c['from'] ?? '—' }}</span> → <strong>{{ $c['to'] }}</strong></td></tr>
                @endforeach
            </table>
        </div>
    @endif

    @if(auth()->user()->isAdmin() && ! $booking->status->isTerminal())
        <div class="toolbar" style="margin-bottom:16px">
            @if(!empty($canScan))
                <form method="POST" action="{{ route('bookings.scan-calendar', $booking) }}">
                    @csrf
                    <button class="btn btn-dark" style="padding:9px 16px">🔍 Scan calendar</button>
                </form>
            @endif
            <a href="{{ route('bookings.edit', $booking) }}" class="btn btn-primary" style="padding:9px 16px">✏️ Edit booking</a>
            <button type="button" class="btn btn-ghost" style="padding:9px 16px;color:#b32020" onclick="document.getElementById('cancel-box').style.display='block';this.style.display='none'">✕ Cancel booking</button>
        </div>
        @if(!empty($canScan))
            <p class="hint" style="margin:-8px 0 16px">
                <strong>Scan calendar</strong> finds this booking on your live Google Calendar (by its reference), then makes it match exactly — time, addresses, and the full details block. Never changes the calendar.
                @if(!empty($booking->meta['calendar_scanned_at']))
                    · Last scanned {{ \Illuminate\Support\Carbon::parse($booking->meta['calendar_scanned_at'])->format('D d M, H:i') }}
                @endif
            </p>
        @endif
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

    @if(auth()->user()->isAdmin() && !empty($booking->meta['calendar_unverified']))
        <div class="alert alert-error" style="margin-bottom:16px">
            ⚠ <strong>Not verified against the live calendar.</strong> The time and details below are from our stored copy and <strong>may be out of date</strong> if the event was changed on Google Calendar. Tap <strong>🔍 Scan calendar</strong> above to pull the live version.
        </div>
    @endif

    @if(auth()->user()->isAdmin())
        {{-- One-tap status control for the office. Reaches ANY stage directly
             (Arrived / On Board / Completed …) and can wind a job back if a
             driver tapped the wrong button. Same admin-override route the
             dispatch board uses (logged against the actor). --}}
        @php
            $statusButtons = [
                ['pending',   '⏳', 'Pending'],
                ['allocated', '🧭', 'Allocated'],
                ['accepted',  '✅', 'Accepted'],
                ['en_route',  '🚗', 'En Route'],
                ['arrived',   '📍', 'Arrived'],
                ['collected', '🧳', 'On Board (POB)'],
                ['complete',  '🏁', 'Completed'],
                ['no_show',   '🚫', 'No Show'],
            ];
        @endphp
        <div class="card" style="margin-bottom:16px">
            <h2 style="margin-top:0">Update status</h2>
            <p class="hint" style="margin-top:-6px">Set this job to any stage in one tap. Logged against your name — you can also wind it back if a driver tapped the wrong button. Marking <strong>On Board</strong> winds the masked number down (closes ~30 min later).</p>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                @foreach($statusButtons as [$value, $icon, $label])
                    @if($booking->status->value === $value)
                        <span class="btn" style="padding:8px 14px;font-size:13px;background:#111;color:#FBBA2A;cursor:default" aria-current="true">{{ $icon }} {{ $label }} · now</span>
                    @else
                        <form method="POST" action="{{ route('despatch.quick-status', $booking) }}" onsubmit="return confirm('Set {{ $booking->reference }} to {{ $label }}?')">
                            @csrf
                            <input type="hidden" name="status" value="{{ $value }}">
                            <button class="btn btn-ghost" style="padding:8px 14px;font-size:13px">{{ $icon }} {{ $label }}</button>
                        </form>
                    @endif
                @endforeach
            </div>
            <p class="hint" style="margin:10px 0 0">To cancel, use <strong>Cancel booking</strong> above — it records a reason and doesn't touch the calendar.</p>
        </div>
    @endif

    @if($booking->status === \App\Enums\BookingStatus::Cancelled && ! empty($booking->meta['cancellation_reason']))
        <div class="alert alert-error">Cancelled — {{ $booking->meta['cancellation_reason'] }}
            @if(!empty($booking->meta['cancelled_at'])) <span class="muted">({{ \Illuminate\Support\Carbon::parse($booking->meta['cancelled_at'])->format('d M Y, H:i') }})</span>@endif
        </div>
    @endif

    {{-- The calendar's own words, front and centre — exactly what's on the
         event, like a screenshot of its description. --}}
    @if($booking->calendarEvent && filled($booking->calendarEvent->description))
        <div class="card cal-panel">
            <div class="cal-panel-head">
                <span>📅 Full details (from the calendar)</span>
                <span class="muted" style="font-size:12px">{{ $booking->calendarEvent->start_at->format('D d M') }} · {{ $booking->calendarEvent->start_at->format('H:i') }} → {{ $booking->calendarEvent->end_at->format('H:i') }}</span>
            </div>
            <div class="cal-panel-title mono">{{ $booking->calendarEvent->title }}</div>
            <div class="cal-panel-body">{{ str_replace('*', '', $booking->calendarEvent->description) }}</div>
            @if(auth()->user()->isAdmin())
                <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:10px">
                    @if($booking->calendarEvent->google_event_id)
                        <form method="POST" action="{{ route('bookings.sync-time', $booking) }}"
                              onsubmit="return confirm('Set this booking\'s pickup time to whatever is on the Google Calendar right now? The calendar itself is not changed.')">
                            @csrf
                            <button class="btn btn-ghost" style="padding:6px 14px;font-size:13px">🗓 Match time to calendar</button>
                        </form>
                    @endif
                    <details>
                        <summary class="muted" style="font-size:12px;cursor:pointer">Event details</summary>
                        <table style="margin-top:6px">
                            <tr><th>Location</th><td>{{ $booking->calendarEvent->location }}</td></tr>
                            <tr><th>Sync</th><td>{{ ucfirst($booking->calendarEvent->sync_status) }}</td></tr>
                        </table>
                    </details>
                </div>
            @endif
        </div>
    @endif

    <div class="grid grid-2">
        <div class="card">
            <h2>Journey</h2>
            <table>
                <tr><th>Pickup</th><td>{{ $booking->pickup_at->format('D d M Y, H:i') }}</td></tr>
                <tr><th>From</th><td>{{ $booking->displayPickupAddress() }}</td></tr>
                @foreach($booking->stops as $stop)
                    <tr><th>Via {{ $stop->sequence }}</th><td>{{ $stop->address }}</td></tr>
                @endforeach
                <tr><th>To</th><td>{{ $booking->displayDropoffAddress() }}</td></tr>
                @if($booking->airport)<tr><th>Airport</th><td>{{ $booking->airport->code }} — {{ $booking->airport->name }}</td></tr>@endif
                @if($booking->displayFlightNumber())
                    <tr><th>Flight</th><td>
                        <span class="mono">{{ $booking->displayFlightNumber() }}</span>
                        <a href="{{ $booking->flightRadarUrl() }}" data-flightradar target="_blank" rel="noopener" class="btn" style="background:#fc3d02;color:#fff;padding:3px 10px;font-size:12px;margin-left:8px">✈ Flightradar24</a>
                        <a href="{{ $booking->flightSearchUrl() }}" target="_blank" rel="noopener" class="btn btn-ghost" style="padding:3px 10px;font-size:12px">Live status</a>
                    </td></tr>
                @endif
                @if($booking->displayMeetAndGreet())<tr><th>Meet &amp; Greet</th><td>{{ $booking->displayMeetAndGreet() }}</td></tr>@endif
                <tr><th>Passengers</th><td>{{ $booking->passengerCount() }}</td></tr>
                <tr><th>Luggage</th><td>{{ $booking->luggageBreakdown() }}</td></tr>
                <tr><th>Type</th><td>{{ ucfirst(str_replace('_',' ',$booking->journey_type)) }}{{ $booking->is_return_leg ? ' (return leg)' : '' }}</td></tr>
                @if($booking->special_requests)<tr><th>Notes</th><td>{{ $booking->special_requests }}</td></tr>@endif
            </table>
        </div>

        <div class="card">
            <h2>Service &amp; Payment</h2>
            <table>
                <tr><th>Customer</th><td>@if($booking->customer && auth()->user()->isAdmin())<a href="{{ route('customers.show', $booking->customer) }}">{{ $booking->displayCustomerName() }}</a>@else{{ $booking->displayCustomerName() }}@endif</td></tr>
                @if($booking->displayContact())<tr><th>Contact</th><td>{{ $booking->displayContact() }}</td></tr>@endif
                <tr><th>Vehicle</th><td>{{ $booking->displayVehicleType() }}</td></tr>
                <tr><th>Driver</th><td>{{ $booking->driver?->name ?? 'Awaiting allocation' }}</td></tr>
                @if($booking->corporateAccount)
                    <tr><th>Account</th><td>{{ $booking->corporateAccount->name }}</td></tr>
                    @if($booking->cost_code)<tr><th>Cost code</th><td>{{ $booking->cost_code }}</td></tr>@endif
                @endif
                <tr><th>Payment</th><td>
                    @if($booking->displayPayment())
                        {{ $booking->displayPayment() }}
                    @else
                        {{ $booking->payment_method->emoji() }} {{ $booking->payment_method->label() }}
                        @if($booking->payment_status === 'paid')
                            <span class="badge badge-complete">Paid</span>
                        @else
                            <span class="badge badge-pending">{{ ucfirst($booking->payment_status ?? 'pending') }}</span>
                        @endif
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

    @if(auth()->user()->isAdmin())
        @php $pay = $booking->driverPay(); $paid = $booking->driverPaidAmount(); $left = $booking->driverPayRemaining(); @endphp
        <div class="card" @if($left !== null && $left > 0) style="border-left:4px solid #FBBA2A" @endif>
            <h2>Driver payroll — {{ $booking->payrollDriverName() }}</h2>
            @if($pay === null)
                <p class="muted" style="margin:0 0 10px">No driver pay set for this job yet.</p>
            @else
                <table style="max-width:420px">
                    <tr><th>Job pays</th><td>£{{ number_format($pay, 2) }}</td></tr>
                    <tr><th>Paid so far</th><td>£{{ number_format($paid, 2) }}</td></tr>
                    <tr><th>Remaining</th><td>
                        @if($left > 0)
                            <strong style="color:#b8860b">£{{ number_format($left, 2) }} owed</strong>
                        @else
                            <span class="badge badge-complete">Paid in full</span>
                        @endif
                    </td></tr>
                </table>
            @endif

            <div style="display:flex;gap:18px;flex-wrap:wrap;margin-top:12px">
                <form method="POST" action="{{ route('bookings.payroll', $booking) }}" style="display:flex;gap:8px;align-items:end">
                    @csrf
                    <input type="hidden" name="action" value="set">
                    <div class="field" style="margin:0">
                        <label for="pay-amount" style="font-size:12px">Job pays the driver (£)</label>
                        <input id="pay-amount" name="amount" type="number" step="0.01" min="0" value="{{ $pay }}" required style="width:130px">
                    </div>
                    <button class="btn btn-ghost" style="padding:8px 14px;font-size:13px">Set pay</button>
                </form>

                @if($pay !== null && $left > 0)
                    <form method="POST" action="{{ route('bookings.payroll', $booking) }}" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap">
                        @csrf
                        <input type="hidden" name="action" value="record">
                        <div class="field" style="margin:0">
                            <label for="paid-amount" style="font-size:12px">Record payment (£)</label>
                            <input id="paid-amount" name="amount" type="number" step="0.01" min="0.01" value="{{ $left }}" required style="width:130px">
                        </div>
                        <div class="field" style="margin:0">
                            <label for="paid-note" style="font-size:12px">Note (optional)</label>
                            <input id="paid-note" name="note" placeholder="e.g. bank transfer" style="width:170px">
                        </div>
                        <button class="btn btn-primary" style="padding:8px 14px;font-size:13px">✓ Record paid</button>
                    </form>
                @endif
            </div>

            @if($booking->driverPayHistory() !== [])
                <details style="margin-top:10px">
                    <summary class="muted" style="font-size:12px;cursor:pointer">Payment history</summary>
                    @foreach(array_reverse($booking->driverPayHistory()) as $h)
                        <div style="font-size:13px;padding:4px 0;border-bottom:1px solid rgba(128,128,128,.1)">
                            £{{ number_format($h['amount'], 2) }}
                            <span class="muted">· {{ \Illuminate\Support\Carbon::parse($h['at'])->format('D d M Y, H:i') }}
                            @if(!empty($h['by'])) · by {{ $h['by'] }}@endif
                            @if(!empty($h['note'])) · {{ $h['note'] }}@endif</span>
                        </div>
                    @endforeach
                </details>
            @endif
            <p class="hint" style="margin:10px 0 0">Full monthly view per driver: <a href="{{ route('payroll.index') }}">Payroll</a>.</p>
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
        @php
            // The key milestones in order, first time each happened.
            $milestones = [
                'allocated' => ['🧭', 'Driver allocated'],
                'accepted'  => ['✅', 'Driver accepted'],
                'en_route'  => ['🚗', 'On the way'],
                'arrived'   => ['📍', 'Arrived at pickup'],
                'collected' => ['🧳', 'Passenger on board'],
                'complete'  => ['🏁', 'Dropped off'],
            ];
            $firstOf = [];
            foreach ($booking->statusHistory->sortBy('created_at') as $h) {
                if (isset($milestones[$h->to_status]) && ! isset($firstOf[$h->to_status])) {
                    $firstOf[$h->to_status] = $h;
                }
            }
        @endphp
        @if(!empty($firstOf))
            <div class="card">
                <h2>Job timeline</h2>
                @foreach($milestones as $key => [$icon, $label])
                    @php $h = $firstOf[$key] ?? null; @endphp
                    <div style="display:flex;gap:12px;align-items:baseline;padding:7px 0;border-bottom:1px solid rgba(128,128,128,.1);{{ $h ? '' : 'opacity:.4' }}">
                        <span style="width:26px;flex:none;text-align:center">{{ $icon }}</span>
                        <span style="flex:1;font-weight:600">{{ $label }}</span>
                        @if($h)
                            <span style="font-variant-numeric:tabular-nums">{{ $h->created_at?->format('H:i') }}</span>
                            <span class="muted" style="font-size:12px">{{ $h->created_at?->format('d M') }}</span>
                            @if($h->gps_latitude)
                                <a href="https://www.google.com/maps?q={{ $h->gps_latitude }},{{ $h->gps_longitude }}" target="_blank" rel="noopener" title="Where the driver was" style="font-size:13px">📍 map</a>
                            @endif
                        @else
                            <span class="muted" style="font-size:13px">—</span>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

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

            {{-- The office's contact panel: the two REAL numbers you keep, plus
                 the TWO masked lines to hand out — one for the customer to reach
                 the driver, one for the driver to reach the customer. Neither
                 party ever gets the other's real number. --}}
            @php
                $maskedForCustomer = $booking->customerMaskedNumber(); // customer dials this to reach the driver
                $maskedForDriver = $booking->driverContactNumber();    // driver dials this to reach the customer
                $maskingConfigured = app(\App\Services\Telephony\TwilioProxyService::class)->configured();
                $driverRealPhone = $booking->driver?->phone ?? ($booking->meta['driver_details']['phone'] ?? null);
                $maskState = fn () => ! $maskingConfigured ? 'Masking not switched on here'
                    : (! $booking->driver_id ? 'Opens when you assign a driver' : 'No open session (job finished, or it couldn’t open)');
            @endphp

            <div class="muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">🔒 Real numbers — office only, never given out</div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px">
                <div style="flex:1;min-width:200px;border:1px solid var(--line);border-radius:10px;padding:10px 14px">
                    <div class="muted" style="font-size:12px">📞 Customer</div>
                    <div style="font-weight:700;font-size:15px" class="mono">{{ $booking->customer?->phone ?? '—' }}</div>
                </div>
                <div style="flex:1;min-width:200px;border:1px solid var(--line);border-radius:10px;padding:10px 14px">
                    <div class="muted" style="font-size:12px">🚗 Driver</div>
                    <div style="font-weight:700;font-size:15px" class="mono">{{ $driverRealPhone ?? '—' }}</div>
                </div>
            </div>

            <div class="muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">🎭 Masked CET lines — these are what you give out</div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">
                <div style="flex:1;min-width:220px;border:1px solid {{ $maskedForCustomer ? 'rgba(31,122,68,.45)' : 'var(--line)' }};border-radius:10px;padding:10px 14px;{{ $maskedForCustomer ? 'background:rgba(31,157,85,.06)' : '' }}">
                    <div class="muted" style="font-size:12px">➡️ Give to the <strong>CUSTOMER</strong> (rings the driver)</div>
                    @if($maskedForCustomer)
                        <div style="font-weight:800;font-size:16px" class="mono">{{ $maskedForCustomer }}</div>
                        <div class="hint" style="margin-top:2px">Already in the customer’s driver-details message. Closes ~30 min after On Board (or ~4h after drop-off if the job never hits POB).</div>
                    @else
                        <div style="font-weight:700;font-size:14px" class="muted">{{ $maskState() }}</div>
                    @endif
                </div>
                <div style="flex:1;min-width:220px;border:1px solid {{ $maskedForDriver ? 'rgba(31,122,68,.45)' : 'var(--line)' }};border-radius:10px;padding:10px 14px;{{ $maskedForDriver ? 'background:rgba(31,157,85,.06)' : '' }}">
                    <div class="muted" style="font-size:12px">⬅️ Give to the <strong>DRIVER</strong> (rings the customer)</div>
                    @if($maskedForDriver)
                        <div style="font-weight:800;font-size:16px" class="mono">{{ $maskedForDriver }}</div>
                        <div class="hint" style="margin-top:2px">Also shown on the driver’s own job screen. He dials this — never the customer’s real number.</div>
                    @else
                        <div style="font-weight:700;font-size:14px" class="muted">{{ $maskState() }}</div>
                    @endif
                </div>
            </div>

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
                            @if($m->isScheduledPrompt() && $m->scheduled_for && $m->status !== 'sent') · due {{ $m->scheduled_for->format('D d M, H:i') }}
                            @elseif($m->sent_at) · sent {{ $m->sent_at->format('d M H:i') }}@endif
                        </span>
                    </div>
                    <div class="msg-body" style="font-size:13px;color:#444;white-space:pre-line;margin-top:4px">{{ $m->body }}</div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;align-items:center">
                        @if($m->whatsAppLink() && $m->isReadyToSend())
                            <a href="{{ $m->whatsAppLink() }}" target="_blank" rel="noopener" class="btn" style="background:#25D366;color:#fff;padding:5px 12px;font-size:12px">📲 Send on WhatsApp</a>
                        @elseif($m->isScheduledPrompt() && ! $m->isReadyToSend())
                            <span class="muted" style="font-size:12px">🕗 Ready to send {{ $m->scheduled_for->format('D d M, H:i') }}</span>
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
