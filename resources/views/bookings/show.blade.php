@extends('layouts.app')
@section('title', 'Booking ' . $booking->reference)

@section('content')
    @if(!empty($backUrl))
        <a href="{{ $backUrl }}" class="muted" style="display:inline-block;margin-bottom:10px;font-size:14px;text-decoration:none">← Back to bookings</a>
    @endif
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

    {{-- Possible duplicate — another live booking looks like the same journey. --}}
    @if(auth()->user()->isAdmin())
        @php $dupes = $booking->duplicateCandidates(); @endphp
        @if($dupes->isNotEmpty())
            <div id="duplicate" class="card" style="border-left:4px solid #b32020;background:rgba(179,32,32,.06);margin-bottom:16px;scroll-margin-top:16px">
                <strong>⚠ Possible duplicate booking</strong>
                <p class="hint" style="margin:6px 0 8px">Another live booking looks like the same journey (same time &amp; customer/drop-off). Keep <strong>this</strong> copy and merge the other in — its driver, tips, calendar link and any missing details fold into this one, then it’s removed. <strong>Your Google Calendar isn’t touched.</strong></p>
                @foreach($dupes as $d)
                    <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between;padding:8px 0;border-top:1px solid rgba(179,32,32,.15)">
                        <span><a href="{{ route('bookings.show', $d) }}">{{ $d->reference }}</a> — {{ $d->displayCustomerName() }} · {{ $d->pickup_at->format('D d M, H:i') }} · {{ $d->status->label() }}@if($d->driver) · {{ $d->driver->name }}@endif</span>
                        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                            <form method="POST" action="{{ route('bookings.merge', $booking) }}" style="margin:0"
                                  onsubmit="return confirm('Merge {{ $d->reference }} into this booking? The other copy is removed. This does NOT touch Google Calendar.')">
                                @csrf
                                <input type="hidden" name="dupe_id" value="{{ $d->id }}">
                                <button class="btn" style="background:#b32020;color:#fff;padding:7px 14px;font-size:13px">⇄ Merge into this one</button>
                            </form>
                            <form method="POST" action="{{ route('bookings.keep-separate', $booking) }}" style="margin:0"
                                  onsubmit="return confirm('Mark {{ $d->reference }} as a SEPARATE booking? They stay as two records and won\'t be flagged as duplicates again.')">
                                @csrf
                                <input type="hidden" name="dupe_id" value="{{ $d->id }}">
                                <button class="btn btn-ghost" style="padding:7px 14px;font-size:13px" title="These are two genuinely different jobs — stop flagging them">Not a duplicate — keep separate</button>
                            </form>
                        </div>
                    </div>
                @endforeach
                <p class="hint" style="margin:8px 0 0">Tip: merge from the copy that’s already <strong>allocated to a driver</strong>, so the allocation stays put. Two real jobs at the same time? Tap <strong>keep separate</strong> and they won’t be flagged again.</p>
            </div>
        @endif
    @endif

    @if(auth()->user()->isAdmin())
        @php $contactFix = $booking->contactNumberMismatch(); @endphp
        @if($contactFix)
            <div class="card" style="border-left:4px solid #b8860b;background:rgba(184,134,11,.08);margin-bottom:16px">
                <strong>⚠ Contact number doesn’t match the calendar</strong>
                <p class="hint" style="margin:6px 0 8px">The customer record has <strong>{{ $booking->customer?->phone }}</strong> but this booking’s calendar contact is <strong>{{ $contactFix }}</strong>. Messages already go to the calendar number — tap below to also correct the stored record.</p>
                <form method="POST" action="{{ route('bookings.fix-contact', $booking) }}" style="margin:0">
                    @csrf
                    <button class="btn" style="background:#b8860b;color:#fff;padding:8px 16px">Use calendar number ({{ $contactFix }})</button>
                </form>
            </div>
        @endif
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

    {{-- Assign / reassign a system driver right here — same allocate flow as the
         dispatch board (sets Allocated, opens the masked line, notifies them). --}}
    @if(auth()->user()->isAdmin() && ! $booking->status->isTerminal())
        <div class="card">
            <h2 style="margin:0 0 4px">🧑‍✈️ {{ $booking->driver_id ? 'Change driver' : 'Assign a driver' }}</h2>
            <p class="hint" style="margin:0 0 12px">
                @if($booking->driver)Currently <strong>{{ $booking->driver->name }}</strong>. @endif
                Allocate a driver with a login — the job goes to <strong>Allocated</strong>, the masked line opens and the driver is notified.
            </p>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                <form method="POST" action="{{ route($booking->driver_id ? 'despatch.reassign' : 'despatch.allocate', $booking) }}" style="display:flex;gap:6px;flex-wrap:wrap">
                    @csrf
                    <select name="driver_id" required style="width:auto;min-width:190px">
                        <option value="">Choose a driver…</option>
                        @foreach($allocatableDrivers as $d)
                            @php $reg = $d->driverProfile?->defaultVehicle?->registration; @endphp
                            <option value="{{ $d->id }}" @selected($booking->driver_id === $d->id)>{{ $d->driverProfile?->callsign ?: $d->name }}@if($reg) ({{ $reg }})@endif</option>
                        @endforeach
                    </select>
                    <button class="btn btn-primary" style="padding:8px 16px">{{ $booking->driver_id ? 'Reassign' : 'Assign' }}</button>
                </form>
                @if($booking->vehicleType?->affects_rotation && ! $booking->driver_id)
                    <form method="POST" action="{{ route('despatch.auto-allocate', $booking) }}">
                        @csrf
                        <button class="btn btn-ghost" style="padding:8px 16px">Auto (rotation)</button>
                    </form>
                @endif
                @if($booking->driver_id)
                    <form method="POST" action="{{ route('despatch.reassign', $booking) }}" onsubmit="return confirm('Remove the driver and put this job back in Unallocated?')">
                        @csrf<input type="hidden" name="driver_id" value="">
                        <button class="btn btn-ghost" style="padding:8px 16px;color:#b32020">Remove driver</button>
                    </form>
                @endif
            </div>
            @if($allocatableDrivers->isEmpty())
                <p class="hint" style="margin:10px 0 0">No login-drivers yet. For a one-off/cover driver, use <strong>Driver for this job</strong> below (name &amp; number only).</p>
            @endif
        </div>
    @endif

    {{-- Shareable driver link — its own card so it's easy to find. Send it to a
         cover driver and they get the full job page (details, cash to collect,
         masked number, navigate, status, live GPS) with no login. --}}
    @if(auth()->user()->isAdmin() && ! $booking->status->isTerminal())
        @php
            $driverLink = $booking->driverLinkUrl();
            $linkPhone = \App\Support\Phone::wa($booking->driver?->phone ?? ($booking->meta['driver_details']['phone'] ?? null));
            $linkMsg = $booking->driverLinkMessage();
        @endphp
        <div class="card">
            <h2 style="margin:0 0 4px">🔗 Driver link — no login</h2>
            <p class="hint" style="margin:0 0 12px">Send this to the driver. It opens their full job sheet — details, cash to collect, the contact number, navigation and the status buttons — with live tracking, no account needed.</p>
            <input type="text" value="{{ $driverLink }}" readonly onclick="this.select()" style="font-size:12px;margin-bottom:10px">
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                <button type="button" class="btn btn-primary copy-link" data-link="{{ $driverLink }}" style="padding:9px 16px;font-size:14px">⧉ Copy link</button>
                @if($linkPhone)
                    <a href="https://wa.me/{{ $linkPhone }}?text={{ rawurlencode($linkMsg) }}" target="_blank" rel="noopener" class="btn" style="background:#25D366;color:#fff;padding:9px 16px;font-size:14px">📲 Send to driver</a>
                @endif
                <span class="copy-link-done hint" style="color:#1f8b4c"></span>
            </div>
            <p class="hint" style="margin:10px 0 0">Anyone with this link can work the job — treat it like a key. It stops working once the job is completed or cancelled.</p>
        </div>
        <script>
            (function () {
                document.querySelectorAll('.copy-link').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        var done = btn.parentElement.querySelector('.copy-link-done');
                        var finish = function () { if (done) done.textContent = '✓ Copied — paste it to the driver'; };
                        if (navigator.clipboard) { navigator.clipboard.writeText(btn.dataset.link).then(finish).catch(finish); }
                        else { finish(); }
                    });
                });
            })();
        </script>
    @endif

    {{-- Additional drivers for a MULTI-CAR job (e.g. a 3-car wedding). Each gets
         their own link and their own per-car status, tracked separately. --}}
    @if(auth()->user()->isAdmin() && ! $booking->status->isTerminal())
        @php $extraDrivers = $booking->extraDrivers(); @endphp
        <div class="card">
            <h2 style="margin:0 0 4px">🚗 Extra cars — multi-car job</h2>
            <p class="hint" style="margin:0 0 12px">For a job that needs more than one car, add each extra driver here. They get their own link and their own status buttons, so you can track each car separately. Extra drivers contact the customer via the office.</p>

            @foreach($extraDrivers as $i => $d)
                @php
                    $carNo = $i + 2;
                    $st = \App\Enums\BookingStatus::from($d['status'] ?? 'allocated');
                    $carLink = $booking->extraDriverLinkUrl($d['token']);
                    $carPhone = \App\Support\Phone::wa($d['phone'] ?? null);
                    $carMsg = "Central Executive Transfers\n\n".$booking->displayName()."\n".$booking->pickup_at->format('D d/m/Y H:i')."\n\nYou're Car {$carNo}. Open your job sheet:\n{$carLink}";
                @endphp
                <div style="border:1px solid var(--line);border-radius:10px;padding:12px 14px;margin-bottom:10px">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap">
                        <strong>Car {{ $carNo }} — {{ $d['name'] }}</strong>
                        <span class="badge badge-{{ $st->value }}">{{ $st->label() }}</span>
                    </div>
                    @if(!empty($d['phone']) || !empty($d['reg']) || !empty($d['car']))
                        <div class="hint" style="margin:4px 0 8px">{{ trim(implode(' · ', array_filter([$d['phone'] ?? '', $d['car'] ?? '', $d['reg'] ?? '']))) }}</div>
                    @endif
                    <input type="text" value="{{ $carLink }}" readonly onclick="this.select()" style="font-size:12px;margin-bottom:8px">
                    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                        <button type="button" class="btn btn-primary copy-link" data-link="{{ $carLink }}" style="padding:7px 14px;font-size:13px">⧉ Copy link</button>
                        @if($carPhone)
                            <a href="https://wa.me/{{ $carPhone }}?text={{ rawurlencode($carMsg) }}" target="_blank" rel="noopener" class="btn" style="background:#25D366;color:#fff;padding:7px 14px;font-size:13px">📲 Send</a>
                        @endif
                        <span class="copy-link-done hint" style="color:#1f8b4c"></span>
                        <form method="POST" action="{{ route('bookings.extra-drivers.remove', $booking) }}" style="margin:0;margin-left:auto"
                              onsubmit="return confirm('Remove Car {{ $carNo }} ({{ $d['name'] }}) from this job?')">
                            @csrf
                            <input type="hidden" name="token" value="{{ $d['token'] }}">
                            <button class="btn btn-ghost" style="padding:7px 12px;font-size:13px;color:var(--red)">Remove</button>
                        </form>
                    </div>
                </div>
            @endforeach

            <form method="POST" action="{{ route('bookings.extra-drivers.add', $booking) }}" style="margin-top:8px">
                @csrf
                <div class="grid grid-2">
                    <div class="field"><label for="ex-name">Driver name <span class="req">*</span></label><input id="ex-name" name="name" required placeholder="e.g. Sam Jones"></div>
                    <div class="field"><label for="ex-phone">Phone <span class="muted">(for you to send the link)</span></label><input id="ex-phone" name="phone" placeholder="07…"></div>
                    <div class="field"><label for="ex-reg">Vehicle reg</label><input id="ex-reg" name="reg" style="text-transform:uppercase"></div>
                    <div class="field"><label for="ex-car">Make &amp; model</label><input id="ex-car" name="car" placeholder="e.g. Black Mercedes V-Class"></div>
                </div>
                <button class="btn btn-primary" style="padding:8px 16px;font-size:14px">＋ Add another car</button>
            </form>
        </div>
    @endif

    @if(auth()->user()->isAdmin() && $booking->driver_id && ! $booking->status->isTerminal())
        {{-- Live driver location. "Request location" pushes the driver's phone to
             share where they are right now (works even before Set off); once
             they've set off the pinger keeps this fresh on its own. Polls the
             latest ping so the card updates without a page reload. --}}
        <div class="card" id="live-loc" style="margin-bottom:16px"
             data-poll="{{ route('bookings.location', $booking) }}"
             data-request="{{ route('bookings.request-location', $booking) }}">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
                <h2 style="margin:0">📍 Driver location</h2>
                <button type="button" id="loc-req-btn" class="btn btn-primary" style="padding:8px 14px;font-size:13px">Request location</button>
            </div>
            <div id="loc-status" class="hint" style="margin-top:8px">Checking…</div>
            <div id="loc-detail" style="margin-top:8px;display:none">
                <a id="loc-map" href="#" target="_blank" rel="noopener" class="btn btn-ghost" style="padding:7px 14px;font-size:13px">🗺 Open in Google Maps</a>
                <a href="{{ route('fleet.map') }}" class="btn btn-ghost" style="padding:7px 14px;font-size:13px">Live fleet map</a>
            </div>
            <p class="hint" style="margin:10px 0 0"><strong>Request location</strong> buzzes the driver to share where they are right now — even before they've set off. Needs push notifications switched on (VAPID keys); if they're off, the driver can still tap Share on their open job screen.</p>
        </div>
        <script src="{{ asset('js/cet-liveloc.js') }}" defer></script>
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
        <div id="payroll" class="card" style="scroll-margin-top:16px;{{ ($left !== null && $left > 0) ? 'border-left:4px solid #FBBA2A' : '' }}">
            <h2>Driver payroll — {{ $booking->payrollDriverName() }}</h2>
            @if($booking->driverSettledByCustomer())
                {{-- Cash job: the customer pays the driver directly on the day.
                     Nothing owed by the business — just confirm the amount. --}}
                @php $cash = $booking->cashDueToDriver(); $confirmed = $booking->cashConfirmed(); @endphp
                <p style="margin:0 0 6px">
                    <span class="badge badge-complete">Cash job — settled with the driver</span>
                    @if($confirmed)<span class="badge" style="background:#1f7a44;color:#fff">✓ Confirmed</span>@endif
                </p>
                <p class="muted" style="margin:0 0 8px">
                    The driver collects the cash from the customer directly on the day.
                    <strong>Nothing is owed by the business.</strong>
                </p>
                <form method="POST" action="{{ route('bookings.payroll', $booking) }}" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap">
                    @csrf
                    <input type="hidden" name="action" value="confirm_cash">
                    <div class="field" style="margin:0">
                        <label for="cash-amount" style="font-size:12px">Cash the driver collects (£)</label>
                        <input id="cash-amount" name="amount" type="number" step="0.01" min="0" inputmode="decimal"
                               value="{{ $cash !== null ? number_format($cash, 2, '.', '') : '' }}" required style="width:130px">
                    </div>
                    <button class="btn btn-primary" style="padding:8px 14px;font-size:13px">{{ $confirmed ? 'Update amount' : '✓ Confirm cash' }}</button>
                </form>
                <p class="hint" style="margin:6px 0 0">Wrong figure? Change it above and confirm — it won't touch the calendar.</p>
                <form method="POST" action="{{ route('bookings.payroll', $booking) }}" style="margin-top:10px">
                    @csrf
                    <input type="hidden" name="action" value="company_collected">
                    <input type="hidden" name="collected" value="1">
                    <button class="btn btn-ghost" style="padding:6px 12px;font-size:12px">This one was paid by card to the business →</button>
                    <span class="muted" style="font-size:12px">(customer tapped a card in the car — the business then owes the driver)</span>
                </form>
                <details style="margin-top:8px">
                    <summary class="muted" style="font-size:12px;cursor:pointer">Business is paying this driver something on top? Set it here</summary>
                    <form method="POST" action="{{ route('bookings.payroll', $booking) }}" style="display:flex;gap:8px;align-items:end;margin-top:8px">
                        @csrf
                        <input type="hidden" name="action" value="set">
                        <div class="field" style="margin:0">
                            <label for="pay-amount" style="font-size:12px">Extra the business pays (£)</label>
                            <input id="pay-amount" name="amount" type="number" step="0.01" min="0" value="{{ $pay }}" required style="width:130px">
                        </div>
                        <button class="btn btn-ghost" style="padding:8px 14px;font-size:13px">Set</button>
                    </form>
                </details>
            @else
            @if($booking->businessCollectedCash())
                <p class="muted" style="margin:0 0 8px">
                    💳 Paid by card to the business — the business owes the driver their pay.
                    <form method="POST" action="{{ route('bookings.payroll', $booking) }}" style="display:inline">
                        @csrf
                        <input type="hidden" name="action" value="company_collected">
                        <input type="hidden" name="collected" value="0">
                        <button class="btn-link" style="background:none;border:0;padding:0;color:#b8860b;cursor:pointer;font-size:12px;text-decoration:underline">undo — it was cash to the driver</button>
                    </form>
                </p>
            @endif
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
            @endif {{-- driverSettledByCustomer --}}

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

            {{-- Tips — the driver's gratuity, on top of job pay. --}}
            @php $tips = $booking->tipsTotal(); @endphp
            <div style="margin-top:14px;padding-top:12px;border-top:1px solid rgba(128,128,128,.15)">
                <strong style="font-size:14px">💛 Tips for {{ $booking->payrollDriverName() }}</strong>
                @if(app(\App\Services\Payments\SquareTipService::class)->enabled())
                    <div class="hint" style="margin:4px 0 6px">Customer card-tip link: <a href="{{ $booking->tipUrl() }}" target="_blank" rel="noopener">{{ $booking->tipUrl() }}</a> <span class="muted">(also added to the review message automatically)</span></div>
                @endif
                @if($tips > 0)
                    <span class="muted" style="font-size:13px"> · £{{ number_format($tips, 2) }} total @if($booking->tipsTotalBy('cash') > 0 && $booking->cardTipsOwed() > 0)(£{{ number_format($booking->tipsTotalBy('cash'), 2) }} cash · £{{ number_format($booking->cardTipsOwed(), 2) }} card)@endif</span>
                    @if($booking->cardTipsOwed() > 0)
                        <div class="hint" style="margin:4px 0 0">£{{ number_format($booking->cardTipsOwed(), 2) }} card tip owed to the driver (collected by the company).</div>
                    @endif
                @endif
                <form method="POST" action="{{ route('bookings.payroll', $booking) }}" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;margin-top:8px">
                    @csrf
                    <input type="hidden" name="action" value="tip">
                    <div class="field" style="margin:0">
                        <label for="tip-amount" style="font-size:12px">Tip received (£)</label>
                        <input id="tip-amount" name="amount" type="number" step="0.01" min="0.01" placeholder="5.00" required style="width:110px">
                    </div>
                    <div class="field" style="margin:0">
                        <label for="tip-method" style="font-size:12px">How</label>
                        <select id="tip-method" name="method" style="width:110px">
                            <option value="cash">Cash</option>
                            <option value="card">Card</option>
                        </select>
                    </div>
                    <button class="btn btn-ghost" style="padding:8px 14px;font-size:13px">Log tip</button>
                </form>
                @if($booking->tips() !== [])
                    <details style="margin-top:8px">
                        <summary class="muted" style="font-size:12px;cursor:pointer">Tip history</summary>
                        @foreach(array_reverse($booking->tips()) as $t)
                            <div style="font-size:13px;padding:4px 0;border-bottom:1px solid rgba(128,128,128,.1)">
                                £{{ number_format($t['amount'], 2) }} <span class="muted">{{ ucfirst($t['method'] ?? 'cash') }} · {{ \Illuminate\Support\Carbon::parse($t['at'])->format('D d M Y, H:i') }}@if(!empty($t['by'])) · by {{ $t['by'] }}@endif @if(!empty($t['note'])) · {{ $t['note'] }}@endif</span>
                            </div>
                        @endforeach
                    </details>
                @endif
            </div>

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
                $ownerDriving = $booking->driver?->isAdmin() ?? false;  // admin drives their own job
                $maskingOff = $booking->maskingDisabled();               // office turned masking off for this job
                $realNumbers = $ownerDriving || $maskingOff;             // no masked line — both use real numbers
                $switchboard = app(\App\Services\Telephony\MaskingService::class);
                $switchboardOn = $switchboard->configured();
                $maskedForCustomer = $realNumbers ? null : $switchboard->customerLine(); // customer rings this → reaches the driver
                $maskedForDriver = $realNumbers ? null : $switchboard->driverLine();      // driver rings this → reaches the customer
                $driverRealPhone = $booking->driver?->phone ?? ($booking->meta['driver_details']['phone'] ?? null);
            @endphp

            <div class="muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">🔒 Real numbers — office{{ $realNumbers ? '' : ' only, never given out' }}</div>
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

            @if($realNumbers)
                {{-- No masked line for this job — either an owner is driving, or
                     the office turned masking off. Both parties use their real
                     numbers. --}}
                <div class="card" style="border-left:4px solid #FBBA2A;background:rgba(251,186,42,.06);margin-bottom:14px;padding:10px 14px">
                    @if($ownerDriving)
                        <strong>👑 Owner is driving this one</strong>
                        <div class="hint" style="margin-top:4px">No masked line needed — {{ $booking->driver?->name }} uses the customer’s real number ({{ $booking->customer?->phone ?? '—' }}) straight from their job screen, and the customer has the driver’s direct number. A Twilio credit is saved.</div>
                    @else
                        <strong>🔓 Masking is OFF for this job</strong>
                        <div class="hint" style="margin-top:4px">Both sides use their real numbers — customer <span class="mono">{{ $booking->customer?->phone ?? '—' }}</span> · driver <span class="mono">{{ $driverRealPhone ?? '—' }}</span>. Handy when they already have each other's number (e.g. a return leg). The driver's job screen shows the real number too.</div>
                    @endif
                </div>
            @elseif($switchboardOn)
                {{-- Switchboard: two PERMANENT CET lines. Same numbers on every
                     job, so they're safe to send with driver details 24h ahead —
                     each rings whoever's on the job at the time of the call. --}}
                <div class="muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">🎭 CET switchboard lines — hand these out (same on every job)</div>
                <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">
                    <div style="flex:1;min-width:220px;border:1px solid rgba(31,122,68,.45);border-radius:10px;padding:10px 14px;background:rgba(31,157,85,.06)">
                        <div class="muted" style="font-size:12px">➡️ Give to the <strong>CUSTOMER</strong> (rings the driver)</div>
                        <div style="font-weight:800;font-size:16px" class="mono">{{ $maskedForCustomer }}</div>
                        <div class="hint" style="margin-top:2px">Goes in the driver-details message. Call or text it — reaches {{ $booking->driver?->name ?? 'the driver' }}. Never changes.</div>
                    </div>
                    <div style="flex:1;min-width:220px;border:1px solid rgba(31,122,68,.45);border-radius:10px;padding:10px 14px;background:rgba(31,157,85,.06)">
                        <div class="muted" style="font-size:12px">⬅️ Give to the <strong>DRIVER</strong> (rings the customer)</div>
                        <div style="font-weight:800;font-size:16px" class="mono">{{ $maskedForDriver }}</div>
                        <div class="hint" style="margin-top:2px">On the driver's job screen too. Call or text it — reaches the customer. Never the customer's real number.</div>
                    </div>
                </div>
            @else
                <div class="alert" style="margin-bottom:14px">Masking isn't switched on here yet. Set <span class="mono">TWILIO_CUSTOMER_LINE</span> and <span class="mono">TWILIO_DRIVER_LINE</span> to turn on the CET switchboard.</div>
            @endif

            @if($booking->driver_id && ! $ownerDriving && ! $booking->status->isTerminal())
                <form method="POST" action="{{ route('bookings.toggle-masking', $booking) }}" style="margin-bottom:14px">
                    @csrf
                    @if($maskingOff)
                        <button class="btn btn-ghost" style="padding:7px 14px;font-size:13px">🔒 Re-mask this job (use CET lines again)</button>
                        <span class="hint" style="margin-left:6px">Back to the CET switchboard lines for this job.</span>
                    @else
                        <button class="btn btn-ghost" style="padding:7px 14px;font-size:13px;color:#b8860b"
                                onclick="return confirm('Turn masking OFF for {{ $booking->reference }}? The customer and driver will use their real numbers for this job.')">🔓 Unmask this job (use real numbers)</button>
                        <span class="hint" style="margin-left:6px">For when they already have each other's number — e.g. a return leg.</span>
                    @endif
                </form>
            @endif

            {{-- Per-booking masking timing: when the line goes live + when it closes. --}}
            @if($booking->driver_id && ! $ownerDriving && ! $maskingOff && ! $booking->status->isTerminal())
                <form method="POST" action="{{ route('bookings.masking-timing', $booking) }}"
                      style="display:flex;gap:12px;align-items:end;flex-wrap:wrap;margin-bottom:14px;padding-top:10px;border-top:1px solid rgba(128,128,128,.15)">
                    @csrf
                    <div class="field" style="margin:0">
                        <label for="mask-lead" style="font-size:12px">Calls connect from (min before pickup)</label>
                        <input id="mask-lead" name="lead_minutes" type="number" min="0" max="1440" step="5"
                               value="{{ $booking->maskingLeadMinutes() }}" style="width:150px">
                    </div>
                    <div class="field" style="margin:0">
                        <label for="mask-grace" style="font-size:12px">Backstop close (h after drop-off)</label>
                        <input id="mask-grace" name="grace_hours" type="number" min="0" max="48" step="0.5"
                               value="{{ rtrim(rtrim(number_format($booking->maskingGraceHours(), 1, '.', ''), '0'), '.') }}" style="width:130px">
                    </div>
                    <button class="btn btn-ghost" style="padding:8px 14px;font-size:13px">Save timing</button>
                    <span class="hint">
                        @if($booking->maskingWindowOpen())
                            <strong style="color:#1f7a44">● Connecting now</strong>
                        @else
                            Connects from <strong>{{ $booking->maskingOpensAt()?->format('D d M, H:i') ?? '—' }}</strong> <span class="muted">— earlier calls hear a CET message</span>
                        @endif
                    </span>
                </form>
                <p class="hint" style="margin:-4px 0 12px">The masked number is on the driver's job screen from allocation. A call or text <strong>before</strong> the connect time plays a Central Executive Transfers message instead of connecting. <strong>Marking the job Complete closes the line straight away</strong> — the backstop only kicks in if a job is never marked complete.</p>
            @endif

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
                    <div class="msg-body" style="font-size:13px;color:#444;white-space:pre-line;margin-top:4px">{{ $m->renderedBody() }}</div>
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

            @if($booking->status->value === 'complete' && ! $booking->messages()->where('type', 'review_request')->exists())
                <div style="margin-top:12px;border-top:1px solid var(--line);padding-top:12px">
                    @if($reason = $booking->reviewSkipReason())
                        <p class="hint" style="margin:0 0 8px;color:#b8860b">⭐ {{ $reason }}</p>
                    @endif
                    @if(filled($booking->customer?->phone))
                        <form method="POST" action="{{ route('bookings.request-review', $booking) }}">
                            @csrf
                            <button class="btn btn-light" style="padding:7px 14px;font-size:13px">⭐ Request a review</button>
                        </form>
                        <p class="hint" style="margin:6px 0 0">Creates a review request to send by hand — overrides the once-per-customer rule.</p>
                    @endif
                </div>
            @endif

            <form method="POST" action="{{ route('bookings.message', $booking) }}" style="margin-top:14px">
                @csrf
                <label for="body" style="font-weight:600">Send a message to {{ $booking->customer?->name ?? 'the customer' }}</label>
                <textarea id="body" name="body" required placeholder="Type a message…" style="margin:6px 0 8px;min-height:70px">{{ old('body') }}</textarea>
                <button type="submit" class="btn btn-dark" style="padding:8px 16px">Send</button>
                <span class="hint">Goes to {{ $booking->customerContactNumber() ?? $booking->customer?->email ?? 'no contact on file' }}.</span>
            </form>
        </div>
    @endif

    @if(auth()->user()->isAdmin())
        @php
            $driverBrief = app(\App\Services\CalendarEventBuilder::class)->driverBrief($booking);
            $driverRealForWa = $booking->driver?->phone ?? ($booking->meta['driver_details']['phone'] ?? null);
            $briefWa = \App\Support\Phone::wa($driverRealForWa);
        @endphp
        <div class="card">
            <h2>🚗 Driver brief — send to the driver</h2>
            <p class="hint" style="margin-top:-8px">The job details for the driver — <strong>no price and no booking reference</strong>. A cover driver gets the masked CET number; a director driving their own job gets the real one. Copy it, or send it straight to the driver on WhatsApp.</p>
            <textarea id="driver-brief" readonly style="width:100%;min-height:240px;font-family:var(--mono,monospace);font-size:13px;line-height:1.5;white-space:pre-wrap">{{ $driverBrief }}</textarea>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;align-items:center">
                <button type="button" class="btn btn-ghost copy-brief" style="padding:8px 16px">⧉ Copy</button>
                @if($briefWa)
                    <a href="https://wa.me/{{ $briefWa }}?text={{ rawurlencode($driverBrief) }}" target="_blank" rel="noopener" class="btn" style="background:#25D366;color:#fff;padding:8px 16px">📲 Send to {{ $booking->driver?->name ?? 'the driver' }}</a>
                @else
                    <span class="hint">Add the driver's number (in <strong>Driver for this job</strong> above) to enable the WhatsApp send.</span>
                @endif
            </div>
            @verbatim
            <script>
                (function () {
                    var btn = document.querySelector('.copy-brief');
                    if (!btn) return;
                    btn.addEventListener('click', function () {
                        var t = document.getElementById('driver-brief');
                        t.select();
                        navigator.clipboard.writeText(t.value).then(function () {
                            var old = btn.textContent; btn.textContent = '✓ Copied';
                            setTimeout(function () { btn.textContent = old; }, 1500);
                        });
                    });
                })();
            </script>
            @endverbatim
        </div>
    @endif

    @if($auditLogs->isNotEmpty())
        <div class="card">
            <details>
                <summary style="cursor:pointer;font-weight:700;font-size:16px">Audit trail <span class="muted" style="font-weight:400;font-size:13px">· latest {{ $auditLogs->count() }}@if(($auditLogTotal ?? 0) > $auditLogs->count()) of {{ $auditLogTotal }}@endif</span></summary>
                <div style="overflow-x:auto;margin-top:10px">
                <table>
                    <thead><tr><th>When</th><th>Action</th><th>By</th><th>Changed</th></tr></thead>
                    <tbody>
                        @foreach($auditLogs as $log)
                            <tr>
                                <td style="white-space:nowrap">{{ $log->created_at?->format('d M H:i') }}</td>
                                <td>{{ ucfirst($log->action) }}</td>
                                <td>{{ $log->user?->name ?? 'System' }}</td>
                                <td class="mono" style="font-size:11px">{{ $log->new_values ? implode(', ', array_keys($log->new_values)) : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            </details>
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
