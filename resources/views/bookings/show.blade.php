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
                <div class="bh-who">{{ ($booking->meta['lead_name'] ?? null) ?: ($booking->displayCustomerName() ?? 'Customer') }}
                    <span class="badge badge-{{ $booking->statusKey() }}" id="hero-status">{{ $booking->statusLabel() }}</span>
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
            <span class="bh-chip">👤 {{ $booking->driver ? $booking->driver->nameWithNickname() : ($booking->meta['driver_details']['name'] ?? 'No driver yet') }}@if($booking->driverVehicleReg()) ({{ $booking->driverVehicleReg() }})@endif</span>
            <span class="bh-chip">{{ $booking->passengerCount() }} pax · {{ $booking->luggageShort() }}</span>
            @if($booking->displayPayment())
                <span class="bh-chip {{ str_contains(strtolower($booking->displayPayment()), 'paid') ? 'ok' : '' }}">💳 {{ $booking->displayPayment() }}</span>
            @elseif($booking->payment_status === 'paid')
                <span class="bh-chip ok">💳 Paid</span>
            @else
                <span class="bh-chip warn">💳 {{ ucfirst($booking->payment_status ?? 'pending') }}</span>
            @endif
            @if($booking->displayFlightNumber())<span class="bh-chip">🛬 {{ $booking->displayFlightNumber() }}</span>@endif
            @if($booking->waitingTimeLabel())<span class="bh-chip" title="Waiting time booked on this job — the driver waits and it's paid for">⏳ Waiting {{ $booking->waitingTimeLabel() }}</span>@endif
            @php
                $waited = $booking->recordedWaitingMinutes() ?? ($booking->status === \App\Enums\BookingStatus::Arrived ? $booking->waitingBillableMinutes() : null);
                // Total time at the pickup = the free grace + any prepaid minutes + the billable overage.
                $totalWaited = $waited === null ? null : ($booking->waitingGraceMinutes() + $booking->waitingIncludedMinutes() + $waited);
            @endphp
            @if($waited !== null && $waited > 0)
                <span class="bh-chip warn" title="Driver waited about {{ $totalWaited }} min at the pickup — {{ $waited }} min past the free {{ $booking->waitingGraceMinutes() }} min{{ $booking->waitingIncludedMinutes() > 0 ? ' + '.$booking->waitingIncludedMinutes().' min paid for' : '' }}{{ $booking->recordedWaitingMinutes() === null ? ' (still waiting)' : '' }}">⏱ {{ $totalWaited }} min waited · {{ $waited }} chargeable</span>
            @endif
            @if($booking->waitingCharge() > 0)
                @php
                    $wcTitle = $booking->waitingChargeableMinutes().' chargeable min at £'
                        .number_format($booking->waitingHourlyRate(), 0).'/hr, after the free '
                        .$booking->waitingGraceMinutes().' min'
                        .($booking->waitingIncludedMinutes() > 0 ? ' + '.$booking->waitingIncludedMinutes().' min paid for' : '');
                @endphp
                <span class="bh-chip warn" title="{{ $wcTitle }}">💷 Waiting charge £{{ number_format($booking->waitingCharge(), 2) }}</span>
            @endif
            @if($booking->hasCashToCollect())
                @if($booking->cashCollectAcknowledged())
                    <span class="bh-chip ok" title="Driver confirmed the cash reminder{{ $booking->cashCollectAckAt() ? ' at '.$booking->cashCollectAckAt()->format('D d M, H:i') : '' }}">💷 {{ $booking->cashToCollectDisplay() }} cash · driver ✓</span>
                @else
                    <span class="bh-chip warn" title="Driver has not yet acknowledged the cash reminder">💷 {{ $booking->cashToCollectDisplay() }} cash · not confirmed</span>
                @endif
            @elseif($booking->returnLegCollectedOnOutbound())
                <span class="bh-chip ok" title="This is the return leg of a cash job — the driver collected the whole fare on the outbound, so there is nothing to collect here. The driver's link just shows 'Paid'.">💷 already collected on the outbound</span>
            @elseif($booking->paymentNeedsChecking())
                <span class="bh-chip warn" title="A balance may be owed but the amount isn't clear on the booking — the driver is told to check with the office">💷 payment unclear — check</span>
            @endif
            @if($booking->displayChildSeats())
                @if($booking->anyChildSeatConfirmed())
                    <span class="bh-chip ok" title="Driver confirmed collecting the child seat{{ $booking->childSeatsCollectedAt() ? ' at '.$booking->childSeatsCollectedAt()->format('D d M, H:i') : '' }}">🚼 {{ $booking->displayChildSeats() }} · collected ✓</span>
                @else
                    <span class="bh-chip warn" title="Driver has not yet confirmed collecting the child seat from the office">🚼 {{ $booking->displayChildSeats() }} · not collected</span>
                @endif
            @endif
            @if($booking->driverReadNotes())
                @if($booking->driverNotesAcknowledged())
                    <span class="bh-chip ok" title="Driver confirmed reading the notes{{ $booking->driverNotesAckAt() ? ' at '.$booking->driverNotesAckAt()->format('D d M, H:i') : '' }}">📝 notes · read ✓</span>
                @else
                    <span class="bh-chip warn" title="Driver has not yet confirmed reading the job notes">📝 notes · not read</span>
                @endif
            @endif
        </div>
        <div class="bh-meta">Created {{ $booking->created_at->format('D d M Y, H:i') }}@if($booking->createdBy) by {{ $booking->createdBy->name }}@endif</div>
    </div>

    @if(session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-error">{{ session('error') }}</div>
    @endif

    {{-- Notes contain a phone number → hidden from the driver (masking safety).
         Inform the office and let them set it as the contact number + a name. --}}
    @if(auth()->user()->isAdmin() && $booking->notesContainNumber())
        <div class="card" style="border-left:4px solid #b8860b;background:rgba(251,186,42,.12);margin-bottom:16px">
            <strong>⚠ A phone number is in the notes — hidden from the driver</strong>
            <p class="hint" style="margin:6px 0 10px">To protect number masking, the driver can't see the note. Set the right contact number below (and a name so it's easy to spot — we often only have a first name). It'll be used for masking, driver details and reminders on this booking.</p>
            <form method="POST" action="{{ route('bookings.set-contact', $booking) }}" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
                @csrf
                <div class="field" style="margin:0">
                    <label style="font-size:12px">Contact number</label>
                    <select name="contact_number" style="min-width:170px">
                        @foreach($booking->notePhoneNumbers() as $num)
                            <option value="{{ $num }}">{{ $num }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field" style="margin:0">
                    <label style="font-size:12px">Name (optional)</label>
                    <input name="lead_name" value="{{ $booking->displayName() }}" placeholder="e.g. Alexandra Goodman (booked by John)" style="min-width:220px">
                </div>
                <button class="btn btn-primary" style="padding:8px 14px">Use this number</button>
            </form>
            <p class="hint" style="margin:8px 0 0">Current contact: <strong>{{ $booking->displayContact() ?: '—' }}</strong></p>
        </div>
    @endif

    @if(auth()->user()->isAdmin() && $booking->driver_id && ! $booking->status->isTerminal())
        {{-- Live driver location — kept right at the top, under the customer, so the
             office can see where the driver is (and ring/chase them) the instant the
             job opens, without scrolling. "Request location" pushes the driver's
             phone to share where they are right now (works even before Set off);
             once they've set off the pinger keeps this fresh on its own. Polls the
             latest ping so the card updates without a page reload. --}}
        <div class="card" id="live-loc" style="margin-bottom:16px"
             data-poll="{{ route('bookings.location', $booking) }}"
             data-request="{{ route('bookings.request-location', $booking) }}">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
                <h2 style="margin:0">📍 Driver location</h2>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    {{-- Phone the driver right now with a spoken "update your status"
                         nudge — for when a WhatsApp isn't cutting through. --}}
                    <form method="POST" action="{{ route('bookings.ring-driver', $booking) }}" style="margin:0"
                          onsubmit="return confirm('Ring {{ addslashes($booking->driver->name ?? 'the driver') }} now? They\'ll hear a message asking them to update their status.');">
                        @csrf
                        <button type="submit" class="btn btn-ghost" style="padding:8px 14px;font-size:13px">📞 Ring driver to update</button>
                    </form>
                    <button type="button" id="loc-req-btn" class="btn btn-primary" style="padding:8px 14px;font-size:13px">Request location</button>
                </div>
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

    {{-- Driver confirmed they're on it (tapped "Getting ready"), so the office
         knows the job is handled without chasing. Shown until the job is done. --}}
    @if(auth()->user()->isAdmin() && $booking->gettingReadyConfirmedAt() && ! $booking->status->isTerminal())
        <div class="card" style="margin-bottom:16px;border-left:4px solid #1f7a44;background:rgba(31,122,68,.08);display:flex;align-items:center;gap:12px">
            <span style="font-size:24px;line-height:1">🟢</span>
            <div>
                <strong style="font-size:15px">Driver has confirmed they’re on it</strong>
                <div class="muted" style="font-size:13px">{{ $booking->driverLabel() }} tapped “Getting ready” at {{ $booking->gettingReadyConfirmedAt()->format('D d M, H:i') }}.</div>
            </div>
        </div>
    @endif

    {{-- Job timeline — the milestones with times, pin distances, ETA and a
         batch-update flag. Kept right under the driver location so the office
         sees how the job actually ran before the controls below. --}}
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

                @php $batchFlag = $booking->batchUpdateFlag(); @endphp
                @if($batchFlag)
                    <div class="alert" style="background:rgba(179,32,32,.08);border:1px solid rgba(179,32,32,.35);color:#8a1c1c;margin:0 0 10px">
                        ⚠️ <strong>Looks batch-updated.</strong> {{ $batchFlag }}
                    </div>
                @endif
                @php $eta = $booking->enRouteEta(); @endphp

                @foreach($milestones as $key => [$icon, $label])
                    @php
                        $h = $firstOf[$key] ?? null;
                        $pin = ($h && $h->gps_latitude)
                            ? $booking->pinDistanceMiles((float) $h->gps_latitude, (float) $h->gps_longitude, $key)
                            : null;
                    @endphp
                    <div style="display:flex;gap:10px;align-items:baseline;padding:7px 0;border-bottom:1px solid rgba(128,128,128,.1);flex-wrap:wrap;{{ $h ? '' : 'opacity:.4' }}">
                        <span style="width:26px;flex:none;text-align:center">{{ $icon }}</span>
                        <span style="flex:1;min-width:120px;font-weight:600">{{ $label }}</span>
                        @if($h)
                            <span style="font-variant-numeric:tabular-nums">{{ $h->created_at?->format('H:i') }}</span>
                            <span class="muted" style="font-size:12px">{{ $h->created_at?->format('d M') }}</span>
                            @if($h->gps_latitude)
                                <a href="https://www.google.com/maps?q={{ $h->gps_latitude }},{{ $h->gps_longitude }}" target="_blank" rel="noopener" title="Where the driver was" style="font-size:13px">📍 map</a>
                            @endif
                            {{-- How far the pin was from where the step should happen. --}}
                            @if($pin)
                                <span style="font-size:12px;{{ $pin['far'] ? 'color:#b32020;font-weight:700' : ($pin['expects'] ? 'color:#1f7a44' : 'color:#888') }}">
                                    {{ number_format($pin['miles'], 1) }} mi from {{ $pin['ref'] }}@if($pin['expects']){{ $pin['far'] ? ' ⚠' : ' ✓' }}@endif
                                </span>
                            @endif
                            {{-- Set-off ETA on the "on the way" row; ETA vs actual on "arrived". --}}
                            @if($key === 'en_route' && $eta)
                                <span class="muted" style="font-size:12px">· ETA {{ $eta->format('H:i') }}@if($booking->enRouteDriveMinutes() !== null) ({{ $booking->enRouteDriveMinutes() }} min){{ '' }}@endif</span>
                            @elseif($key === 'arrived' && $eta && $h->created_at)
                                @php $lateMin = (int) round($eta->diffInMinutes($h->created_at, false)); @endphp
                                <span style="font-size:12px;{{ $lateMin > 10 ? 'color:#b32020;font-weight:700' : 'color:#1f7a44' }}">
                                    · {{ $lateMin <= 0 ? 'on/ahead of ETA' : $lateMin.' min after ETA' }}
                                </span>
                            @endif
                        @else
                            <span class="muted" style="font-size:13px">—</span>
                        @endif
                    </div>

                    {{-- Via stops sit between Passenger on board (first pickup) and Dropped
                         off — each shows BOTH times: when the driver arrived at the stop and
                         when they moved on (picked up / dropped off), plus how long they waited. --}}
                    @if($key === 'collected' && $booking->hasViaStops())
                        @foreach($booking->viaStops() as $si => $stopAddr)
                            @php
                                $sArr = $booking->stopArrivedAt($si);
                                $sGo = $booking->stopPickedUpAt($si);
                                $sWait = $booking->stopWaitLabel($si);
                                $sVerb = $booking->stopActionVerb(); // "Picked up" / "Dropped off"
                            @endphp
                            <div style="display:flex;gap:10px;align-items:baseline;padding:7px 0 7px 26px;border-bottom:1px solid rgba(128,128,128,.1);flex-wrap:wrap;{{ $sArr ? '' : 'opacity:.4' }}">
                                <span style="width:26px;flex:none;text-align:center">🛑</span>
                                <span style="flex:1;min-width:120px;font-weight:600">Stop {{ $si + 1 }}<span class="muted" style="font-weight:400;font-size:12px"> · {{ \Illuminate\Support\Str::limit($stopAddr, 28) }}</span></span>
                                @if($sArr)
                                    <span style="font-size:12px">
                                        Arrived <strong style="font-variant-numeric:tabular-nums">{{ $sArr->format('H:i') }}</strong>
                                        @if($sGo)· {{ $sVerb }} <strong style="font-variant-numeric:tabular-nums">{{ $sGo->format('H:i') }}</strong>@else· <span style="color:#b8860b;font-weight:700">still here</span>@endif
                                        @if($sWait)<span class="muted"> (waited {{ $sWait }})</span>@endif
                                    </span>
                                @else
                                    <span class="muted" style="font-size:13px">not reached yet —</span>
                                @endif
                            </div>
                        @endforeach
                    @endif
                @endforeach
            </div>
        @endif
    @endif

    {{-- Cancellation outcome — full control over the money on a cancelled/no-show
         job: charge nothing, charge part/all (driver still paid), and/or refund the
         customer in full or part. A kept fee counts in revenue + payroll; a refund
         is recorded (and marks the job refunded) — the real card refund is done by
         hand in Square. The Google Calendar event is never touched either way. --}}
    @if(auth()->user()->isAdmin() && in_array($booking->status, [\App\Enums\BookingStatus::Cancelled, \App\Enums\BookingStatus::NoShow], true))
        @php
            $baseFare = $booking->cancellationOriginalFare() ?? $booking->fareAmount();
            $hasCharge = $booking->hasCancellationCharge();
            $hasRefund = $booking->hasCancellationRefund();
            $paid = $booking->transactionsPaidTotal();
            $fullRefundAmount = $paid > 0 ? $paid : ($baseFare ?: 0);
        @endphp
        <div class="card" style="border-left:4px solid #b8860b;background:rgba(251,186,42,.08);margin-bottom:16px">
            <h2 style="margin:0 0 4px">🚫 Cancellation outcome</h2>
            <p class="hint" style="margin:0 0 10px">Decide what happens to the money. Charge nothing, charge part or all (the driver still gets their share), or refund the customer in full or part. Nothing here touches Google Calendar.</p>

            @if($paid > 0)
                <p style="margin:0 0 8px;font-size:14px">Customer has paid <strong>£{{ number_format($paid, 2) }}</strong> so far{{ $booking->payment_status === 'refunded' ? ' · marked REFUNDED' : '' }}.</p>
            @endif
            @if($hasCharge || $hasRefund)
                <p style="margin:0 0 10px;font-size:15px">
                    @if($hasCharge)
                        Charging <strong>£{{ number_format($booking->cancellationFee(), 2) }}</strong>{{ $baseFare ? ' of the £'.number_format($baseFare, 2).' fare' : '' }}; driver gets <strong>£{{ number_format($booking->cancellationDriverPay() ?? 0, 2) }}</strong>.
                    @endif
                    @if($hasRefund)
                        <br>Refunding <strong>£{{ number_format($booking->cancellationRefund(), 2) }}</strong> to the customer{{ $booking->cancellationRefundReason() ? ' — '.$booking->cancellationRefundReason() : '' }}. <span class="hint">Process the card refund in Square.</span>
                    @endif
                </p>
            @endif

            <form method="POST" action="{{ route('bookings.cancellation-charge', $booking) }}" id="cancel-charge-form">
                @csrf
                <div class="grid grid-2" style="gap:12px">
                    <div class="field">
                        <label for="cc-fee">Charge to customer (£)</label>
                        <input id="cc-fee" type="number" step="0.01" min="0" name="fee" value="{{ $booking->cancellationFee() }}" placeholder="0.00 = no charge">
                    </div>
                    <div class="field">
                        <label for="cc-driver">Driver pay (£)</label>
                        <input id="cc-driver" type="number" step="0.01" min="0" name="driver_pay" value="{{ $booking->cancellationDriverPay() }}" placeholder="leave blank if unpaid">
                    </div>
                </div>
                <div class="grid grid-2" style="gap:12px;margin-top:4px">
                    <div class="field">
                        <label for="cc-refund">Refund to customer (£)</label>
                        <input id="cc-refund" type="number" step="0.01" min="0" name="refund" value="{{ $booking->cancellationRefund() }}" placeholder="0.00 = no refund">
                    </div>
                    <div class="field">
                        <label for="cc-refund-reason">Reason for refund <span class="hint">(optional)</span></label>
                        <input id="cc-refund-reason" type="text" maxlength="300" name="refund_reason" value="{{ $booking->cancellationRefundReason() }}" placeholder="e.g. cancelled in good time">
                    </div>
                </div>

                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:10px">
                    <span class="hint" style="align-self:center">Quick:</span>
                    <button type="button" class="btn btn-light" style="padding:6px 12px;font-size:13px"
                            onclick="cetCancelPreset(0, 0)">No charge</button>
                    @if($baseFare)
                        <button type="button" class="btn btn-light" style="padding:6px 12px;font-size:13px"
                                onclick="cetCancelPreset({{ number_format($baseFare / 2, 2, '.', '') }}, null)">Charge 50% (£{{ number_format($baseFare / 2, 2) }})</button>
                        <button type="button" class="btn btn-light" style="padding:6px 12px;font-size:13px"
                                onclick="cetCancelPreset({{ number_format($baseFare, 2, '.', '') }}, null)">Charge in full (£{{ number_format($baseFare, 2) }})</button>
                    @endif
                    @if($fullRefundAmount > 0)
                        <button type="button" class="btn btn-light" style="padding:6px 12px;font-size:13px;border-color:#b32020;color:#b32020"
                                onclick="cetCancelPreset(0, 0, {{ number_format($fullRefundAmount, 2, '.', '') }})">Full refund (£{{ number_format($fullRefundAmount, 2) }})</button>
                    @endif
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:12px">
                    <button class="btn btn-primary" style="padding:8px 16px;font-size:14px">Save outcome</button>
                    @if($hasCharge)
                        <button type="submit" name="clear" value="1" class="btn btn-ghost" style="padding:8px 14px;font-size:13px;color:#b32020"
                                onclick="return confirm('Remove the cancellation charge and restore the original fare?')">Remove charge</button>
                    @endif
                    @if($hasRefund)
                        <button type="submit" name="clear_refund" value="1" class="btn btn-ghost" style="padding:8px 14px;font-size:13px;color:#b32020"
                                onclick="return confirm('Remove the recorded refund?')">Remove refund</button>
                    @endif
                </div>
                <p class="hint" style="margin:10px 0 0">A kept fee counts in <a href="{{ route('payroll.index') }}">Payroll</a> (driver's cut) and revenue. A refund is recorded for the books and marks the job refunded — <strong>you still process the actual card refund in Square by hand</strong>.</p>
            </form>
        </div>
        <script>
            function cetCancelPreset(fee, driver, refund){
                var f = document.getElementById('cc-fee'); if (f) f.value = fee;
                var d = document.getElementById('cc-driver'); if (d && driver !== null) d.value = driver;
                var r = document.getElementById('cc-refund'); if (r && typeof refund !== 'undefined') r.value = refund;
            }
        </script>
    @endif

    {{-- Possible duplicate — another live booking looks like the same journey. --}}
    @if(auth()->user()->isAdmin())
        @php $dupes = $booking->duplicateCandidates(); @endphp
        @if($dupes->isNotEmpty())
            <div id="duplicate" class="card" style="border-left:4px solid #b32020;background:rgba(179,32,32,.06);margin-bottom:16px;scroll-margin-top:16px">
                <strong>⚠ Possible duplicate booking</strong>
                <p class="hint" style="margin:6px 0 8px">Another live booking looks like the same journey (same time &amp; customer/drop-off, or the <strong>same flight for the same customer</strong>). Keep <strong>this</strong> copy and merge the other in — its driver, tips, calendar link and any missing details fold into this one, then it’s removed. <strong>Your Google Calendar isn’t touched.</strong></p>
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
            @php $recordShared = $booking->customer_id && \App\Models\Booking::where('customer_id', $booking->customer_id)->where('id', '!=', $booking->id)->exists(); @endphp
            <div class="card" style="border-left:4px solid #b8860b;background:rgba(184,134,11,.08);margin-bottom:16px">
                <strong>⚠ Contact number doesn’t match the customer record</strong>
                <p class="hint" style="margin:6px 0 8px">This booking’s contact number is <strong>{{ $contactFix }}</strong>, but it’s filed under <strong>{{ $booking->customer?->name }}</strong> whose saved number is <strong>{{ $booking->customer?->phone }}</strong>. Messages already go to the booking’s number.
                    @if($recordShared)
                        <br><strong style="color:#8a6d00">{{ $booking->customer?->name }} has other bookings</strong>, so tapping below re-files <em>this</em> booking under its own customer (named from the booking) and leaves {{ $booking->customer?->name }}’s record untouched — it will not change {{ $booking->customer?->name }}’s number.
                    @else
                        Tap below to correct the stored record to match.
                    @endif
                </p>
                <form method="POST" action="{{ route('bookings.fix-contact', $booking) }}" style="margin:0">
                    @csrf
                    <button class="btn" style="background:#b8860b;color:#fff;padding:8px 16px">{{ $recordShared ? 'Re-file this booking under its own customer' : 'Use the booking’s number ('.$contactFix.')' }}</button>
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
            <form method="POST" action="{{ route('bookings.clear-audit', $booking) }}" style="margin:10px 0 0"
                  onsubmit="return confirm('Mark this audit flag as checked and clear it? It disappears from the booking and the Needs-attention list.')">
                @csrf
                <button class="btn btn-ghost" style="padding:7px 14px;font-size:13px">✓ Checked — clear this flag</button>
            </form>
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

    @if(auth()->user()->isAdmin())
        <div class="toolbar" style="margin-bottom:16px">
            {{-- Edit is ALWAYS available (incl. completed / cancelled jobs) so the
                 office can correct a wrong date or name WITHOUT reverting the status
                 or losing the job's timeline. --}}
            <a href="{{ route('bookings.edit', $booking) }}" class="btn btn-primary" style="padding:9px 16px">✏️ Edit booking</a>
            @if(! $booking->status->isTerminal())
                @php
                    $copySummary = implode("\n", array_filter([
                        $booking->pickup_at?->format('D d M Y').' · '.$booking->pickup_at?->format('H:i'),
                        $booking->displayName(),
                        '📍 '.$booking->displayPickupAddress(),
                        '🏁 '.$booking->displayDropoffAddress(),
                        trim(($booking->vehicleType?->name ?? '').' · '.$booking->passengerCount().' pax'),
                        $booking->displayFlightNumber() ? '✈ '.$booking->displayFlightNumber() : null,
                        'Ref '.$booking->reference.($booking->fareGross() !== null ? ' · £'.number_format((float) $booking->fareGross(), 2) : ''),
                    ]));
                @endphp
                <button type="button" class="btn btn-dark copy-pay-link" data-link="{{ $copySummary }}" style="padding:9px 16px">📋 Copy summary</button>
                <span class="copy-link-done hint" style="color:#1f8b4c;align-self:center"></span>
                @if(! $booking->linked_booking_id && ! $booking->is_return_leg)
                    <button type="button" class="btn btn-ghost" style="padding:9px 16px" onclick="document.getElementById('return-box').style.display='block';this.style.display='none'">↩ Create return leg</button>
                @endif
                @if($booking->is_return_leg || ($booking->linked_booking_id && $booking->journey_type === 'return'))
                    <form method="POST" action="{{ route('bookings.unlink-return', $booking) }}"
                          onsubmit="return confirm('Mark this as NOT a return and unlink it from the other leg? Both bookings stay — only the return pairing is removed.')">
                        @csrf
                        <button class="btn btn-ghost" style="padding:9px 16px">⛓ Not a return — unlink</button>
                    </form>
                @endif
                <button type="button" class="btn btn-ghost" style="padding:9px 16px" onclick="document.getElementById('postpone-box').style.display='block';this.style.display='none'">⏸ Postpone</button>
                <button type="button" class="btn btn-ghost" style="padding:9px 16px;color:#b32020" onclick="document.getElementById('cancel-box').style.display='block';this.style.display='none'">✕ Cancel booking</button>
            @endif
            {{-- Delete is ALWAYS available to an admin, including on terminal jobs
                 (No Show / Cancelled / Completed) — that's exactly when you need to
                 clear a test or junk booking. Soft delete; the calendar is untouched. --}}
            <button type="button" class="btn btn-ghost" style="padding:9px 16px;color:#b32020" onclick="document.getElementById('delete-box').style.display='block';this.style.display='none'">🗑️ Delete booking</button>
        </div>
        <div id="delete-box" class="card" style="display:none;border-left:4px solid #b32020;background:rgba(179,32,32,.06);margin-bottom:16px">
            <strong>Delete this booking?</strong>
            <p class="hint" style="margin:6px 0 10px">It's removed from the Command Centre everywhere (bookings list, dispatch, review). Cancel instead if you just want to call it off but keep the record. The <strong>Google Calendar event is never touched</strong> — remove it by hand if it was pushed there. This is recoverable if you delete one by mistake.</p>
            <form method="POST" action="{{ route('bookings.destroy', $booking) }}" onsubmit="return confirm('Delete booking {{ $booking->reference }}? It disappears from the Command Centre. The Google Calendar event is NOT removed.')" style="margin:0">
                @csrf @method('DELETE')
                <button type="submit" class="btn" style="background:#b32020;color:#fff;padding:9px 16px">Yes, delete booking</button>
                <button type="button" class="btn btn-ghost" style="padding:9px 16px" onclick="document.getElementById('delete-box').style.display='none'">Keep it</button>
            </form>
        </div>

        {{-- Per-booking receipt / VAT invoice: view the PDF, download it, or email
             it. A COVER JOB (we covered the job for another operator) becomes an
             INVOICE to that operator, who pays us; otherwise it's a customer
             receipt / VAT invoice. --}}
        @php
            $cover = $booking->coverFor();
            $recName = $cover ? $cover['name'] : $booking->bookerName();
            $recEmail = $cover ? $cover['email'] : ($booking->bookerEmail() ?: $booking->customer?->email);
            $docWord = $cover ? 'invoice' : ($booking->vatInvoiceRequested() ? 'VAT invoice' : 'invoice');
            $docTotal = $cover ? $booking->coverForAmount() : $booking->fareGross();
            $waDigits = $cover ? \App\Support\Phone::wa($cover['phone']) : null;
            $waText = $cover
                ? rawurlencode('Hi '.$cover['name'].', please find our invoice for job '.$booking->reference.' ('.$booking->pickup_at?->format('d M Y').')'.($docTotal !== null ? ' — £'.number_format((float) $docTotal, 2) : '').'. Central Executive Transfers.')
                : null;
        @endphp
        <div class="card" style="margin-bottom:16px">
            <h2 style="margin:0 0 6px;font-size:16px">📄 {{ $cover ? 'Cover-job invoice' : ($booking->vatInvoiceRequested() ? 'VAT invoice' : 'Invoice') }}</h2>
            @if($cover)
                <p class="hint" style="margin:0 0 10px">Invoicing <strong>{{ $cover['name'] }}</strong>{{ $docTotal !== null ? ' for £'.number_format((float) $docTotal, 2) : '' }} — a job we covered for them. Edit the details below; the PDF is addressed to them.</p>
            @elseif($booking->fareGross() === null)
                <p class="hint" style="margin:0 0 10px">No price on this job yet — set the fare above and the invoice will show it. You can still open a blank {{ $docWord }}.</p>
            @else
                <p class="hint" style="margin:0 0 10px">Total <strong>£{{ number_format((float) $booking->fareGross(), 2) }}</strong>{{ $booking->fareIsPaid() ? ' · marked paid' : ' · balance due' }}. To amend any detail, use <strong>Edit booking</strong> above, then reopen the PDF.</p>
            @endif

            {{-- VAT invoice toggle — adds 20% on top of the standard (ex-VAT) price,
                 for a customer who's asked to be invoiced with VAT. --}}
            @unless($cover)
                @php $vb = $booking->fareVatBreakdown(); @endphp
                <div style="border:1px solid var(--line);border-radius:10px;padding:10px 12px;margin:0 0 12px;background:rgba(0,0,0,.02)">
                    <form method="POST" action="{{ route('bookings.vat-invoice', $booking) }}" style="margin:0;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                        @csrf
                        <input type="hidden" name="vat" value="{{ $booking->vatInvoiceRequested() ? '0' : '1' }}">
                        <label class="checkbox-row" style="margin:0;font-weight:600">
                            <input type="checkbox" onchange="this.form.submit()" @checked($booking->vatInvoiceRequested())>
                            Charge VAT (customer wants a VAT invoice)
                        </label>
                        @if($booking->vatInvoiceRequested() && $vb)
                            <span class="muted" style="font-size:13px">
                                Net £{{ number_format($vb['net'], 2) }} + VAT £{{ number_format($vb['vat'], 2) }} =
                                <strong style="color:var(--accent,#b8860b)">£{{ number_format($vb['gross'], 2) }} to charge</strong>
                            </span>
                        @endif
                    </form>
                    @if($booking->vatInvoiceRequested())
                        <p class="hint" style="margin:8px 0 0">Charge the <strong>£{{ number_format((float) $booking->amountPayable(), 2) }}</strong> (incl. VAT) via a payment link below, then View/Email the VAT invoice — it shows the net, VAT and total and your VAT number.</p>
                        {{-- VAT received: auto-ticks when the customer pays the VAT via the Square
                             link; here to set it by hand if paid another way. When ticked the
                             invoice reads paid in full. --}}
                        <form method="POST" action="{{ route('bookings.invoice.vat-received', $booking) }}" style="margin:10px 0 0;display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                            @csrf
                            <input type="hidden" name="received" value="{{ $booking->vatSettled() ? '0' : '1' }}">
                            @if($booking->vatSettled())
                                <span class="badge" style="background:#1f7a44;color:#fff">✓ VAT received</span>
                                <button class="btn btn-ghost" style="padding:5px 10px;font-size:12px">Mark outstanding</button>
                            @else
                                <button class="btn btn-primary" style="padding:6px 12px;font-size:13px">✓ Mark VAT received (£{{ number_format($booking->vatOnTopAmount(), 2) }})</button>
                                <span class="muted" style="font-size:12px">Ticks automatically when the VAT link is paid.</span>
                            @endif
                        </form>
                    @endif
                </div>
            @endunless
            <div class="toolbar" style="margin:0">
                <a href="{{ route('bookings.receipt', $booking) }}" target="_blank" rel="noopener" class="btn btn-primary" style="padding:8px 14px">👁 View PDF</a>
                <a href="{{ route('bookings.receipt', ['booking' => $booking, 'download' => 1]) }}" class="btn btn-ghost" style="padding:8px 14px">⬇ Download</a>
                <form method="POST" action="{{ route('bookings.receipt.email', $booking) }}" style="margin:0"
                      onsubmit="return confirm('Email the {{ $docWord }} to {{ $recEmail ?: ($cover ? $cover['name'] : 'the customer') }}?')">
                    @csrf
                    <button type="submit" class="btn btn-dark" style="padding:8px 14px" @disabled(! $recEmail)>✉ Email {{ $cover ? 'to operator' : 'to customer' }}</button>
                </form>
                @if($cover && $waDigits)
                    <a href="https://wa.me/{{ $waDigits }}?text={{ $waText }}" target="_blank" rel="noopener" class="btn btn-ghost" style="padding:8px 14px;border-color:#25D366;color:#128C4A">🟢 WhatsApp</a>
                @endif
            </div>
            @if($cover)
                <p class="hint" style="margin:8px 0 0">WhatsApp opens a ready-typed message to {{ $cover['name'] }}@if($cover['phone']) ({{ $cover['phone'] }})@endif — attach the downloaded PDF in WhatsApp (wa.me can't carry the file itself).@unless($recEmail) No email saved for them — add one below to email the invoice.@endunless</p>
            @elseif(! $recEmail)
                <p class="hint" style="margin:8px 0 0;color:#8a6d00">No customer email on file — add one via Edit booking to enable emailing.</p>
            @endif

            {{-- Combine several bookings onto one invoice (e.g. an outbound + return
                 that came in as separate jobs, or a batch for one client). --}}
            @unless($cover)
                @php $invoiceGroup = $booking->invoiceGroupBookings()->reject(fn ($b) => $b->is($booking))->values(); @endphp
                <details class="card" style="margin:12px 0 0;background:rgba(0,0,0,.02)" @if($invoiceGroup->isNotEmpty()) open @endif>
                    <summary style="cursor:pointer;font-weight:600;font-size:13px">🧾 Bill more than one booking on this invoice</summary>
                    @if($invoiceGroup->isNotEmpty())
                        <p class="hint" style="margin:10px 0 6px">This invoice also includes:</p>
                        <ul style="margin:0 0 10px;padding-left:18px">
                            @foreach($invoiceGroup as $g)
                                <li style="font-size:13px;margin-bottom:4px">
                                    <a href="{{ route('bookings.show', $g) }}" style="font-weight:700">{{ $g->reference }}</a> — {{ $g->pickup_at?->format('D d M, H:i') }}, {{ $g->pickup_address }} → {{ $g->destination_address }}
                                    @if($g->fareGross() !== null) · £{{ number_format((float) $g->fareGross(), 2) }}@endif
                                    <form method="POST" action="{{ route('bookings.invoice.uncombine', ['booking' => $booking, 'other' => $g]) }}" style="display:inline;margin:0">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-ghost" style="padding:2px 8px;font-size:12px">Remove</button>
                                    </form>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    <form method="POST" action="{{ route('bookings.invoice.combine', $booking) }}" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:8px">
                        @csrf
                        <input type="text" name="reference" placeholder="Other booking reference (e.g. FLCCGSb)" style="padding:8px 10px;border:1px solid var(--line);border-radius:8px;font-size:13px">
                        <button class="btn btn-primary" style="padding:8px 14px;font-size:13px">Add to this invoice</button>
                    </form>
                    <p class="hint" style="margin:8px 0 0">The View/Download/Email invoice above will list every journey and total them. Invoice-only — it doesn't change rotation, the calendar or payments.</p>
                </details>
            @endunless

            {{-- Cover-job details: who we covered the job for, so we invoice them. --}}
            <details class="card" style="margin:12px 0 0;background:rgba(0,0,0,.02)" @if($cover) open @endif>
                <summary style="cursor:pointer;font-weight:600;font-size:13px">🤝 {{ $cover ? 'Edit cover-job details' : 'This was a cover job for another operator — invoice them' }}</summary>
                <form method="POST" action="{{ route('bookings.cover-for', $booking) }}" style="margin-top:10px">
                    @csrf
                    <div class="grid grid-2" style="gap:10px">
                        <label style="font-size:12px">Operator / company name
                            <input name="name" value="{{ $cover['name'] ?? '' }}" placeholder="e.g. A1 Cars Ltd" style="width:100%;padding:7px 9px;border:1px solid var(--line);border-radius:8px">
                        </label>
                        <label style="font-size:12px">Amount to invoice (£)
                            <input type="number" step="0.01" min="0" name="amount" value="{{ $cover['amount'] ?? '' }}" placeholder="defaults to the fare" style="width:100%;padding:7px 9px;border:1px solid var(--line);border-radius:8px">
                        </label>
                        <label style="font-size:12px">Their email <span class="muted">— for emailing the invoice</span>
                            <input type="email" name="email" value="{{ $cover['email'] ?? '' }}" placeholder="accounts@operator.co.uk" style="width:100%;padding:7px 9px;border:1px solid var(--line);border-radius:8px">
                        </label>
                        <label style="font-size:12px">Their WhatsApp / phone
                            <input type="tel" name="phone" value="{{ $cover['phone'] ?? '' }}" placeholder="07…" style="width:100%;padding:7px 9px;border:1px solid var(--line);border-radius:8px">
                        </label>
                    </div>
                    <div style="margin-top:10px">
                        <button type="submit" class="btn btn-primary" style="padding:7px 14px">{{ $cover ? 'Save cover-job details' : 'Mark as cover job' }}</button>
                        @if($cover)
                            <button type="submit" class="btn btn-ghost" style="padding:7px 14px;color:#b32020"
                                    onclick="this.form.name.value='';return confirm('Clear the cover-job details? This goes back to a normal customer booking.')">Clear — not a cover job</button>
                        @endif
                    </div>
                </form>
            </details>
        </div>
        @if(! $booking->status->isTerminal())
            <p class="hint" style="margin:-8px 0 16px">
                <strong>Copy summary</strong> puts this booking's key details (time, name, route, vehicle, flight, ref and price) on your clipboard — ready to paste into WhatsApp or an email in one tap.
            </p>
            @if(! $booking->linked_booking_id && ! $booking->is_return_leg)
                <div id="return-box" class="card" style="display:none;border-left:4px solid #111;margin-bottom:16px">
                    <form method="POST" action="{{ route('bookings.return-leg', $booking) }}">
                        @csrf
                        <strong>Create the return leg</strong>
                        <p class="hint" style="margin:6px 0 10px">Makes a new booking with pickup and drop-off swapped, same customer, vehicle and passengers. It's left unpriced and unallocated for you to price and assign. Nothing is pushed to Google Calendar automatically.</p>
                        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:end">
                            <div>
                                <label for="return_pickup_at" style="font-weight:600;display:block">Return pickup <span class="req">*</span></label>
                                <input id="return_pickup_at" type="datetime-local" name="return_pickup_at" required style="margin-top:4px">
                            </div>
                            <div>
                                <label for="return_flight" style="font-weight:600;display:block">Return flight (optional)</label>
                                <input id="return_flight" name="flight_number" placeholder="e.g. BA1235" style="margin-top:4px">
                            </div>
                            <button type="submit" class="btn btn-primary" style="padding:9px 16px">Create return leg</button>
                            <button type="button" class="btn btn-ghost" style="padding:9px 16px" onclick="document.getElementById('return-box').style.display='none'">Cancel</button>
                        </div>
                    </form>

                    <div style="border-top:1px solid var(--line);margin:14px 0 0;padding-top:12px">
                        <strong>…or match an existing return booking</strong>
                        <p class="hint" style="margin:6px 0 10px">If the return is already in the system as its own booking, enter its reference to pair them. The cash then combines on the outbound (earlier) leg — the outbound driver collects both legs' cash, the return reads "collect nothing".</p>
                        <form method="POST" action="{{ route('bookings.match-return', $booking) }}" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
                            @csrf
                            <input type="text" name="reference" placeholder="Other leg's reference (e.g. CET-XXXX)" required style="padding:9px 12px;border:1px solid var(--line);border-radius:8px">
                            <button type="submit" class="btn btn-dark" style="padding:9px 16px">🔗 Match as return pair</button>
                        </form>
                    </div>
                </div>
            @endif
            <div id="postpone-box" class="card" style="display:none;border-left:4px solid #FBBA2A;background:rgba(251,186,42,.08);margin-bottom:16px">
                <form method="POST" action="{{ route('bookings.postpone', $booking) }}">
                    @csrf
                    <strong>Postpone — hold for a reschedule</strong>
                    <p class="hint" style="margin:6px 0 10px">Use this when the journey is going ahead later (e.g. the flight was cancelled) and you'll rebook once there are new details. The booking and <strong>any payment are held</strong> — nothing is refunded. It drops off dispatch and the driver's list, its reminders stop, and the masked number closes. Reschedule it from here when the new time is known.</p>
                    <label for="postpone_reason" style="font-weight:600">Note (optional)</label>
                    <input id="postpone_reason" name="postpone_reason" placeholder="e.g. Flight cancelled — customer rebooking, payment held" style="margin:6px 0 10px" value="{{ old('postpone_reason') }}">
                    <button type="submit" class="btn btn-primary" style="padding:9px 16px">Postpone booking</button>
                    <button type="button" class="btn btn-ghost" style="padding:9px 16px" onclick="document.getElementById('postpone-box').style.display='none'">Keep it live</button>
                    <p class="hint" style="margin:8px 0 0">The Google Calendar event isn't removed automatically — take it off by hand if it was pushed there.</p>
                </form>
            </div>
            <div id="cancel-box" class="card" style="display:none;border-left:4px solid #b32020;background:rgba(179,32,32,.05);margin-bottom:16px">
                <form method="POST" action="{{ route('bookings.cancel', $booking) }}">
                    @csrf
                    <label for="cancellation_reason" style="font-weight:600">Reason for cancellation <span class="req">*</span></label>
                    <input id="cancellation_reason" name="cancellation_reason" required placeholder="e.g. Customer cancelled — no charge" style="margin:6px 0 10px">
                    <p class="hint" style="margin:0 0 10px">Cancelling calls the job off for good. If it's just moving to another day, use <strong>Postpone</strong> instead so the payment is held.</p>
                    <button type="submit" class="btn" style="background:#b32020;color:#fff;padding:9px 16px">Confirm cancellation</button>
                    <button type="button" class="btn btn-ghost" style="padding:9px 16px" onclick="document.getElementById('cancel-box').style.display='none'">Keep booking</button>
                    <p class="hint" style="margin:8px 0 0">The calendar event isn't removed automatically — take it off Google Calendar yourself if it was pushed there.</p>
                </form>
            </div>
        @endif
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
                            <option value="{{ $d->id }}" @selected($booking->driver_id === $d->id)>{{ $d->driverProfile?->callsign ?: $d->name }}{{ $d->nickname() ? ' · '.$d->nickname() : '' }}@if($reg) ({{ $reg }})@endif</option>
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

    {{-- Offer the job to a driver — a short brief with the vital info and the
         fare to the driver (their payroll pay, so the two always match). --}}
    @if(auth()->user()->isAdmin() && ! $booking->status->isTerminal())
        @php $offerMsg = $booking->driverOfferMessage(); @endphp
        <div class="card">
            <h2 style="margin:0 0 4px">📤 Offer this job to a driver</h2>
            <p class="hint" style="margin:0 0 10px">A short brief to send a driver so they can take the job. The <strong>Fare to you</strong> is the driver’s pay — set it below (same figure as Driver payroll) and it fills into the message automatically.</p>

            @if($booking->driverPay() === null)
                <div class="card" style="border-left:4px solid #b8860b;background:rgba(251,186,42,.12);margin:0 0 12px">
                    <strong>Set the fare to the driver</strong>
                    <p class="hint" style="margin:4px 0 8px">The message shows <strong>£____</strong> until this is set. It’s the same figure as the driver’s pay.</p>
                    <form method="POST" action="{{ route('bookings.payroll', $booking) }}" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                        @csrf
                        <input type="hidden" name="action" value="set">
                        <span style="font-weight:700">£</span>
                        <input type="number" step="0.01" min="0" name="amount" placeholder="130" required style="max-width:120px">
                        <button class="btn btn-primary" style="padding:8px 14px;font-size:14px">Set fare</button>
                    </form>
                </div>
            @endif

            <textarea id="offer-msg" readonly rows="10" onclick="this.select()" style="width:100%;font-family:inherit;font-size:14px;line-height:1.5;white-space:pre-wrap">{{ $offerMsg }}</textarea>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:10px">
                <button type="button" class="btn btn-primary" id="offer-copy" style="padding:9px 16px;font-size:14px">⧉ Copy message</button>
                <a href="https://wa.me/?text={{ rawurlencode($offerMsg) }}" target="_blank" rel="noopener" class="btn" style="background:#25D366;color:#fff;padding:9px 16px;font-size:14px">📲 Share on WhatsApp</a>
                <span id="offer-copy-done" class="hint" style="color:#1f8b4c"></span>
            </div>
        </div>
        <script>
            (function () {
                var btn = document.getElementById('offer-copy');
                if (!btn) { return; }
                btn.addEventListener('click', function () {
                    var ta = document.getElementById('offer-msg');
                    var done = document.getElementById('offer-copy-done');
                    var finish = function () { if (done) { done.textContent = '✓ Copied'; } };
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(ta.value).then(finish).catch(finish);
                    } else {
                        ta.select(); try { document.execCommand('copy'); } catch (e) {} finish();
                    }
                });
            })();
        </script>
    @endif

    {{-- Shareable driver link — its own card so it's easy to find. Send it to a
         cover driver and they get the full job page (details, cash to collect,
         masked number, navigate, status, live GPS) with no login. --}}
    @if(auth()->user()->isAdmin() && ! $booking->status->isTerminal())
        @php
            $driverLink = $booking->driverLinkUrl();
            $linkPhone = \App\Support\Phone::wa($booking->driverRealPhone());
            $linkRecipient = $booking->driverContactLabel();
            $linkMsg = $booking->driverLinkMessage();
        @endphp
        <div class="card">
            <h2 style="margin:0 0 4px">🔗 Driver link — no login @if($booking->hasExtraDrivers())<span class="muted" style="font-weight:400;font-size:13px">· Car 1 of {{ $booking->carCount() }}</span>@endif</h2>
            <p class="hint" style="margin:0 0 12px">Send this to the driver. It opens their full job sheet — details, cash to collect, the contact number, navigation and the status buttons — with live tracking, no account needed.</p>
            @if($booking->hasExtraDrivers())
                <div style="background:var(--panel,#f7f7f9);border:1px solid var(--line);border-radius:10px;padding:10px 12px;margin:0 0 12px">
                    <form method="POST" action="{{ route('bookings.extra-drivers.passengers', $booking) }}" style="display:flex;gap:8px;align-items:center;margin:0;flex-wrap:wrap">
                        @csrf
                        <input type="hidden" name="token" value="lead">
                        <label style="font-size:14px"><strong>👤 Passengers in this lead car:</strong></label>
                        <input type="number" min="0" max="60" name="passengers" value="{{ $booking->leadCarPassengersSet() ? $booking->leadCarPassengers() : '' }}" placeholder="{{ $booking->leadCarPassengers() }}" style="width:80px">
                        <button class="btn btn-light" style="padding:6px 12px;font-size:13px">Set</button>
                        <span class="hint" style="margin:0">
                            @if($booking->leadCarPassengersSet())set by hand · of {{ $booking->passengerCount() }} total@else auto: the {{ $booking->leadCarPassengers() }} left after the extra cars (of {{ $booking->passengerCount() }} total)@endif
                        </span>
                    </form>
                </div>
            @endif
            <input type="text" value="{{ $driverLink }}" readonly onclick="this.select()" style="font-size:12px;margin-bottom:10px">
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                <button type="button" class="btn btn-primary copy-link" data-link="{{ $driverLink }}" style="padding:9px 16px;font-size:14px">⧉ Copy link</button>
                @if($linkPhone)
                    <a href="https://wa.me/{{ $linkPhone }}?text={{ rawurlencode($linkMsg) }}" target="_blank" rel="noopener" class="btn" style="background:#25D366;color:#fff;padding:9px 16px;font-size:14px">📲 Send to driver</a>
                @endif
                <span class="copy-link-done hint" style="color:#1f8b4c"></span>
            </div>
            {{-- Show EXACTLY who the link will reach, so two same-named drivers
                 (e.g. two "Haseeb"s) can never be confused. --}}
            @if($linkPhone)
                <p class="hint" style="margin:8px 0 0">📲 Sends to <strong>{{ $linkRecipient ?? 'the driver' }}</strong> · {{ '+'.$linkPhone }}</p>
            @elseif($booking->driver_id)
                <div class="alert alert-error" style="margin:8px 0 0">⚠ <strong>{{ $booking->driver->name }}</strong> has no phone number saved — add one on their <a href="{{ route('users.edit', $booking->driver) }}">driver account</a> before sending. (We won’t guess a number.)</div>
            @endif
            <p class="hint" style="margin:10px 0 0">Anyone with this link can work the job — treat it like a key. It stops working once the job is completed or cancelled.</p>

            {{-- White-label toggle: send an UNBRANDED link to an outsourced driver. --}}
            <form method="POST" action="{{ route('bookings.driver-link-branding', $booking) }}" style="margin:12px 0 0;padding-top:12px;border-top:1px solid var(--line)">
                @csrf
                <input type="hidden" name="unbranded" value="{{ $booking->driverLinkUnbranded() ? '0' : '1' }}">
                <label style="display:flex;gap:8px;align-items:flex-start;font-size:14px;cursor:pointer">
                    <input type="checkbox" onchange="this.form.submit()" {{ $booking->driverLinkUnbranded() ? 'checked' : '' }} style="width:18px;height:18px;margin-top:1px">
                    <span><strong>Unbranded link (for outsourced drivers)</strong>
                    <span class="hint">Hides all Central Executive Transfers branding on the driver's page — for drivers that aren't ours.</span></span>
                </label>
            </form>
        </div>
    @endif

    {{-- All cars at a glance on a multi-car job: the lead PLUS every extra car,
         each with its own current stage + a progress strip, so the office can
         see who's arrived without opening each link. Each car updates from its
         own driver link independently. --}}
    @if(auth()->user()->isAdmin() && $booking->hasExtraDrivers())
        @php
            $stageSeq = [
                'allocated' => ['🧭', 'Allocated'],
                'accepted' => ['✅', 'Accepted'],
                'en_route' => ['🚗', 'On the way'],
                'arrived' => ['📍', 'Arrived'],
                'collected' => ['🧳', 'On board'],
                'complete' => ['🏁', 'Dropped off'],
            ];
            $stageKeys = array_keys($stageSeq);

            // Lead car milestone times from its status history.
            $leadStamps = [];
            foreach ($booking->statusHistory->sortBy('created_at') as $h) {
                if (isset($stageSeq[$h->to_status]) && ! isset($leadStamps[$h->to_status])) {
                    $leadStamps[$h->to_status] = $h->created_at;
                }
            }

            $allCars = [[
                'no' => 1,
                'name' => $booking->driver?->name ?? ($booking->meta['driver_details']['name'] ?? 'Lead driver'),
                'status' => $booking->status->value,
                'stamps' => $leadStamps,
            ]];
            foreach ($booking->extraDrivers() as $i => $d) {
                $stamps = [];
                foreach (($d['stamps'] ?? []) as $k => $iso) {
                    try { $stamps[$k] = \Illuminate\Support\Carbon::parse($iso); } catch (\Throwable) {}
                }
                $allCars[] = [
                    'no' => $i + 2,
                    'name' => $d['name'] ?? 'Driver',
                    'status' => $d['status'] ?? 'allocated',
                    'stamps' => $stamps,
                ];
            }

            $arrived = collect($allCars)->filter(fn ($c) => in_array($c['status'], ['arrived', 'collected', 'complete'], true))->count();
        @endphp
        <div class="card">
            <h2 style="margin:0 0 4px">🚦 All cars — live status</h2>
            <p class="hint" style="margin:0 0 12px"><strong>{{ $arrived }} of {{ count($allCars) }}</strong> {{ \Illuminate\Support\Str::plural('car', count($allCars)) }} at pickup or beyond. Each car updates from its own driver link.</p>
            @foreach($allCars as $car)
                @php
                    $curIdx = array_search($car['status'], $stageKeys, true);
                    $isOff = in_array($car['status'], ['cancelled', 'no_show', 'pending'], true);
                    [$cIcon, $cLabel] = $stageSeq[$car['status']] ?? ['•', ucfirst(str_replace('_', ' ', $car['status']))];
                    $atPickup = in_array($car['status'], ['arrived', 'collected', 'complete'], true);
                    $curStamp = $car['stamps'][$car['status']] ?? null;
                @endphp
                <div style="padding:10px 0;border-bottom:1px solid rgba(128,128,128,.12)">
                    <div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;align-items:baseline">
                        <strong style="font-size:14px">Car {{ $car['no'] }} — {{ $car['name'] }}</strong>
                        <span style="font-size:13px;{{ $atPickup ? 'color:#1f7a44;font-weight:700' : ($isOff ? 'color:var(--muted,#888)' : '') }}">
                            {{ $cIcon }} {{ $cLabel }}@if($curStamp) · {{ $curStamp->format('H:i') }}@endif
                        </span>
                    </div>
                    <div style="display:flex;gap:4px;margin-top:8px">
                        @foreach($stageKeys as $sk)
                            @php $reached = $curIdx !== false && ! $isOff && array_search($sk, $stageKeys, true) <= $curIdx; @endphp
                            <span title="{{ $stageSeq[$sk][1] }}{{ isset($car['stamps'][$sk]) ? ' · '.$car['stamps'][$sk]->format('d M H:i') : '' }}"
                                  style="flex:1;height:8px;border-radius:4px;background:{{ $reached ? '#1f9d55' : 'rgba(128,128,128,.18)' }}"></span>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Additional drivers for a MULTI-CAR job (e.g. a 3-car wedding). Each gets
         their own link and their own per-car status, tracked separately. Shown
         on completed jobs too — pay and mark-paid happen after the job runs. --}}
    @if(auth()->user()->isAdmin() && ($booking->hasExtraDrivers() || ! $booking->status->isTerminal()))
        @php $extraDrivers = $booking->extraDrivers(); @endphp
        <details id="extra-cars" class="card" style="scroll-margin-top:16px"{{ count($extraDrivers) ? ' open' : '' }}>
            <summary style="cursor:pointer;font-weight:700;font-size:17px">🚗 Extra cars — multi-car job <span class="muted" style="font-weight:400;font-size:13px">{{ count($extraDrivers) ? '— '.count($extraDrivers).' extra '.\Illuminate\Support\Str::plural('car', count($extraDrivers)) : '— add a car for a wedding / group job' }}</span></summary>
            <p class="hint" style="margin:12px 0 12px">For a job that needs more than one car, add each extra driver here. They get their own link and their own status buttons, so you can track each car separately. Extra drivers contact the customer via the office.</p>

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

                    {{-- How many passengers ride in THIS car (shown on the driver's own link). --}}
                    @php $carPax = $booking->extraDriverPassengers($d['token']); @endphp
                    <div style="border-top:1px solid var(--line);margin-top:10px;padding-top:10px">
                        <form method="POST" action="{{ route('bookings.extra-drivers.passengers', $booking) }}" style="display:flex;gap:6px;align-items:center;margin:0;flex-wrap:wrap">
                            @csrf
                            <input type="hidden" name="token" value="{{ $d['token'] }}">
                            <label style="font-size:13px"><strong>👤 Passengers in this car:</strong></label>
                            <input type="number" min="0" max="60" name="passengers" value="{{ $carPax }}" placeholder="—" style="width:80px">
                            <button class="btn btn-light" style="padding:6px 12px;font-size:13px">Set</button>
                            @if($carPax === null)<span class="hint" style="margin:0">not split — shows the whole party</span>@endif
                        </form>
                    </div>

                    {{-- This car's OWN pay — separate from the lead driver and the other cars. --}}
                    @php $carPay = $booking->extraDriverPay($d['token']); $carRemain = $booking->extraDriverPayRemaining($d['token']); @endphp
                    <div style="border-top:1px solid var(--line);margin-top:10px;padding-top:10px">
                        <div style="font-size:13px;margin-bottom:6px">
                            <strong>Pay:</strong>
                            @if($carPay === null)<span class="muted">not set yet</span>
                            @else £{{ number_format($carPay, 2) }} · <span style="color:#1f7a44">£{{ number_format($booking->extraDriverPaidAmount($d['token']), 2) }} paid</span> · @if($carRemain > 0)<strong style="color:#b8860b">£{{ number_format($carRemain, 2) }} owed</strong>@else<span class="badge badge-complete">Settled</span>@endif
                            @endif
                        </div>
                        <div style="display:flex;gap:8px;flex-wrap:wrap">
                            <form method="POST" action="{{ route('bookings.extra-drivers.payroll', $booking) }}" style="display:flex;gap:6px;margin:0">
                                @csrf
                                <input type="hidden" name="token" value="{{ $d['token'] }}"><input type="hidden" name="action" value="set">
                                <input type="number" step="0.01" min="0" name="amount" value="{{ $carPay }}" placeholder="Pay £" style="width:110px">
                                <button class="btn btn-light" style="padding:6px 12px;font-size:13px">Set pay</button>
                            </form>
                            @if(($carRemain ?? 0) > 0)
                                {{-- One tap: mark this car paid in full for the amount set. --}}
                                <form method="POST" action="{{ route('bookings.extra-drivers.payroll', $booking) }}" style="margin:0">
                                    @csrf
                                    <input type="hidden" name="token" value="{{ $d['token'] }}"><input type="hidden" name="action" value="record">
                                    <input type="hidden" name="amount" value="{{ number_format($carRemain, 2, '.', '') }}">
                                    <button class="btn btn-primary" style="padding:6px 12px;font-size:13px">✓ Mark paid (£{{ number_format($carRemain, 2) }})</button>
                                </form>
                                <form method="POST" action="{{ route('bookings.extra-drivers.payroll', $booking) }}" style="display:flex;gap:6px;margin:0">
                                    @csrf
                                    <input type="hidden" name="token" value="{{ $d['token'] }}"><input type="hidden" name="action" value="record">
                                    <input type="number" step="0.01" min="0" name="amount" value="{{ number_format($carRemain, 2, '.', '') }}" placeholder="Paid £" style="width:110px">
                                    <button class="btn btn-ghost" style="padding:6px 12px;font-size:13px">Part pay</button>
                                </form>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach

            <form method="POST" action="{{ route('bookings.extra-drivers.add', $booking) }}" style="margin-top:8px">
                @csrf
                <div class="field">
                    <label for="ex-driver-pick">Pick a driver <span class="muted">(or start typing the name below)</span></label>
                    <select id="ex-driver-pick">
                        <option value="">— Choose a saved driver, or type one below —</option>
                        @foreach($jobDrivers as $d)
                            <option value="{{ $loop->index }}"
                                data-name="{{ $d['name'] }}" data-phone="{{ $d['phone'] }}"
                                data-reg="{{ $d['reg'] }}" data-car="{{ $d['car'] }}">{{ $d['name'] }}@if($d['reg']) · {{ $d['reg'] }}@endif</option>
                        @endforeach
                    </select>
                </div>
                <div class="grid grid-2">
                    <div class="field">
                        <label for="ex-name">Driver name <span class="req">*</span></label>
                        <input id="ex-name" name="name" required placeholder="e.g. Sam Jones" list="ex-driver-names" autocomplete="off">
                        <datalist id="ex-driver-names">
                            @foreach($jobDrivers as $d)
                                <option value="{{ $d['name'] }}">@if($d['reg']){{ $d['reg'] }}@endif</option>
                            @endforeach
                        </datalist>
                    </div>
                    <div class="field"><label for="ex-phone">Phone <span class="muted">(for you to send the link)</span></label><input id="ex-phone" name="phone" placeholder="07…"></div>
                    <div class="field"><label for="ex-reg">Vehicle reg</label><input id="ex-reg" name="reg" style="text-transform:uppercase"></div>
                    <div class="field"><label for="ex-car">Make &amp; model</label><input id="ex-car" name="car" placeholder="e.g. Black Mercedes V-Class"></div>
                    <div class="field"><label for="ex-pax">Passengers in this car <span class="muted">(optional)</span></label><input id="ex-pax" name="passengers" type="number" min="0" max="60" placeholder="e.g. 4"></div>
                </div>
                <button class="btn btn-primary" style="padding:8px 16px;font-size:14px">＋ Add another car</button>
            </form>

            {{-- Match these cars onto ANOTHER booking (usually the return). Pick the
                 target — the customer's other bookings are listed (linked leg first),
                 each copied car gets its own link. --}}
            @if($booking->hasExtraDrivers() && $matchTargets->isNotEmpty())
                <div style="margin-top:14px;border-top:1px solid var(--line);padding-top:12px">
                    <form method="POST" action="{{ route('bookings.extra-drivers.copy', $booking) }}"
                          onsubmit="return confirm('Match these {{ count($booking->extraDrivers()) }} car(s) onto the booking you picked? Each gets its own link.')">
                        @csrf
                        <label for="match-target" style="font-weight:600;font-size:14px">↔ Match these cars to another booking</label>
                        <p class="hint" style="margin:4px 0 8px">e.g. the customer's return leg — the same drivers &amp; vehicles get copied over, each with its own link.</p>
                        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                            <select id="match-target" name="target_booking_id" required style="min-width:240px">
                                <option value="">— Choose the booking to match to —</option>
                                @foreach($matchTargets as $t)
                                    <option value="{{ $t->id }}">
                                        {{ $t->reference }}{{ $t->is_return_leg ? ' (return)' : '' }} · {{ $t->pickup_at?->format('D d M, H:i') }} · {{ \Illuminate\Support\Str::limit($t->pickup_address, 18) }} → {{ \Illuminate\Support\Str::limit($t->destination_address, 18) }}
                                    </option>
                                @endforeach
                            </select>
                            <button class="btn btn-primary" style="padding:8px 16px;font-size:14px">Match cars to this booking</button>
                        </div>
                    </form>
                </div>
            @endif
        </details>
    @endif

    @if($booking->isPostponed())
        <div class="card" style="border-left:4px solid #FBBA2A;background:rgba(251,186,42,.08);margin-bottom:16px">
            <strong>⏸ Postponed — awaiting reschedule.</strong>
            <span class="muted">{{ $booking->meta['postpone_reason'] ?? 'Held for a new date' }}@if(!empty($booking->meta['postponed_at'])) · {{ \Illuminate\Support\Carbon::parse($booking->meta['postponed_at'])->format('d M Y, H:i') }}@endif</span>
            <p class="hint" style="margin:6px 0 10px">The payment is held against this booking — nothing has been refunded. Enter the new pickup time (and flight, if any) to put it back on the board and carry the payment over. No new charge.</p>
            <form method="POST" action="{{ route('bookings.reschedule', $booking) }}" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end">
                @csrf
                <div>
                    <label for="reschedule_pickup_at" style="font-weight:600;display:block">New pickup <span class="req">*</span></label>
                    <input id="reschedule_pickup_at" type="datetime-local" name="pickup_at" required style="margin-top:4px"
                           value="{{ old('pickup_at', optional($booking->pickup_at)->format('Y-m-d\TH:i')) }}">
                </div>
                <div>
                    <label for="reschedule_flight" style="font-weight:600;display:block">Flight (optional)</label>
                    <input id="reschedule_flight" name="flight_number" placeholder="e.g. BA1234" style="margin-top:4px"
                           value="{{ old('flight_number', $booking->flight_number) }}">
                </div>
                <button type="submit" class="btn btn-primary" style="padding:9px 16px">Reschedule &amp; reinstate</button>
            </form>
            <p class="hint" style="margin:10px 0 0">Add the new event to Google Calendar yourself — the calendar is never changed automatically.</p>
        </div>
    @elseif($booking->status === \App\Enums\BookingStatus::Cancelled && ! empty($booking->meta['cancellation_reason']))
        <div class="alert alert-error">Cancelled — {{ $booking->meta['cancellation_reason'] }}
            @if(!empty($booking->meta['cancelled_at'])) <span class="muted">({{ \Illuminate\Support\Carbon::parse($booking->meta['cancelled_at'])->format('d M Y, H:i') }})</span>@endif
        </div>
    @endif

    {{-- The booking in full, in the confirmation format we read — title + the
         Booking Confirmation block (notes and all). The BOOKING RECORD is the
         source of truth: this is rendered LIVE from the booking (CalendarEventBuilder),
         so editing the booking updates it straight away and it can never show a
         stale Google Calendar snapshot from a different leg/date. The stored
         calendar text is only a fallback if the live render can't be built. --}}
    @php
        $calEvent = $booking->calendarEvent;
        $cal = null;
        if ($booking->pickup_at) {
            // Live from the booking's own current fields — always matches the record.
            try { $cal = app(\App\Services\CalendarEventBuilder::class)->preview($booking); } catch (\Throwable $e) { $cal = null; }
        }
        if ((! $cal || ! filled($cal['description'] ?? null)) && $calEvent && filled($calEvent->description)) {
            // Only if we couldn't build it live (e.g. no pickup time yet).
            $cal = ['title' => $calEvent->title, 'description' => $calEvent->description, 'location' => $calEvent->location];
        }
        // The times shown track the BOOKING, not a stale calendar start.
        $calStart = $booking->pickup_at ?? $calEvent?->start_at;
        $calEnd = $booking->pickup_at?->copy()->addHour() ?? $calEvent?->end_at;
    @endphp
    @if($cal && filled($cal['description'] ?? null))
        <div class="card cal-panel">
            <div class="cal-panel-head">
                <span>📅 Full details</span>
                <span class="muted" style="font-size:12px">{{ $calStart?->format('D d M') }} · {{ $calStart?->format('H:i') }} → {{ $calEnd?->format('H:i') }}</span>
            </div>
            <div class="cal-panel-title mono">{{ $cal['title'] }}</div>
            <div class="cal-panel-body">{{ str_replace('*', '', $cal['description']) }}</div>
            @if(auth()->user()->isAdmin())
                <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:10px">
                    @if($calEvent?->google_event_id)
                        <form method="POST" action="{{ route('bookings.sync-time', $booking) }}"
                              onsubmit="return confirm('Set this booking\'s pickup time to whatever is on the Google Calendar right now? The calendar itself is not changed.')">
                            @csrf
                            <button class="btn btn-ghost" style="padding:6px 14px;font-size:13px">🗓 Match time to calendar</button>
                        </form>
                    @endif
                    @if($calEvent)
                        <details>
                            <summary class="muted" style="font-size:12px;cursor:pointer">Calendar sync</summary>
                            <table style="margin-top:6px">
                                <tr><th>Location</th><td>{{ $calEvent->location }}</td></tr>
                                <tr><th>Sync</th><td>{{ ucfirst($calEvent->sync_status) }}{{ $calEvent->google_event_id ? ' · on Google Calendar' : '' }}</td></tr>
                            </table>
                        </details>
                    @else
                        <span class="hint" style="font-size:12px">Not on Google Calendar yet.</span>
                    @endif
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
                @foreach($booking->viaStops() as $i => $stop)
                    <tr><th>Via {{ $i + 1 }}</th><td>
                        🔀 {{ $stop }}
                        @php $sArr = $booking->stopArrivedAt($i); $sPob = $booking->stopPickedUpAt($i); @endphp
                        @if($sArr)
                            <div class="hint" style="margin-top:2px">
                                📍 Arrived {{ $sArr->format('H:i') }}@if($sPob) · 🧍 {{ $booking->stopActionVerb() }} {{ $sPob->format('H:i') }} · ⏱ waited {{ $booking->stopWaitLabel($i) }}@else · ⏱ waiting {{ $booking->stopWaitLabel($i) }}…@endif
                            </div>
                        @endif
                    </td></tr>
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
                @if($booking->estimatedDistanceMiles() !== null)<tr><th>Estimated distance</th><td>{{ number_format($booking->estimatedDistanceMiles(), 1) }} mi</td></tr>@endif
                @if($booking->estimatedDurationLabel())<tr><th>Estimated duration</th><td>{{ $booking->estimatedDurationLabel() }}</td></tr>@endif
                <tr><th>Passengers</th><td>{{ $booking->passengerCount() }}</td></tr>
                <tr><th>Luggage</th><td>{{ $booking->luggageBreakdown() }}</td></tr>
                <tr><th>Type</th><td>@if($booking->isHourlyHire())Hourly hire — {{ $booking->hourlyHireLabel() }}@else{{ ucfirst(str_replace('_',' ',$booking->journey_type)) }}{{ $booking->is_return_leg ? ' (return leg)' : '' }}@endif</td></tr>
                @if($booking->special_requests)<tr><th>Special requests</th><td style="white-space:pre-wrap">{!! \App\Support\NoteText::linkify($booking->special_requests) !!}</td></tr>@endif
                @if($booking->driverNotes())<tr><th>📝 Driver notes</th><td style="white-space:pre-wrap">{!! \App\Support\NoteText::linkify($booking->driverNotes()) !!}</td></tr>@endif
            </table>
        </div>

        <div class="card">
            <h2>Service &amp; Payment</h2>
            <table>
                <tr><th>Customer</th><td>@if($booking->customer && auth()->user()->isAdmin())<a href="{{ route('customers.show', $booking->customer) }}">{{ $booking->displayCustomerName() }}</a>@else{{ $booking->displayCustomerName() }}@endif</td></tr>
                @if($booking->displayContact())<tr><th>Contact</th><td>{{ $booking->displayContact() }}</td></tr>@endif
                <tr><th>Vehicle</th><td>{{ $booking->displayVehicleType() }}</td></tr>
                <tr><th>Driver</th><td>{{ ($booking->driver || !empty($booking->meta['driver_details']['name'])) ? $booking->driverLabel() : 'Awaiting allocation' }}</td></tr>
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
                @if($booking->depositAmount() > 0)
                    <tr><th>Deposit</th><td>£{{ number_format($booking->depositAmount(), 2) }} ·
                        @if($booking->depositPaidAmount() > 0)<span style="color:#1f7a44">paid</span>@else<span style="color:#b8860b">not paid</span>@endif
                        @php $bal = ($booking->fareAmount() ?? 0) - $booking->depositPaidAmount(); @endphp
                        @if($booking->fareAmount() !== null)<span class="muted">· balance £{{ number_format(max(0, $bal), 2) }}</span>@endif
                    </td></tr>
                @endif
                <tr><th>Passenger charge</th><td>{{ $booking->fareGross() !== null ? '£'.number_format($booking->fareGross(), 2) : '—' }}</td></tr>
                <tr><th>Driver income</th><td>{{ $booking->driverPay() !== null ? '£'.number_format($booking->driverPay(), 2) : '—' }}</td></tr>
                <tr id="booking-details" style="scroll-margin-top:16px"><th>Notification language</th><td>{{ $booking->notificationLanguage() }}</td></tr>
            </table>
            @if(auth()->user()->isAdmin())
                {{-- Preferred notification language — which language the confirmation /
                     reminder wording is written in for this customer. --}}
                <form method="POST" action="{{ route('bookings.notification-language', $booking) }}" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;margin-top:12px;padding-top:12px;border-top:1px solid rgba(128,128,128,.15)">
                    @csrf
                    <div class="field" style="margin:0">
                        <label for="notification_language" style="font-size:12px">Preferred notification language</label>
                        @php $langs = ['English','Polski','Română','Українська','اردو','العربية','Français','Español','Deutsch','Português','Italiano','中文','हिन्दी']; @endphp
                        <select id="notification_language" name="notification_language" style="width:180px">
                            @foreach($langs as $lang)<option value="{{ $lang }}" @selected($booking->notificationLanguage() === $lang)>{{ $lang }}</option>@endforeach
                        </select>
                    </div>
                    <button class="btn btn-light" style="padding:8px 14px;font-size:13px">Save language</button>
                </form>
            @endif
            @if(auth()->user()->isAdmin() && $booking->payment_status !== 'paid' && ! $booking->status->isTerminal())
                <form method="POST" action="{{ route('payments.paid', $booking) }}" style="margin-top:8px">@csrf
                    <button class="btn btn-primary" style="padding:6px 14px;font-size:13px">Mark paid</button>
                </form>
            @endif

            @if(auth()->user()->isAdmin())
                {{-- Quick price fix — set the job's final price without opening the
                     full edit form (e.g. a job that cost more in cover than we
                     charged). This is what fareAmount() uses. --}}
                <form method="POST" action="{{ route('bookings.price', $booking) }}" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;margin-top:12px;padding-top:12px;border-top:1px solid rgba(128,128,128,.15)">
                    @csrf
                    <div class="field" style="margin:0">
                        <label for="final_price" style="font-size:12px">Job price (£)</label>
                        <input id="final_price" name="final_price" type="number" step="0.01" min="0" inputmode="decimal"
                               value="{{ $booking->fareAmount() !== null ? number_format($booking->fareAmount(), 2, '.', '') : '' }}" style="width:130px">
                    </div>
                    <button class="btn btn-light" style="padding:8px 14px;font-size:13px">Set price</button>
                    <span class="hint" style="flex-basis:100%;margin:2px 0 0">What the customer is charged. Blank clears it back to the quote.</span>
                </form>

                {{-- Waiting time override — editable any time, even after completion.
                     Fixes a runaway charge when a driver forgot to progress the job. --}}
                @php $wMin = $booking->waitingChargeableMinutes(); @endphp
                <form method="POST" action="{{ route('bookings.waiting', $booking) }}" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;margin-top:12px;padding-top:12px;border-top:1px solid rgba(128,128,128,.15)">
                    @csrf
                    <div class="field" style="margin:0">
                        <label for="waiting_minutes" style="font-size:12px">Billable waiting (min)</label>
                        <input id="waiting_minutes" name="waiting_minutes" type="number" step="1" min="0" inputmode="numeric"
                               value="{{ $booking->recordedWaitingMinutes() ?? $wMin }}" style="width:130px">
                    </div>
                    <button class="btn btn-light" style="padding:8px 14px;font-size:13px">Set waiting</button>
                    <button type="submit" name="clear" value="1" class="btn btn-ghost" style="padding:8px 14px;font-size:13px"
                            onclick="return confirm('Remove the waiting charge for this job?')">No waiting charge</button>
                    <span class="hint" style="flex-basis:100%;margin:2px 0 0">Currently {{ $wMin }} chargeable min = <strong>£{{ number_format($booking->waitingCharge(), 2) }}</strong>@if($booking->hasViaStops() && $booking->stopsWaitingBillableMinutes() > 0) — includes <strong>{{ $booking->stopsWaitingBillableMinutes() }} min</strong> across the stops (each counted separately)@endif. Use <strong>No waiting charge</strong> if the driver forgot to update the job.</span>
                </form>
            @endif
        </div>
    </div>

    @if(auth()->user()->isAdmin())
        @php
            $pay = $booking->driverPay(); $paid = $booking->driverPaidAmount(); $left = $booking->driverPayRemaining();
            // The card tip is part of what the driver earns for this job, so it
            // rolls into what's owed and what "Mark paid in full" settles.
            $tipOwed = $booking->cardTipsRemaining();
            $jobLeft = $booking->driverJobRemaining();
        @endphp
        <div id="payroll" class="card" style="scroll-margin-top:16px;{{ ($left !== null && $left > 0) ? 'border-left:4px solid #FBBA2A' : '' }}">
            <h2>Driver payroll — {{ $booking->driverLabel() }}</h2>
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
                    @if($tipOwed > 0)<tr><th>Card tip</th><td>+ £{{ number_format($tipOwed, 2) }} <span class="muted">(owed to driver)</span></td></tr>@endif
                    <tr><th>Paid so far</th><td>£{{ number_format($paid + $booking->cardTipsPaid(), 2) }}</td></tr>
                    <tr><th>Remaining</th><td>
                        @if($jobLeft > 0)
                            <strong style="color:#b8860b">£{{ number_format($jobLeft, 2) }} owed</strong>
                            @if($tipOwed > 0 && $left > 0)<span class="muted"> · £{{ number_format($left, 2) }} pay + £{{ number_format($tipOwed, 2) }} tip</span>@elseif($tipOwed > 0)<span class="muted"> · card tip</span>@endif
                        @else
                            <span class="badge badge-complete">Paid in full</span>
                        @endif
                    </td></tr>
                </table>
            @endif

            <div style="display:flex;gap:18px;flex-wrap:wrap;margin-top:12px">
                @php $suggestedPay = $booking->suggestedDriverPay(); $payPrefill = $pay ?? $suggestedPay; @endphp
                <form method="POST" action="{{ route('bookings.payroll', $booking) }}" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap">
                    @csrf
                    <input type="hidden" name="action" value="set">
                    <div class="field" style="margin:0">
                        <label for="pay-amount" style="font-size:12px">Job pays the driver (£)</label>
                        <input id="pay-amount" name="amount" type="number" step="0.01" min="0"
                               value="{{ $payPrefill !== null ? number_format($payPrefill, 2, '.', '') : '' }}" required style="width:130px">
                    </div>
                    <button class="btn {{ $pay === null && $suggestedPay !== null ? 'btn-primary' : 'btn-ghost' }}" style="padding:8px 14px;font-size:13px">{{ $pay === null && $suggestedPay !== null ? '✓ Confirm pay' : 'Set pay' }}</button>
                    @if($pay === null && $suggestedPay !== null)
                        <span class="hint" style="flex-basis:100%;margin:2px 0 0">Standard {{ rtrim(rtrim(number_format($booking->driverPayPercent(), 1), '0'), '.') }}% of £{{ number_format($booking->fareAmount(), 2) }} = <strong>£{{ number_format($suggestedPay, 2) }}</strong> — click Confirm, or type a different figure.</span>
                    @endif
                </form>

                @if($jobLeft > 0)
                    {{-- One tap: settle everything owed for the job — pay AND the card tip. --}}
                    <form method="POST" action="{{ route('bookings.payroll', $booking) }}" style="align-self:end;margin:0">
                        @csrf
                        <input type="hidden" name="action" value="mark_paid">
                        <button class="btn btn-primary" style="padding:8px 16px;font-size:14px">✓ Mark paid in full (£{{ number_format($jobLeft, 2) }})</button>
                    </form>
                @endif

                @if($pay !== null && $left > 0)
                    <details style="align-self:end">
                        <summary class="muted" style="cursor:pointer;font-size:12px;padding:8px 0">Pay only part of it?</summary>
                        <form method="POST" action="{{ route('bookings.payroll', $booking) }}" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;margin-top:6px">
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
                            <button class="btn btn-ghost" style="padding:8px 14px;font-size:13px">✓ Record paid</button>
                        </form>
                    </details>
                @endif
            </div>
            @endif {{-- driverSettledByCustomer --}}

            @if($booking->driverPayHistory() !== [])
                <details style="margin-top:10px">
                    <summary class="muted" style="font-size:12px;cursor:pointer">Payment history</summary>
                    @foreach(array_reverse($booking->driverPayHistory(), true) as $i => $h)
                        <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;font-size:13px;padding:4px 0;border-bottom:1px solid rgba(128,128,128,.1)">
                            <span>£{{ number_format($h['amount'], 2) }}
                                <span class="muted">· {{ \Illuminate\Support\Carbon::parse($h['at'])->format('D d M Y, H:i') }}@if(!empty($h['by'])) · by {{ $h['by'] }}@endif
                                    @if(!empty($h['note'])) · {{ $h['note'] }}@endif</span>
                            </span>
                            {{-- Undo a payment recorded by mistake (driver wasn't actually paid).
                                 Reverses just this entry; the driver is owed it again. --}}
                            <form method="POST" action="{{ route('bookings.payroll', $booking) }}" style="margin:0"
                                  onsubmit="return confirm('Revert this £{{ number_format($h['amount'], 2) }} payment? {{ $booking->payrollDriverName() }} will be owed it again.')">
                                @csrf
                                <input type="hidden" name="action" value="undo_payment">
                                <input type="hidden" name="index" value="{{ $i }}">
                                <button class="btn btn-ghost" style="padding:4px 10px;font-size:12px;color:#b8323b;flex:none">Undo</button>
                            </form>
                        </div>
                    @endforeach
                </details>
            @endif

            {{-- Pay this job's fee to someone else (e.g. Kash supplied the driver).
                 The job then folds into that payee's payroll card and total. --}}
            <details style="margin-top:12px;border-top:1px solid var(--line);padding-top:10px"{{ $booking->payTo() ? ' open' : '' }}>
                <summary style="cursor:pointer;font-size:13px;font-weight:600">
                    💸 Pay this job to someone else
                    @if($booking->payTo())<span class="badge" style="background:#5b2bc7;color:#fff;font-size:10px">→ {{ $booking->payTo() }}</span>@endif
                </summary>
                <p class="hint" style="margin:8px 0 8px">When another driver supplied the driver for this job, pay them instead. This job's pay moves into their payroll total; the driver who drove it won't be owed it separately.</p>
                <form method="POST" action="{{ route('bookings.payroll', $booking) }}" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap">
                    @csrf
                    <input type="hidden" name="action" value="set_payee">
                    <div class="field" style="margin:0">
                        <label for="payee-pick" style="font-size:12px">Pick a driver</label>
                        <select id="payee-pick" style="width:200px">
                            <option value="">— Choose a driver —</option>
                            @php $seenPayee = []; @endphp
                            @foreach(($jobDrivers ?? []) as $d)
                                @if($d['name'] && ! in_array(strtolower($d['name']), $seenPayee, true))
                                    @php $seenPayee[] = strtolower($d['name']); @endphp
                                    <option value="{{ $d['name'] }}" @selected(($booking->meta['pay_to'] ?? '') === $d['name'])>{{ $d['name'] }}</option>
                                @endif
                            @endforeach
                        </select>
                    </div>
                    <div class="field" style="margin:0">
                        <label for="payee" style="font-size:12px">…or type a name</label>
                        <input id="payee" name="payee" value="{{ $booking->meta['pay_to'] ?? '' }}" style="width:180px" autocomplete="off">
                    </div>
                    <button class="btn btn-light" style="padding:8px 14px;font-size:13px">Save</button>
                </form>
                <script>
                    (function () {
                        var pick = document.getElementById('payee-pick');
                        var input = document.getElementById('payee');
                        if (pick && input) {
                            pick.addEventListener('change', function () { if (pick.value) input.value = pick.value; });
                        }
                    })();
                </script>
                @if($booking->payTo())
                    <form method="POST" action="{{ route('bookings.payroll', $booking) }}" style="margin-top:8px">
                        @csrf
                        <input type="hidden" name="action" value="set_payee">
                        <input type="hidden" name="payee" value="">
                        <button class="btn btn-ghost" style="padding:6px 12px;font-size:12px;color:var(--red)">Clear — pay the driver directly</button>
                    </form>
                @endif
            </details>

            {{-- Tips — the driver's gratuity, on top of job pay. --}}
            @php $tips = $booking->tipsTotal(); @endphp
            <div style="margin-top:14px;padding-top:12px;border-top:1px solid rgba(128,128,128,.15)">
                <strong style="font-size:14px">💛 Tips for {{ $booking->driverLabel() }}</strong>
                @if(app(\App\Services\Payments\SquareTipService::class)->enabled())
                    @php
                        $tipMsg = app(\App\Services\Messaging\BookingNotifier::class)->tipRequestBody($booking);
                        $tipWa = \App\Support\Phone::wa($booking->customerContactNumber() ?? $booking->customer?->phone);
                    @endphp
                    <div class="hint" style="margin:4px 0 6px">Customer card-tip link: <a href="{{ $booking->tipUrl() }}" target="_blank" rel="noopener">{{ $booking->tipUrl() }}</a> <span class="muted">(100% goes to the driver)</span></div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;margin:6px 0">
                        @if(filled($tipWa))
                            <a href="https://wa.me/{{ $tipWa }}?text={{ rawurlencode($tipMsg) }}" target="_blank" rel="noopener" class="btn" style="background:#25D366;color:#fff;padding:7px 14px;font-size:13px">📲 Send tip link</a>
                        @endif
                        <button type="button" class="btn btn-ghost" style="padding:7px 14px;font-size:13px" onclick="navigator.clipboard.writeText(@js($tipMsg));this.textContent='✓ Copied'">Copy message</button>
                    </div>
                @endif
                @if($booking->customerCashTipNotedAt())
                    <div class="hint" style="margin:4px 0 6px;color:#b8860b">💷 The customer said they left a cash tip ({{ $booking->customerCashTipNotedAt()->format('D d M, H:i') }}). Confirm the amount with {{ $booking->driverPublicName() ?: 'the driver' }} and log it below if you want it on payroll.</div>
                @endif
                @if($tips > 0)
                    <span class="muted" style="font-size:13px"> · £{{ number_format($tips, 2) }} total @if($booking->tipsTotalBy('cash') > 0 && $booking->cardTipsOwed() > 0)(£{{ number_format($booking->tipsTotalBy('cash'), 2) }} cash · £{{ number_format($booking->cardTipsOwed(), 2) }} card)@endif</span>
                    @if($booking->cardTipsRemaining() > 0)
                        <div class="hint" style="margin:4px 0 0">£{{ number_format($booking->cardTipsRemaining(), 2) }} card tip owed to the driver — collected by the company, included in what’s owed above.</div>
                    @elseif($booking->cardTipsOwed() > 0)
                        <div class="hint" style="margin:4px 0 0;color:#1f7a44">✓ £{{ number_format($booking->cardTipsOwed(), 2) }} card tip paid to the driver.</div>
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


    @if(auth()->user()->isAdmin())
        @php
            $txns = $booking->payments->sortByDesc('id');
            $fareGross = $booking->fareGross();
            $payableTotal = $booking->amountPayable();
            $vatOnTop = $booking->vatInvoiceRequested() ? round(($payableTotal ?? 0) - ($fareGross ?? 0), 2) : 0.0;
            $paidTotal = $booking->transactionsPaidTotal();
            $amountDue = $booking->transactionsAmountDue();
            $txStatuses = ['pending' => 'Pending', 'link_sent' => 'Link sent', 'paid' => 'Paid', 'balance_remaining' => 'Balance remaining', 'failed' => 'Failed', 'refunded' => 'Refunded'];
            $badgeFor = fn ($s) => $s === 'paid' ? 'complete' : ($s === 'failed' || $s === 'refunded' ? 'cancelled' : 'pending');
        @endphp
        <div id="transactions" class="card" style="scroll-margin-top:16px">
            <h2 style="margin:0 0 4px">💳 Payment history</h2>
            <p class="hint" style="margin:0 0 12px">Every payment on this booking. Add a deposit or balance, send a card payment link, or mark a transaction paid.</p>

            @if(session('copy_link'))
                <div class="card" style="border-left:4px solid #1f7a44;background:rgba(31,122,68,.07);margin:0 0 12px;padding:10px 14px">
                    <div style="font-weight:700;margin-bottom:6px">💳 Square payment link ready</div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap">
                        @if(session('share_wa'))
                            <a href="{{ session('share_wa') }}" target="_blank" rel="noopener" class="btn btn-primary" style="padding:7px 14px;font-size:13px">📲 Open WhatsApp with the link</a>
                        @endif
                        @if(session('sms_link'))
                            <a href="{{ session('sms_link') }}" class="btn btn-primary" style="padding:7px 14px;font-size:13px">💬 Open SMS with the link</a>
                        @endif
                        <button type="button" class="btn btn-ghost copy-link" data-link="{{ session('copy_link') }}" style="padding:7px 14px;font-size:13px">⧉ Copy link</button>
                        <a href="{{ session('copy_link') }}" target="_blank" rel="noopener" class="btn btn-ghost" style="padding:7px 14px;font-size:13px">↗ Open link</a>
                        <span class="copy-link-done hint" style="color:#1f8b4c"></span>
                    </div>
                </div>
            @endif

            {{-- Totals (ETO-style). Total is VAT-inclusive when a VAT invoice is on. --}}
            <div style="display:flex;gap:18px;flex-wrap:wrap;margin-bottom:12px">
                <div>
                    <div class="muted" style="font-size:12px">Total{{ $vatOnTop > 0 ? ' (incl. VAT)' : '' }}</div>
                    <div style="font-weight:800;font-size:18px">£{{ number_format($payableTotal ?? 0, 2) }}</div>
                    @if($vatOnTop > 0)<div class="muted" style="font-size:11px">£{{ number_format($fareGross ?? 0, 2) }} + £{{ number_format($vatOnTop, 2) }} VAT</div>@endif
                </div>
                <div><div class="muted" style="font-size:12px">Paid</div><div style="font-weight:800;font-size:18px;color:#1f7a44">£{{ number_format($paidTotal, 2) }}</div></div>
                <div><div class="muted" style="font-size:12px">Amount due</div><div style="font-weight:800;font-size:18px;{{ ($amountDue ?? 0) > 0 ? 'color:#b8860b' : '' }}">£{{ number_format($amountDue ?? 0, 2) }}</div></div>
            </div>

            @if($txns->isNotEmpty())
                <div style="overflow-x:auto">
                <table style="width:100%">
                    <thead><tr><th>Name</th><th>Amount</th><th>Charge</th><th>Method</th><th>Status</th><th style="text-align:right">Actions</th></tr></thead>
                    <tbody>
                    @foreach($txns as $payment)
                        <tr>
                            <td>{{ $payment->name() }}<div class="muted" style="font-size:11px">{{ $payment->updated_at?->format('d/m/Y H:i') }}</div></td>
                            <td>£{{ number_format((float) $payment->amount, 2) }}</td>
                            <td>£{{ number_format($payment->charge(), 2) }}</td>
                            <td>{{ $payment->methodLabel() }}</td>
                            <td><span class="badge badge-{{ $badgeFor($payment->status) }}">{{ $payment->statusLabel() }}</span></td>
                            <td style="text-align:right;white-space:nowrap">
                                <details style="display:inline-block;position:relative">
                                    <summary class="btn btn-ghost" style="padding:4px 10px;font-size:12px;list-style:none;cursor:pointer">Actions ▾</summary>
                                    <div style="position:absolute;right:0;z-index:20;background:#fff;border:1px solid rgba(0,0,0,.15);border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.15);padding:8px;min-width:210px;text-align:left">
                                        @if(! $payment->isPaid())
                                            <form method="POST" action="{{ route('bookings.transactions.pay-now', [$booking, $payment]) }}">@csrf
                                                <button class="txn-menu-item" style="color:#1f7a44">✓ Pay now (mark paid)</button>
                                            </form>
                                        @endif
                                        <form method="POST" action="{{ route('bookings.transactions.send-link', [$booking, $payment]) }}">@csrf
                                            <input type="hidden" name="channel" value="whatsapp">
                                            <button class="txn-menu-item" style="color:#1f7a44">📲 Card link via WhatsApp</button>
                                        </form>
                                        <form method="POST" action="{{ route('bookings.transactions.send-link', [$booking, $payment]) }}">@csrf
                                            <input type="hidden" name="channel" value="sms">
                                            <button class="txn-menu-item">💬 Card link via SMS</button>
                                        </form>
                                        <form method="POST" action="{{ route('bookings.transactions.send-link', [$booking, $payment]) }}">@csrf
                                            <input type="hidden" name="channel" value="email">
                                            <button class="txn-menu-item">✉ Card link by email</button>
                                        </form>
                                        <form method="POST" action="{{ route('bookings.transactions.send-link', [$booking, $payment]) }}">@csrf
                                            <input type="hidden" name="channel" value="copy">
                                            <button class="txn-menu-item">⧉ Create &amp; copy card link</button>
                                        </form>
                                        <button type="button" class="txn-menu-item txn-edit-toggle" data-target="txn-edit-{{ $payment->id }}">✎ Edit</button>
                                        <form method="POST" action="{{ route('bookings.transactions.duplicate', [$booking, $payment]) }}">@csrf
                                            <button class="txn-menu-item">⧉ Duplicate</button>
                                        </form>
                                        <form method="POST" action="{{ route('bookings.transactions.destroy', [$booking, $payment]) }}" onsubmit="return confirm('Delete this transaction?')">@csrf @method('DELETE')
                                            <button class="txn-menu-item" style="color:#b32020">🗑 Delete</button>
                                        </form>
                                    </div>
                                </details>
                                @if($payment->tide_payment_link)
                                    <a href="{{ $payment->tide_payment_link }}" target="_blank" rel="noopener" class="muted" style="display:block;font-size:11px;margin-top:4px">payment link ↗</a>
                                @endif
                            </td>
                        </tr>
                        <tr id="txn-edit-{{ $payment->id }}" hidden><td colspan="6" style="background:rgba(128,128,128,.05)">
                            <form method="POST" action="{{ route('bookings.transactions.update', [$booking, $payment]) }}" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;padding:6px 0">
                                @csrf @method('PUT')
                                <div class="field" style="margin:0"><label style="font-size:12px">Name</label><input name="name" value="{{ $payment->name() }}" style="width:130px"></div>
                                <div class="field" style="margin:0"><label style="font-size:12px">Amount (£)</label><input name="amount" type="number" step="0.01" min="0" value="{{ number_format((float) $payment->amount, 2, '.', '') }}" style="width:100px"></div>
                                <div class="field" style="margin:0"><label style="font-size:12px">Charge (£)</label><input name="charge" type="number" step="0.01" min="0" value="{{ $payment->charge() ? number_format($payment->charge(), 2, '.', '') : '' }}" style="width:90px"></div>
                                <div class="field" style="margin:0"><label style="font-size:12px">Method</label><select name="method" style="width:120px">
                                    @foreach(['card' => 'Card / Square', 'cash' => 'Cash', 'account' => 'Account'] as $mv => $ml)<option value="{{ $mv }}" @selected($payment->method === $mv)>{{ $ml }}</option>@endforeach
                                </select></div>
                                <div class="field" style="margin:0"><label style="font-size:12px">Status</label><select name="status" style="width:150px">
                                    @foreach($txStatuses as $sv => $sl)<option value="{{ $sv }}" @selected($payment->status === $sv)>{{ $sl }}</option>@endforeach
                                </select></div>
                                <button class="btn btn-primary" style="padding:8px 14px;font-size:13px">Save</button>
                            </form>
                        </td></tr>
                    @endforeach
                    </tbody>
                </table>
                </div>
            @elseif($booking->fareIsPaid() && $fareGross)
                {{-- No itemised ledger rows, but the fare is already paid (e.g. online
                     via Square, recorded on the booking) — show it so it never reads
                     as £0/unpaid. --}}
                @php
                    $sqRef = $booking->meta['square_payment']['id'] ?? null;
                    $paidMethod = $sqRef ? 'Card / Square' : ucfirst($booking->payment_method?->value ?? 'card');
                    $paidNote = 'Recorded on the booking'.($sqRef ? ' (Square)' : '').'. Add a transaction below only if you need to split it into a deposit + balance.';
                @endphp
                <table style="width:100%">
                    <thead><tr><th>Name</th><th>Amount</th><th>Method</th><th>Status</th></tr></thead>
                    <tbody><tr>
                        <td>Full amount</td>
                        <td>£{{ number_format($fareGross, 2) }}</td>
                        <td>{{ $paidMethod }}</td>
                        <td><span class="badge badge-complete">Paid</span></td>
                    </tr></tbody>
                </table>
                <p class="hint" style="margin:6px 0 0">{{ $paidNote }}</p>
            @else
                <p class="muted" style="margin:0 0 10px">No transactions recorded yet.</p>
            @endif

            {{-- Add a new transaction. --}}
            <details style="margin-top:12px" {{ $errors->any() && old('amount') ? 'open' : '' }}>
                <summary class="btn btn-light" style="padding:8px 14px;font-size:13px;display:inline-block;cursor:pointer">＋ Add new transaction</summary>
                <form method="POST" action="{{ route('bookings.transactions.store', $booking) }}" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin-top:12px">
                    @csrf
                    <div class="field" style="margin:0"><label style="font-size:12px">Name</label><input name="name" placeholder="Full amount" value="{{ old('name', 'Full amount') }}" style="width:130px"></div>
                    <div class="field" style="margin:0"><label style="font-size:12px">Amount (£)</label><input name="amount" type="number" step="0.01" min="0" required value="{{ old('amount', $amountDue !== null ? number_format($amountDue, 2, '.', '') : '') }}" style="width:100px"></div>
                    <div class="field" style="margin:0"><label style="font-size:12px">Charge (£)</label><input name="charge" type="number" step="0.01" min="0" value="{{ old('charge') }}" style="width:90px"></div>
                    <div class="field" style="margin:0"><label style="font-size:12px">Method</label><select name="method" style="width:120px">
                        @foreach(['card' => 'Card / Square', 'cash' => 'Cash', 'account' => 'Account'] as $mv => $ml)<option value="{{ $mv }}" @selected(old('method', $booking->payment_method?->value) === $mv)>{{ $ml }}</option>@endforeach
                    </select></div>
                    <div class="field" style="margin:0"><label style="font-size:12px">Status</label><select name="status" style="width:150px">
                        @foreach($txStatuses as $sv => $sl)<option value="{{ $sv }}" @selected(old('status', 'pending') === $sv)>{{ $sl }}</option>@endforeach
                    </select></div>
                    <button class="btn btn-primary" style="padding:8px 14px;font-size:13px">Add transaction</button>
                    {{-- One tap: record the charge AND create the Square card link, then
                         hand you WhatsApp/SMS/copy to send it (ETO-style). Card only. --}}
                    <button class="btn btn-dark" style="padding:8px 14px;font-size:13px" name="create_link" value="whatsapp"
                            title="Adds the charge and creates a Square card payment link to send">＋ Add &amp; create card link</button>
                </form>
            </details>
            <p class="hint" style="margin:12px 0 0"><strong>Add transaction</strong> just records a line. To take a card payment, use <strong>Add &amp; create card link</strong> (or a row's <strong>Actions → Card link</strong>) — that makes a real Square checkout link you send by WhatsApp, SMS, email or copy. These transactions include one-way and return bookings.</p>
        </div>
    @endif

    @if(auth()->user()->isAdmin())
        @php $bookingFiles = collect($booking->meta['files'] ?? [])->sortByDesc('at'); @endphp
        <div id="files" class="card" style="scroll-margin-top:16px">
            <h2 style="margin:0 0 4px">📎 Additional files</h2>
            <p class="hint" style="margin:0 0 12px">Attach documents to this booking — flight confirmations, meet-and-greet notes, receipts. Only the office can see or download them.</p>

            @if($bookingFiles->isNotEmpty())
                <table style="width:100%">
                    <thead><tr><th>File</th><th>Size</th><th>Added</th><th style="text-align:right"></th></tr></thead>
                    <tbody>
                    @foreach($bookingFiles as $f)
                        <tr>
                            <td>
                                <a href="{{ route('bookings.files.download', [$booking, $f['id']]) }}">{{ $f['label'] ?? $f['name'] }}</a>
                                @if($f['label'])<div class="muted" style="font-size:11px">{{ $f['name'] }}</div>@endif
                            </td>
                            <td>{{ isset($f['size']) ? number_format($f['size'] / 1024, 0).' KB' : '—' }}</td>
                            <td style="font-size:12px" class="muted">{{ isset($f['at']) ? \Illuminate\Support\Carbon::parse($f['at'])->format('d/m/Y H:i') : '' }}@if(!empty($f['by'])) · {{ $f['by'] }}@endif</td>
                            <td style="text-align:right">
                                <form method="POST" action="{{ route('bookings.files.destroy', [$booking, $f['id']]) }}" onsubmit="return confirm('Delete this file?')">@csrf @method('DELETE')
                                    <button class="btn btn-ghost" style="padding:4px 10px;font-size:12px;color:#b32020">🗑 Delete</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @else
                <p class="muted" style="margin:0 0 10px">No files attached yet.</p>
            @endif

            <form method="POST" action="{{ route('bookings.files.store', $booking) }}" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin-top:12px">
                @csrf
                <div class="field" style="margin:0"><label for="file-upload" style="font-size:12px">New file</label><input id="file-upload" type="file" name="file" required></div>
                <div class="field" style="margin:0"><label for="file-label" style="font-size:12px">Label <span class="muted">(optional)</span></label><input id="file-label" name="label" placeholder="e.g. Flight confirmation" style="width:200px"></div>
                <button class="btn btn-light" style="padding:8px 14px;font-size:13px">＋ Add file</button>
            </form>
            <p class="hint" style="margin:8px 0 0">Up to 10 MB — PDF, images, Word/Excel, CSV, text or .eml.</p>
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
        {{-- ETO-style "Send a notification": pick a template, recipient and channel. --}}
        <div id="send-notification" class="card" style="scroll-margin-top:16px">
            <h2 style="margin:0 0 4px">🔔 Send a notification</h2>
            <p class="hint" style="margin:0 0 12px">Pick a ready-made message, who it goes to and how — then tap to send it by hand (WhatsApp / SMS / email). Nothing is sent automatically.</p>

            @if(session('notify_link'))
                @php
                    $nch = session('notify_channel');
                    $nLabel = $nch === 'whatsapp' ? '📲 Open WhatsApp' : ($nch === 'sms' ? '💬 Open SMS' : '✉️ Open email');
                    $nTarget = $nch === 'email' ? '' : 'target="_blank" rel="noopener"';
                @endphp
                <div class="card" style="border-left:4px solid #1f7a44;background:rgba(31,122,68,.07);margin:0 0 12px;padding:10px 14px">
                    <div style="font-weight:700;margin-bottom:6px">Ready to send</div>
                    <a href="{{ session('notify_link') }}" {!! $nTarget !!} class="btn btn-primary" style="padding:7px 14px;font-size:13px">{{ $nLabel }}</a>
                    <button type="button" class="btn btn-ghost copy-pay-link" data-link="{{ session('notify_copy') }}" style="padding:7px 14px;font-size:13px">⧉ Copy message</button>
                </div>
            @endif

            <form method="POST" action="{{ route('bookings.notify', $booking) }}" style="display:flex;gap:12px;align-items:end;flex-wrap:wrap">
                @csrf
                <div class="field" style="margin:0">
                    <label for="notify-template" style="font-size:12px">Notification</label>
                    <select id="notify-template" name="template" style="min-width:200px">
                        @foreach(\App\Services\Messaging\NotificationTemplates::TEMPLATES as $tv => $tl)
                            <option value="{{ $tv }}">{{ $tl }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field" style="margin:0">
                    <label for="notify-recipient" style="font-size:12px">To</label>
                    <select id="notify-recipient" name="recipient" style="min-width:130px">
                        @foreach(\App\Services\Messaging\NotificationTemplates::RECIPIENTS as $rv => $rl)
                            <option value="{{ $rv }}">{{ $rl }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field" style="margin:0">
                    <label for="notify-channel" style="font-size:12px">Channel</label>
                    <select id="notify-channel" name="channel" style="min-width:130px">
                        <option value="whatsapp">🟢 WhatsApp</option>
                        <option value="sms">💬 SMS</option>
                        <option value="email">✉️ Email</option>
                    </select>
                </div>
                <button class="btn btn-primary" style="padding:9px 16px;font-size:14px">Send a notification</button>
            </form>
        </div>

        @php
            $chan = ['whatsapp' => '🟢 WhatsApp', 'sms' => '💬 SMS', 'email' => '✉️ Email'];
            $mstatus = ['sent' => 'complete', 'queued' => 'pending', 'failed' => 'cancelled'];
        @endphp
        <div id="comms" class="card" style="scroll-margin-top:16px">
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
                $driverRealPhone = $booking->driverRealPhone();
            @endphp

            {{-- Numbers & masking collapsed by default to keep the page clean —
                 the masked customer line still shows in the summary at a glance. --}}
            <details style="border:1px solid var(--line);border-radius:10px;padding:10px 14px;margin-bottom:14px">
                <summary style="cursor:pointer;font-weight:700;font-size:14px">🔒 Contact numbers &amp; masking
                    @if($maskedForCustomer)<span class="muted" style="font-weight:400">— give the customer <span class="mono">{{ $maskedForCustomer }}</span></span>@endif
                </summary>
                <div style="margin-top:12px">
            <div class="muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">🔒 Real numbers — office{{ $realNumbers ? '' : ' only, never given out' }}</div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px">
                <div style="flex:1;min-width:200px;border:1px solid var(--line);border-radius:10px;padding:10px 14px">
                    <div class="muted" style="font-size:12px">📞 Customer</div>
                    <div style="font-weight:700;font-size:15px" class="mono">{{ $booking->customerContactNumber() ?? '—' }}</div>
                    @if($booking->customerContactNumber() && $booking->customer?->phone && \App\Support\Phone::wa($booking->customerContactNumber()) !== \App\Support\Phone::wa($booking->customer?->phone))
                        <div class="muted" style="font-size:11px;margin-top:2px">office-set contact · this is the number the switchboard connects</div>
                    @endif
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

            {{-- Per-pickup contacts (shared / multi-pickup jobs). Each via stop that's
                 a separate PARTY gets its own number so masking follows the journey —
                 the party being collected is live, then their number drops and the
                 next pickup's goes live. ADMIN-ONLY: these numbers never reach a
                 driver, they only drive the masked line. --}}
            @if($booking->hasViaStops())
                @php
                    $stopContacts = (array) ($booking->meta['stop_contacts'] ?? []);
                    $activeParty = $booking->pickupParties()[$booking->activePickupIndex()] ?? null;
                    $activeStop = $activeParty['stop'] ?? null; // null = the lead pickup is live
                @endphp
                <form method="POST" action="{{ route('bookings.pickup-contacts', $booking) }}"
                      style="padding-top:12px;border-top:1px solid rgba(128,128,128,.15)">
                    @csrf
                    <div class="muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">🧍 Per-pickup contacts — office only, never shown to the driver</div>

                    <div style="display:flex;justify-content:space-between;align-items:center;border:1px solid var(--line);border-radius:10px;padding:8px 12px;margin-bottom:8px;{{ $activeStop === null ? 'border-color:rgba(31,122,68,.55);background:rgba(31,157,85,.06)' : '' }}">
                        <div>
                            <div style="font-weight:700;font-size:14px">1 · {{ $booking->displayCustomerName() ?? 'Lead pickup' }} <span class="muted" style="font-weight:400;font-size:12px">— {{ \Illuminate\Support\Str::limit($booking->displayPickupAddress() ?? $booking->pickup_address, 42) }}</span></div>
                            <div class="muted mono" style="font-size:12px">{{ $booking->customerContactNumber() ?? '—' }} <span style="font-size:11px">(the booking's own customer)</span></div>
                        </div>
                        @if($activeStop === null)<span style="font-size:11px;font-weight:700;color:#1f7a44">● LIVE NOW</span>@endif
                    </div>

                    @foreach($booking->viaStops() as $i => $stop)
                        <div style="border:1px solid var(--line);border-radius:10px;padding:10px 12px;margin-bottom:8px;{{ $activeStop === $i ? 'border-color:rgba(31,122,68,.55);background:rgba(31,157,85,.06)' : '' }}">
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
                                <div style="font-weight:700;font-size:14px">{{ $i + 2 }} · Stop {{ $i + 1 }} <span class="muted" style="font-weight:400;font-size:12px">— {{ \Illuminate\Support\Str::limit($stop, 42) }}</span></div>
                                @if($activeStop === $i)<span style="font-size:11px;font-weight:700;color:#1f7a44">● LIVE NOW</span>@endif
                            </div>
                            <div style="display:flex;gap:10px;flex-wrap:wrap">
                                <div class="field" style="margin:0;flex:1;min-width:160px">
                                    <label style="font-size:12px" for="pc-name-{{ $i }}">Name</label>
                                    <input id="pc-name-{{ $i }}" name="contacts[{{ $i }}][name]" type="text" maxlength="120"
                                           value="{{ $stopContacts[$i]['name'] ?? '' }}" placeholder="Passenger at this pickup" style="width:100%">
                                </div>
                                <div class="field" style="margin:0;flex:1;min-width:160px">
                                    <label style="font-size:12px" for="pc-phone-{{ $i }}">Mobile (office only)</label>
                                    <input id="pc-phone-{{ $i }}" name="contacts[{{ $i }}][phone]" type="tel" maxlength="40"
                                           value="{{ $stopContacts[$i]['phone'] ?? '' }}" placeholder="07…" style="width:100%">
                                </div>
                            </div>
                        </div>
                    @endforeach

                    <button class="btn btn-ghost" style="padding:8px 14px;font-size:13px">Save pickup contacts</button>
                    <span class="hint" style="margin-left:6px">A number here makes that pickup its own party — the masked line follows the journey. Leave blank for a plain waypoint. Numbers stay office-only.</span>
                </form>
            @endif
                </div>
            </details>

            {{-- Lead time — the clock time the driver would set their alarm for this
                 job. The "Getting ready" prompt + emergency escalation start from
                 here, so we never alert before their alarm. Always populated: the
                 operator's time, else the smart drive-time estimate. --}}
            @unless($booking->status->isTerminal())
                <form method="POST" action="{{ route('bookings.lead-time', $booking) }}"
                      style="display:flex;gap:12px;align-items:end;flex-wrap:wrap;border:1px solid var(--line);border-radius:10px;padding:12px 14px;margin-bottom:14px">
                    @csrf
                    <div class="field" style="margin:0">
                        <label for="lead-time" style="font-size:12px">⏰ Lead time — driver's alarm for this job</label>
                        <input id="lead-time" name="lead_time" type="datetime-local"
                               value="{{ $booking->leadTimeAt()?->format('Y-m-d\TH:i') }}" style="width:220px">
                    </div>
                    <button class="btn btn-ghost" style="padding:8px 14px;font-size:13px">Save lead time</button>
                    <span class="hint">
                        Alerts start at this time.
                        @if($booking->leadTimeIsAuto())
                            <span class="muted">Auto-estimated from the drive — set the driver's real alarm time.</span>
                        @else
                            <span style="color:#1f7a44">Set by you.</span>
                        @endif
                    </span>
                </form>

                {{-- Exactly when the check-in opens and when it rings if unconfirmed,
                     for THIS job — so the per-job timing is visible, not implied. --}}
                @php
                    $checkInAt = $booking->gettingReadyPromptAt();
                    $ringAt = $booking->gettingReadyEscalateAt();
                    $checkedInAt = $booking->gettingReadyConfirmedAt();
                @endphp
                @if($checkInAt && $ringAt)
                    <div style="border:1px solid var(--line);border-radius:10px;padding:10px 14px;margin-bottom:14px;font-size:14px">
                        @if($checkedInAt)
                            <span style="color:#1f7a44;font-weight:700">🟢 Checked in at {{ $checkedInAt->format('D d M, H:i') }}</span>
                            <span class="muted"> — no call will fire.</span>
                        @else
                            ⏰ Check-in opens <strong>{{ $checkInAt->format('D d M, H:i') }}</strong>
                            · 📞 rings {{ $booking->emergencyCallTarget() === 'office' ? 'the business line' : 'the driver' }}
                            at <strong>{{ $ringAt->format('H:i') }}</strong> if not confirmed
                            @if(! $booking->emergencyCallActive())
                                <span class="muted">(this driver isn't in the call scope — office gets an alert, no call)</span>
                            @endif
                        @endif
                    </div>
                @endif
            @endunless

            {{-- Notes for the driver — a free-text brief shown on the driver's job
                 screen for them to read and confirm. Admin-only to edit. Changing
                 the text makes the driver re-confirm they've read it. Numbers do
                 NOT go here (the driver sees it) — reach the customer on the
                 masked line. --}}
            @php $dNotes = $booking->meta['driver_notes'] ?? ''; @endphp
            <form method="POST" action="{{ route('bookings.driver-notes', $booking) }}"
                  style="border:1px solid var(--line);border-radius:10px;padding:12px 14px;margin-bottom:14px">
                @csrf
                <label for="driver-notes" style="font-weight:700;font-size:14px;display:block;margin-bottom:6px">📝 Notes for the driver</label>
                <textarea id="driver-notes" name="driver_notes" rows="4" maxlength="2000"
                          placeholder="Anything the driver needs to know for this job — pickups, timings, meeting point, what to collect. No phone numbers — they reach the customer on the masked line."
                          style="width:100%;font-size:14px;line-height:1.5">{{ old('driver_notes', $dNotes) }}</textarea>
                <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-top:8px">
                    <span class="hint">Shows on the driver's job screen; they tap to confirm they've read it. <strong>Don't put phone numbers here</strong> — the masked line reaches the customer.</span>
                    <span style="display:flex;gap:8px;align-items:center">
                        @if($booking->driverNotesAckAt())
                            <span class="bh-chip ok" style="font-size:12px">read ✓ {{ $booking->driverNotesAckAt()->format('D d M, H:i') }}</span>
                        @elseif($booking->driverReadNotes())
                            <span class="badge badge-pending" style="font-size:12px">not read yet</span>
                        @endif
                        <button class="btn btn-ghost" style="padding:8px 14px;font-size:13px">Save notes</button>
                    </span>
                </div>
            </form>

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

            <div id="reminders" style="scroll-margin-top:16px"></div>
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
                        {{-- The office can send a message BY HAND at any time — the scheduled
                             time is only a recommendation, never a lock. So the Send button is
                             always available; if it's ahead of the recommended time we just note
                             that alongside it. --}}
                        @if($m->whatsAppLink() && $m->status !== 'sent')
                            <a href="{{ $m->whatsAppLink() }}" target="_blank" rel="noopener" class="btn" style="background:#25D366;color:#fff;padding:5px 12px;font-size:12px">📲 Send on WhatsApp</a>
                        @endif
                        @if($m->isScheduledPrompt() && ! $m->isReadyToSend() && $m->status !== 'sent')
                            <span class="muted" style="font-size:12px">🕗 Suggested {{ $m->scheduled_for->format('D d M, H:i') }}</span>
                        @endif
                        {{-- Email fallback for customers who don't use WhatsApp — opens the
                             operator's own mail app with the address + text pre-filled. Always
                             available (like the WhatsApp send) when there's an email on file. --}}
                        @if($m->emailLink() && $m->status !== 'sent')
                            <a href="{{ $m->emailLink() }}" class="btn" style="background:#1f6feb;color:#fff;padding:5px 12px;font-size:12px" title="Email to {{ $booking->customer?->email }}">✉️ Email</a>
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

            {{-- (The standalone "Review link" block was removed — the queued Review
                 request message above already has its own Send / Copy buttons, so
                 this duplicated it.) --}}

            <form method="POST" action="{{ route('bookings.message', $booking) }}" style="margin-top:14px">
                @csrf
                <label for="body" style="font-weight:600">Send a message to {{ $booking->displayName() ?: 'the customer' }}</label>
                <textarea id="body" name="body" required placeholder="Type a message…" style="margin:6px 0 8px;min-height:70px">{{ old('body') }}</textarea>
                <button type="submit" class="btn btn-dark" style="padding:8px 16px">Send</button>
                <span class="hint">Goes to {{ $booking->customerContactNumber() ?? $booking->customer?->email ?? 'no contact on file' }}.</span>
            </form>
        </div>
    @endif

    @if(auth()->user()->isAdmin())
        @php
            $driverBrief = app(\App\Services\CalendarEventBuilder::class)->driverBrief($booking);
            $driverRealForWa = $booking->driverRealPhone();
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

        // Extra-driver picker: choose from the dropdown OR type the name (the
        // datalist filters as you type), and the phone/reg/car prefill.
        (function () {
            var pick = document.getElementById('ex-driver-pick');
            var name = document.getElementById('ex-name');
            if (!pick || !name) return;
            var fill = function (n, p, r, c) {
                name.value = n || name.value;
                document.getElementById('ex-phone').value = p || '';
                document.getElementById('ex-reg').value = r || '';
                document.getElementById('ex-car').value = c || '';
            };
            pick.addEventListener('change', function () {
                var o = pick.options[pick.selectedIndex];
                if (!o || !o.value) return;
                fill(o.getAttribute('data-name'), o.getAttribute('data-phone'), o.getAttribute('data-reg'), o.getAttribute('data-car'));
            });
            // Typing a known name (or picking it from the datalist) prefills too.
            name.addEventListener('input', function () {
                var match = Array.prototype.find.call(pick.options, function (o) {
                    return o.value && (o.getAttribute('data-name') || '').toLowerCase() === name.value.trim().toLowerCase();
                });
                if (match) {
                    fill(match.getAttribute('data-name'), match.getAttribute('data-phone'), match.getAttribute('data-reg'), match.getAttribute('data-car'));
                }
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

    {{-- Job type & driver rotation — collapsed, at the very bottom. Shown on EVERY
         booking so the office can see how the job was auto-classified (which airport,
         or free-roam) and correct the driver/rotation if it's wrong. --}}
    @if(auth()->user()->isAdmin())
        @php
            $onRotation = $booking->onDriverRotation();
            $routeSeq = $onRotation ? $booking->routeSequence(rotationOnly: true) : collect();
            $jobType = $booking->jobTypeLabel();
        @endphp
        <style>
            /* Each job becomes a card on phones — no side-scrolling. */
            @media (max-width: 700px) {
                .rot-check thead { display: none; }
                .rot-check, .rot-check tbody, .rot-check tr, .rot-check td { display: block; width: 100%; }
                .rot-check tr { border: 1px solid var(--line); border-radius: 12px; margin-bottom: 10px; padding: 10px 12px; }
                .rot-check td { border: 0; padding: 3px 0; }
                .rot-check td::before { content: attr(data-label); font-size: 12px; font-weight: 700; color: var(--muted); margin-right: 8px; }
            }
        </style>
        <details style="margin-top:8px">
            <summary style="cursor:pointer;font-weight:700;padding:8px 0">🔢 Job type &amp; driver — <span style="color:var(--accent,#b8860b)">{{ $jobType }}</span> <span class="muted" style="font-weight:500">(tap to check / correct)</span></summary>
            <div class="card" style="margin-top:8px">
                <p style="margin:0 0 6px"><strong>Recognised as:</strong> {{ $jobType }}</p>
                <p class="hint" style="margin:0 0 12px">
                    @if($onRotation)
                        Executive job — allocated by the <strong>Abdi ↔ Maj rotation</strong>. If the order looks wrong, change a driver below and the office decision sticks.
                    @else
                        Not on the executive rotation — {{ $booking->vehicleType?->name ?? 'this vehicle' }} jobs are allocated by hand. Assign or change the driver below.
                    @endif
                </p>

                {{-- Rotation log for THIS job. IMPORTANT: for a normal 'advance',
                     from_driver = the driver who TOOK this job and to_driver = who
                     the pointer moved to NEXT (the OTHER driver) — NOT who did this
                     job. So we never render to_driver as "did it"; who's on the job
                     is always the booking's own assigned driver. --}}
                @php
                    $cs = fn ($u) => $u?->driverProfile?->callsign ?: $u?->name;
                    // Accurate, tense-neutral phrase for one rotation-log entry.
                    // ANCHOR on who the job is ACTUALLY allocated to ($assigned) — that
                    // is the reliable truth and, for a normal turn, whose turn it was.
                    // "Up next" is simply the OTHER logged driver, so the order is right
                    // regardless of how from/to happen to be stored on old logs.
                    $logPhrase = function ($rl, $assigned) use ($cs) {
                        $me = $cs($assigned);
                        $other = null;
                        foreach ([$rl->fromDriver, $rl->toDriver] as $d) {
                            $n = $cs($d);
                            if ($n && $n !== $me) { $other = $n; break; }
                        }
                        return match ($rl->reason) {
                            'advance' => ($me ?: '—').'’s turn'.($other ? ' · '.$other.' up next' : ''),
                            'substitution_no_advance' => 'covered by '.($me ?: '—').($other ? ' (was '.$other.'’s turn)' : ''),
                            'paired_return_no_advance', 'paired_same_driver' => 'same driver as the linked leg',
                            'same_customer_continuity' => 'same customer — kept the same driver',
                            'manual_override' => 'driver set by the office',
                            default => $rl->reason,
                        };
                    };
                @endphp
                @foreach($booking->rotationLogs()->with(['fromDriver.driverProfile', 'toDriver.driverProfile'])->get() as $rl)
                    <p class="hint" style="margin:0 0 4px">🔁 {{ $rl->created_at?->format('D d M, H:i') }} · {{ $logPhrase($rl, $booking->driver) }}</p>
                @endforeach

                {{-- This job's driver — correctable on any booking. --}}
                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:12px">
                    <div>This job: <strong style="color:var(--accent,#b8860b)">{{ $booking->assignedDriverLabel() }}</strong></div>
                    @if(! $booking->status->isTerminal() && $allocatableDrivers->isNotEmpty())
                        <form method="POST" action="{{ route('despatch.reassign', $booking) }}" style="margin:0">
                            @csrf
                            <select name="driver_id" onchange="this.form.submit()" style="font-size:13px;max-width:180px;padding:5px 8px">
                                <option value="" disabled selected>Change driver…</option>
                                @foreach($allocatableDrivers as $d)
                                    <option value="{{ $d->id }}" @selected($booking->driver_id === $d->id)>{{ $d->driverProfile?->callsign ?: $d->name }}</option>
                                @endforeach
                            </select>
                        </form>
                    @endif
                </div>

                @php
                    $routeLabel = match ($booking->routeGroupKey()) {
                        'FREEROAM' => 'free-roam', 'HOURLY' => 'hourly', default => $booking->routeGroupKey(),
                    };
                @endphp
                @if($onRotation && $routeSeq->count() >= 1)
                    <p class="hint" style="margin:0 0 10px">The running order for these executive <strong>{{ $routeLabel }}</strong> jobs — newest that came through at the top. The <strong>Driver</strong> column is who did the job (who it's allocated to); the line under it is whose turn it was on the rotation. This job is highlighted. Change a driver here if the order's wrong.</p>
                    <table class="rot-check">
                        <thead><tr><th>Came in</th><th>Job</th><th>Driver · whose turn</th></tr></thead>
                        <tbody>
                        @foreach($routeSeq as $r)
                            @php
                                $isThis = $r->id === $booking->id;
                            @endphp
                            <tr @if($isThis) style="background:rgba(251,186,42,.14);font-weight:700" @endif>
                                <td data-label="Came in" style="white-space:nowrap;font-size:13px">{{ $r->created_at?->format('D d M, H:i') }}</td>
                                <td data-label="Job" style="font-size:13px">
                                    @if($isThis)➡ {{ $r->reference }}@else<a href="{{ route('bookings.show', $r) }}" class="mono">{{ $r->reference }}</a>@endif
                                    <span class="muted">· {{ \Illuminate\Support\Str::limit($r->displayName(), 16) }}</span>
                                </td>
                                <td data-label="Driver · whose turn">
                                    <strong>{{ $r->assignedDriverLabel() }}</strong>
                                    {{-- The newest log reflects how the CURRENT driver got the job
                                         (a later substitution wins over the first allocation). --}}
                                    @php $turn = $r->rotationLogs->sortByDesc('id')->first(); @endphp
                                    @if($turn)
                                        <div class="hint" style="font-size:11.5px;margin-top:2px">🔁 {{ $logPhrase($turn, $r->driver) }}</div>
                                    @endif
                                    @if(! $r->status->isTerminal() && $allocatableDrivers->isNotEmpty())
                                        <form method="POST" action="{{ route('despatch.reassign', $r) }}" style="margin:2px 0 0">
                                            @csrf
                                            <select name="driver_id" onchange="this.form.submit()" style="font-size:12px;max-width:140px;padding:3px 6px">
                                                <option value="" disabled selected>Change…</option>
                                                @foreach($allocatableDrivers as $d)
                                                    <option value="{{ $d->id }}" @selected($r->driver_id === $d->id)>{{ $d->driverProfile?->callsign ?: $d->name }}</option>
                                                @endforeach
                                            </select>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </details>
    @endif

    <style>
        .txn-menu-item { display:block; width:100%; text-align:left; background:none; border:0; padding:8px 10px;
            font-size:13px; cursor:pointer; border-radius:7px; color:inherit; }
        .txn-menu-item:hover { background:rgba(251,186,42,.16); }
    </style>
    <script src="{{ asset('js/cet-flight.js') }}"></script>
    <script>
        // After a payroll/extra-car action the page reloads with a #anchor; some
        // browsers don't scroll to a fragment that came from a redirect, so land
        // the user back on the section they were using instead of the top.
        document.addEventListener('DOMContentLoaded', function () {
            // Payment-ledger: toggle a transaction's inline edit row.
            document.querySelectorAll('.txn-edit-toggle').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var row = document.getElementById(btn.dataset.target);
                    if (row) row.hidden = !row.hidden;
                    var menu = btn.closest('details'); if (menu) menu.open = false;
                });
            });
            // Copy a link to the clipboard — robust across browsers. Uses the async
            // Clipboard API where allowed, falls back to a hidden textarea + execCommand
            // (older/mobile or non-secure contexts), and as a last resort shows the link
            // to copy by hand. Covers the payment-link box and any other copy buttons.
            function cetCopyLink(btn) {
                var text = btn.dataset.link || '';
                var done = btn.parentElement ? btn.parentElement.querySelector('.copy-link-done') : null;
                var ok = function () {
                    if (done) { done.textContent = '✓ Copied'; }
                    var old = btn.getAttribute('data-label') || btn.textContent;
                    btn.setAttribute('data-label', old);
                    btn.textContent = '✓ Copied';
                    setTimeout(function () { btn.textContent = old; }, 1500);
                };
                var fallback = function () {
                    try {
                        var ta = document.createElement('textarea');
                        ta.value = text; ta.setAttribute('readonly', '');
                        ta.style.position = 'fixed'; ta.style.top = '0'; ta.style.opacity = '0';
                        document.body.appendChild(ta); ta.focus(); ta.select();
                        ta.setSelectionRange(0, text.length);
                        var copied = document.execCommand('copy');
                        document.body.removeChild(ta);
                        if (copied) { ok(); } else { window.prompt('Copy this link:', text); }
                    } catch (e) { window.prompt('Copy this link:', text); }
                };
                if (navigator.clipboard && navigator.clipboard.writeText && window.isSecureContext) {
                    navigator.clipboard.writeText(text).then(ok).catch(fallback);
                } else { fallback(); }
            }
            document.querySelectorAll('.copy-pay-link, .copy-link').forEach(function (btn) {
                btn.addEventListener('click', function (e) { e.preventDefault(); cetCopyLink(btn); });
            });
            // Land back on the section a redirect pointed at (flash 'scroll' or #hash).
            var target = @json(session('scroll')) ;
            var sel = window.location.hash || (target ? '#' + target : '');
            if (!sel) return;
            var el = document.querySelector(sel);
            if (el) {
                if (el.tagName === 'DETAILS') { el.open = true; }
                el.scrollIntoView({ block: 'start' });
            }
        });
    </script>
@endsection
