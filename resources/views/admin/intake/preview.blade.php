@extends('layouts.app')
@section('title', 'Add to the calendar')

@section('content')
    <p class="page-sub"><a href="{{ route('intake.index') }}">← Paste another</a></p>
    <h1 class="page-title">Add to the calendar</h1>
    <p class="page-sub">Fix anything that’s wrong, press <strong>Update preview</strong> to re-check, then <strong>copy</strong> each part onto Google Calendar. It appears in the Command Centre within ~5 minutes — the calendar stays the single source, so there are never duplicates.</p>

    @if($errors->any())
        <div class="card" style="border-left:4px solid #b32020;background:rgba(179,32,32,.08);margin-bottom:16px"><ul style="margin:0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @endif

    {{-- Paste (or re-paste) the message — the free parser fills the fields
         instantly (no AI, no cost). --}}
    <details class="card" style="margin-bottom:16px">
        <summary style="cursor:pointer;font-weight:600">📋 Paste the message to auto-fill the fields</summary>
        <form method="POST" action="{{ route('intake.preview') }}" style="margin-top:10px">
            @csrf
            <textarea name="raw" rows="4" placeholder="Paste the booking message here…" style="width:100%" required></textarea>
            <button type="submit" class="btn btn-ghost" style="margin-top:8px">↻ Read &amp; fill the fields</button>
        </form>
    </details>

    @if($nextDriver)
        <div class="card" style="border-left:4px solid #1f7a44;background:rgba(31,122,68,.06);margin-bottom:16px">
            🚗 <strong>Next in rotation: {{ $nextDriver['tag'] }}</strong>
            <span class="muted">({{ $nextDriver['name'] }})</span> —
            the title below is already tagged <strong>({{ $nextDriver['tag'] }})</strong>. If you put someone else on, change the tag in the title before you paste it.
        </div>
    @elseif(($fields['vehicle'] ?? '') !== '')
        <div class="card" style="border-left:4px solid #6f6f6f;background:rgba(128,128,128,.06);margin-bottom:16px">
            🚗 This vehicle type is outside the driver rotation — set the driver tag in the title yourself.
        </div>
    @endif

    {{-- The exact calendar block — three parts to copy straight onto the event. --}}
    <div class="card" style="border-left:4px solid #FBBA2A">
        <h2 style="margin-top:0">📅 Copy onto the calendar</h2>

        <div class="cal-part">
            <div class="cal-label">Title</div>
            <div class="cal-row">
                <div class="copytext" data-copy style="font-weight:700;font-size:16px">{{ $preview['title'] }}</div>
                <button type="button" class="btn btn-light cal-copy">⧉ Copy</button>
            </div>
        </div>

        <div class="cal-part">
            <div class="cal-label">Location <span class="muted">(pickup address)</span></div>
            <div class="cal-row">
                <div class="copytext" data-copy>{{ $preview['location'] ?: '— no pickup location —' }}</div>
                <button type="button" class="btn btn-light cal-copy">⧉ Copy</button>
            </div>
        </div>

        <div class="cal-part">
            <div class="cal-label">Description</div>
            <div class="cal-row">
                <div class="copytext" data-copy style="white-space:pre-wrap;font-size:14px;line-height:1.6;background:rgba(0,0,0,.03);padding:12px;border-radius:8px;flex:1">{{ $preview['description'] }}</div>
                <button type="button" class="btn btn-light cal-copy">⧉ Copy</button>
            </div>
        </div>

        <p class="hint" style="margin:10px 0 0">Make a new Google Calendar event, paste each part into the matching field, set the start to the pickup time, and save. The sync brings it into the Command Centre automatically.</p>
    </div>

    @verbatim
    <style>
        .cal-part { margin-bottom:14px }
        .cal-label { font-weight:600;font-size:13px;margin-bottom:4px }
        .cal-row { display:flex;gap:10px;align-items:flex-start }
        .cal-row .copytext { flex:1;min-width:0;overflow-wrap:anywhere }
        .cal-copy { flex:0 0 auto;padding:6px 14px;font-size:13px;align-self:flex-start }
    </style>
    <script>
        document.querySelectorAll('.cal-copy').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var box = btn.closest('.cal-row').querySelector('[data-copy]');
                var text = box ? box.innerText : '';
                navigator.clipboard.writeText(text).then(function () {
                    var old = btn.textContent; btn.textContent = '✓ Copied';
                    setTimeout(function () { btn.textContent = old; }, 1500);
                }).catch(function () {});
            });
        });
    </script>
    @endverbatim

    {{-- Editable fields. Submitting re-renders the copy-ready block. --}}
    <form method="POST" action="{{ route('intake.preview') }}" class="eto-form" style="margin-top:16px">
        @csrf
        <input type="hidden" name="fields[driver_tag]" value="{{ $fields['driver_tag'] ?? '' }}">

        <div class="eto-section">
            <div class="head"><span class="ico">👤</span> Passenger</div>
            <div class="body">
                <div class="grid grid-2">
                    <div class="field"><label>Passenger (lead) name <span class="req">*</span></label><input name="fields[lead_name]" value="{{ $fields['lead_name'] }}" required></div>
                    <div class="field"><label>Contact number</label><input type="tel" name="fields[contact_no]" value="{{ $fields['contact_no'] }}" placeholder="07…"></div>
                    <div class="field"><label>Email</label><input type="email" name="fields[email]" value="{{ $fields['email'] }}"></div>
                    <div class="field"><label>Booked by <span class="sub">— if not the passenger</span></label><input name="fields[booked_by]" value="{{ $fields['booked_by'] }}"></div>
                </div>
            </div>
        </div>

        <div class="eto-section">
            <div class="head"><span class="ico">🚗</span> Journey</div>
            <div class="body">
                <div class="grid grid-2">
                    <div class="field"><label>Pickup date &amp; time <span class="req">*</span></label><input type="datetime-local" name="fields[pickup_at]" value="{{ \Illuminate\Support\Str::of((string) $fields['pickup_at'])->replace(' ', 'T')->substr(0, 16) }}" required></div>
                    <div class="field"><label>Flight number</label><input name="fields[flight_number]" value="{{ $fields['flight_number'] }}" placeholder="e.g. BA1234"></div>
                    <div class="field"><label>Pickup address <span class="req">*</span></label><input name="fields[pickup_address]" value="{{ $fields['pickup_address'] }}" required></div>
                    <div class="field"><label>Drop-off address <span class="req">*</span></label><input name="fields[destination_address]" value="{{ $fields['destination_address'] }}" required></div>
                    <div class="field">
                        <label>Where <span class="sub">— title tag</span></label>
                        <input name="fields[where]" value="{{ $fields['where'] }}" placeholder="MAN / LHR / Sheffield">
                        <span class="hint">Airport code or destination word shown in the calendar title.</span>
                    </div>
                    <div class="field"><label>Vehicle</label>
                        <select name="fields[vehicle]">
                            @foreach($vehicleTypes as $vt)
                                <option value="{{ $vt }}" @selected($fields['vehicle'] === $vt)>{{ $vt }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <div class="eto-section">
            <div class="head"><span class="ico">🧳</span> Passengers, luggage &amp; payment</div>
            <div class="body">
                <div class="grid grid-3">
                    <div class="field"><label>Passengers</label><input type="number" min="1" max="16" name="fields[passengers]" value="{{ $fields['passengers'] }}"></div>
                    <div class="field"><label>Suitcases</label><input type="number" min="0" max="30" name="fields[suitcases]" value="{{ $fields['suitcases'] }}"></div>
                    <div class="field"><label>Hand luggage</label><input type="number" min="0" max="30" name="fields[hand_luggage]" value="{{ $fields['hand_luggage'] }}"></div>
                </div>
                <div class="grid grid-2" style="margin-top:4px">
                    <div class="field"><label>Payment</label>
                        <select name="fields[payment]">
                            <option value="cash" @selected($fields['payment']==='cash')>Cash</option>
                            <option value="card" @selected($fields['payment']==='card')>Card</option>
                            <option value="account" @selected($fields['payment']==='account')>Account</option>
                        </select>
                    </div>
                    <div class="field" style="display:flex;align-items:flex-end">
                        <label class="checkbox-row" style="margin:0"><input id="paid" type="checkbox" name="fields[paid]" value="1" @checked(!empty($fields['paid']))> Fully paid</label>
                    </div>
                </div>
                <div class="field"><label>Notes</label><textarea name="fields[notes]" rows="2">{{ $fields['notes'] }}</textarea></div>
            </div>
        </div>

        <div class="toolbar">
            <button type="submit" class="btn btn-primary">↻ Update preview</button>
            <span class="hint" style="margin-left:8px">Re-render the calendar block above after an edit.</span>
        </div>
    </form>
@endsection
