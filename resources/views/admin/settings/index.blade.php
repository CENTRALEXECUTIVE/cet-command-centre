@extends('layouts.app')
@section('title', 'Settings')

@section('content')
    <h1 class="page-title">Settings</h1>
    <p class="page-sub">Integration keys — paste them here, no server access needed.</p>

    @if(session('status'))
        <div class="card" style="border-left:4px solid #1f7a44;background:rgba(31,122,68,.08);margin-bottom:16px">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('settings.update') }}">
        @csrf
        @method('PUT')
        <div class="card">
            <h2>🗺️ Google Maps</h2>
            <p class="muted" style="margin-top:0">Powers the booking form's address dropdown and distance-based free-roam pricing. In Google Cloud enable <strong>Places API (New)</strong> and <strong>Routes API</strong> (the legacy "Places API" and "Distance Matrix API" no longer work for new projects), then paste the API key here and press <strong>Save</strong> before testing.</p>
            <label>API key
                <input type="text" name="google_maps_key" value="{{ $mapsKey }}" placeholder="AIza…" autocomplete="off" spellcheck="false">
            </label>
            @if($mapsKey)
                <p class="muted" style="font-size:12px;margin-bottom:8px;color:#1f7a44">✓ Key saved — address autocomplete and distance pricing are active.</p>
            @else
                <p class="muted" style="font-size:12px;margin-bottom:8px">Not set — the booking form uses plain text boxes until a key is added.</p>
            @endif
            <button type="button" id="test-places" class="btn btn-light" style="padding:7px 14px">Test address search</button>
            <span id="test-result" class="muted" style="font-size:13px;margin-left:8px"></span>
        </div>

        <div class="card">
            <h2>🧾 Company &amp; invoice details</h2>
            <p class="muted" style="margin-top:0">Shown on every invoice and receipt. Company accounts with a VAT number get a proper VAT invoice (net + VAT) they can reclaim. Bank details appear on account invoices for payment by transfer.</p>
            <label>Registered / correspondence address
                <textarea name="invoice_company_address" rows="3" placeholder="e.g. 123 Example Street, Sheffield, S1 2AB">{{ $invoiceProfile['address'] }}</textarea>
            </label>
            <div class="grid grid-2">
                <label>VAT registration number
                    <input type="text" name="invoice_vat_number" value="{{ $invoiceProfile['vat_number'] }}" placeholder="GB123456789" autocomplete="off">
                </label>
                <label>Invoice contact email
                    <input type="email" name="invoice_email" value="{{ $invoiceProfile['email'] }}" placeholder="accounts@…" autocomplete="off">
                </label>
                <label>Invoice contact phone
                    <input type="text" name="invoice_phone" value="{{ $invoiceProfile['phone'] }}" placeholder="0114 …" autocomplete="off">
                </label>
            </div>
            <h3 style="margin:16px 0 6px;font-size:14px">Bank details (for account invoices)</h3>
            <div class="grid grid-2">
                <label>Account name
                    <input type="text" name="invoice_bank_name" value="{{ $invoiceProfile['bank_name'] }}" placeholder="Central Executive Transfers Ltd" autocomplete="off">
                </label>
                <label>Sort code
                    <input type="text" name="invoice_bank_sort" value="{{ $invoiceProfile['bank_sort'] }}" placeholder="00-00-00" autocomplete="off">
                </label>
                <label>Account number
                    <input type="text" name="invoice_bank_account" value="{{ $invoiceProfile['bank_account'] }}" placeholder="12345678" autocomplete="off">
                </label>
            </div>
            <label style="margin-top:8px">Invoice footer note <span class="muted" style="font-weight:400">(optional)</span>
                <input type="text" name="invoice_footer_note" value="{{ $invoiceProfile['footer_note'] }}" placeholder="e.g. Thank you for your business" autocomplete="off">
            </label>
            <p class="muted" style="font-size:12px;margin-bottom:0">Press <strong>Save</strong> at the bottom to apply.</p>
        </div>

        <div class="card">
            <h2>🏠 Postcode address finder <span class="muted" style="font-size:13px;font-weight:400">(optional)</span></h2>
            <p class="muted" style="margin-top:0">Lets a customer type just their <strong>postcode</strong> and pick their exact house from the full list of addresses — the "postcode → choose your address" experience. Uses <strong>getAddress.io</strong> (Royal Mail PAF). Sign up at <a href="https://getaddress.io" target="_blank" rel="noopener">getaddress.io</a> (there's a free tier), then paste the API key here. Without it, the form still works using Google's live suggestions as you type.</p>
            <label>getAddress.io API key
                <input type="text" name="getaddress_key" value="{{ $getAddressKey }}" placeholder="e.g. AbCd…" autocomplete="off" spellcheck="false">
            </label>
            @if($getAddressKey)
                <p class="muted" style="font-size:12px;margin-bottom:8px;color:#1f7a44">✓ Key saved — customers can pick their exact address from a postcode.</p>
            @else
                <p class="muted" style="font-size:12px;margin-bottom:8px">Not set — the form uses Google's live address suggestions (type your house number).</p>
            @endif
        </div>

        <div class="card">
            <h2>📞 Phone lines (number masking)</h2>
            <p class="muted" style="margin-top:0">Your two permanent Twilio numbers. The <strong>customer line</strong> is the masked number customers ring/text to reach the driver; the <strong>driver line</strong> is on the driver's job screen to reach the customer. Change a number here — no server access needed.</p>
            <div class="grid grid-2">
                <label>Customer line
                    <input type="text" name="twilio_customer_line" value="{{ $customerLine }}" placeholder="+447…" autocomplete="off" spellcheck="false">
                    @if($customerLine)
                        <span style="font-size:12px;color:#1f7a44;font-weight:600">✓ Active · {{ $customerLine }}</span>
                    @else
                        <span style="font-size:12px;color:#b32020;font-weight:600">✗ Not set — masking off for customers</span>
                    @endif
                </label>
                <label>Driver line
                    <input type="text" name="twilio_driver_line" value="{{ $driverLine }}" placeholder="+447…" autocomplete="off" spellcheck="false">
                    @if($driverLine)
                        <span style="font-size:12px;color:#1f7a44;font-weight:600">✓ Active · {{ $driverLine }}</span>
                    @else
                        <span style="font-size:12px;color:#b32020;font-weight:600">✗ Not set</span>
                    @endif
                </label>
            </div>
            <p class="muted" style="font-size:12px;margin:6px 0 0">Enter in full international format, e.g. <strong>+447575583899</strong>. Leave blank to fall back to the server's configured number.</p>

            @if($prevLine && $prevNames)
                <div class="card" style="margin-top:12px;border-left:4px solid #FBBA2A;background:rgba(251,186,42,.10)">
                    <strong>🔄 Number changeover active</strong>
                    <p class="hint" style="margin:6px 0 0">Only these customers keep the old number <strong>{{ $prevLine }}</strong> (they already have it): <strong>{{ $prevNames }}</strong>. Everyone else uses <strong>{{ $customerLine }}</strong>. Keep the old number live in Twilio until their jobs have run, then clear this list and release it.</p>
                </div>
            @endif

            <div style="margin-top:14px;border-top:1px solid var(--line);padding-top:12px">
                <p class="muted" style="margin:0 0 6px;font-weight:600">Paste these into each Twilio number (HTTP POST):</p>
                <label style="font-size:12px">Messaging webhook URL
                    <input type="text" value="{{ $smsWebhook }}" readonly onclick="this.select()" style="font-family:ui-monospace,monospace;font-size:12px">
                </label>
                <label style="font-size:12px">Voice webhook URL
                    <input type="text" value="{{ $voiceWebhook }}" readonly onclick="this.select()" style="font-family:ui-monospace,monospace;font-size:12px">
                </label>
            </div>
        </div>

        <div class="card">
            <h2>🕵️ Outsourced driver link domain</h2>
            <p class="muted" style="margin-top:0">The <strong>unbranded</strong> driver links (for drivers that aren't ours) normally use this app's address, which shows the company name. Point a <strong>neutral domain</strong> at this app and paste it here — unbranded links will use it instead, so outsourced drivers never see the company name in the URL. Branded links are unchanged.</p>
            <label>Neutral link address
                <input type="url" name="unbranded_link_base" value="{{ $unbrandedLinkBase }}" placeholder="https://jobs.example.co.uk" autocomplete="off" spellcheck="false">
            </label>
            @if($unbrandedLinkBase)
                <p class="muted" style="font-size:12px;margin:6px 0 0;color:#1f7a44">✓ Unbranded links use <strong>{{ $unbrandedLinkBase }}</strong>. (That domain must be pointed at this app to open.)</p>
            @else
                <p class="muted" style="font-size:12px;margin:6px 0 0">Not set — unbranded links use the normal address for now.</p>
            @endif
        </div>

        <div class="card">
            <h2>💳 Square card payments</h2>
            <p class="muted" style="margin-top:0">Paste your Square keys to take card payments online. Leave a secret blank to keep the saved one. Once set, add the webhook URL below in your Square dashboard so paid bookings confirm automatically.</p>
            <div class="grid grid-2" style="gap:12px">
                <label>Environment
                    <select name="square_environment">
                        <option value="production" @selected(($square['environment'] ?? 'production')==='production')>Production (live)</option>
                        <option value="sandbox" @selected(($square['environment'] ?? '')==='sandbox')>Sandbox (test cards)</option>
                    </select>
                </label>
                <label>Application ID
                    <input type="text" name="square_app_id" value="{{ $square['app_id'] }}" placeholder="sq0idp-…" autocomplete="off" spellcheck="false">
                </label>
                <label>Access token
                    <input type="text" name="square_access_token" value="{{ $square['access_token'] }}" placeholder="EAAA…" autocomplete="off" spellcheck="false">
                </label>
                <label>Location ID
                    <input type="text" name="square_location_id" value="{{ $square['location_id'] }}" placeholder="L…" autocomplete="off" spellcheck="false">
                </label>
                <label>Webhook signature key
                    <input type="text" name="square_webhook_signature_key" value="{{ $square['webhook_signature_key'] }}" placeholder="from Square → Webhooks" autocomplete="off" spellcheck="false">
                </label>
            </div>
            <div class="card" style="margin-top:12px;border-left:4px solid #FBBA2A;background:rgba(251,186,42,.10)">
                <strong>Webhook URL for Square</strong>
                <p class="muted" style="margin:4px 0 0;font-size:13px">Add this in Square → Developer → Webhooks (event <code>payment.updated</code>):</p>
                <div class="mono" style="font-size:13px;word-break:break-all;margin-top:4px">{{ $square['webhook_url'] }}</div>
            </div>
            <details style="margin-top:12px">
                <summary style="cursor:pointer;font-weight:700">Sister company (Central Executive Chauffeurs) — optional</summary>
                <p class="muted" style="font-size:13px;margin:6px 0">Non-VAT fares go to this Square account. Leave blank to use the main account for everything.</p>
                <div class="grid grid-2" style="gap:12px">
                    <label>Chauffeurs access token
                        <input type="text" name="square_chauffeurs_access_token" value="{{ $square['chauffeurs_access_token'] }}" placeholder="EAAA…" autocomplete="off" spellcheck="false">
                    </label>
                    <label>Chauffeurs location ID
                        <input type="text" name="square_chauffeurs_location_id" value="{{ $square['chauffeurs_location_id'] }}" placeholder="L…" autocomplete="off" spellcheck="false">
                    </label>
                    <label>Chauffeurs webhook signature key
                        <input type="text" name="square_chauffeurs_webhook_signature_key" value="" placeholder="leave blank to keep saved" autocomplete="off" spellcheck="false">
                    </label>
                </div>
                <div class="mono" style="font-size:13px;word-break:break-all;margin-top:6px">{{ $square['chauffeurs_webhook_url'] }}</div>
            </details>
        </div>

        <div class="card">
            <h2>💠 Stripe — Central Executive Transfers PVT LTD (no-VAT)</h2>
            <p class="muted" style="margin-top:0">Non-VAT fares are taken by the sister company <strong>Central Executive Transfers PVT LTD</strong> through Stripe. Paste the keys from your Stripe dashboard (Developers → API keys). Leave a secret blank to keep the saved one. Once set, add the webhook below in Stripe so paid bookings confirm automatically.</p>
            <div class="grid grid-2" style="gap:12px">
                <label>Secret key
                    <input type="text" name="stripe_secret_key" value="" placeholder="{{ $stripe['secret_key'] ? '•••• saved — leave blank to keep' : 'sk_live_…' }}" autocomplete="off" spellcheck="false">
                </label>
                <label>Publishable key <span class="muted">(optional)</span>
                    <input type="text" name="stripe_publishable_key" value="{{ $stripe['publishable_key'] }}" placeholder="pk_live_…" autocomplete="off" spellcheck="false">
                </label>
                <label>Webhook signing secret
                    <input type="text" name="stripe_webhook_secret" value="" placeholder="{{ $stripe['webhook_secret'] ? '•••• saved — leave blank to keep' : 'whsec_…' }}" autocomplete="off" spellcheck="false">
                </label>
            </div>
            <div class="card" style="margin-top:12px;border-left:4px solid #635BFF;background:rgba(99,91,255,.08)">
                <strong>Webhook URL for Stripe</strong>
                <p class="muted" style="margin:4px 0 0;font-size:13px">Add this in Stripe → Developers → Webhooks (event <code>checkout.session.completed</code>):</p>
                <div class="mono" style="font-size:13px;word-break:break-all;margin-top:4px">{{ $stripe['webhook_url'] }}</div>
            </div>

            <h3 style="margin:16px 0 4px;font-size:15px">PVT LTD invoice details (non-VAT invoices)</h3>
            <p class="muted" style="font-size:13px;margin-top:0">Shown as the issuing company on non-VAT customer invoices. No VAT number (not VAT registered).</p>
            <div class="grid grid-2" style="gap:12px">
                <label>Company name
                    <input type="text" name="invoice_novat_company_name" value="{{ $stripe['novat_company_name'] }}" placeholder="Central Executive Transfers PVT LTD" autocomplete="off">
                </label>
                <label>Company number
                    <input type="text" name="invoice_novat_company_number" value="{{ $stripe['novat_company_number'] }}" placeholder="e.g. 12345678" autocomplete="off">
                </label>
                <label style="grid-column:1/-1">Registered address <span class="muted">(blank = use the main company address)</span>
                    <input type="text" name="invoice_novat_company_address" value="{{ $stripe['novat_company_address'] }}" placeholder="Registered office address" autocomplete="off">
                </label>
            </div>
        </div>

        <button type="submit" class="btn btn-primary">Save</button>
    </form>

    <div id="mail" class="card" style="scroll-margin-top:16px">
        <h2>📧 Email (booking confirmations &amp; invoices)</h2>
        @if(($mailer ?? 'log') === 'log')
            <div class="alert alert-danger" style="margin:0 0 10px">
                <strong>Emails are NOT being sent.</strong> Mail is set to <code>log</code>, so confirmations are written to a log file instead of emailed. Set <code>MAIL_MAILER=smtp</code> (plus host, username, password and <code>MAIL_FROM_ADDRESS</code>) in the server <code>.env</code>, then <code>php artisan optimize:clear</code>.
            </div>
        @else
            <p class="muted" style="margin-top:0">Mailer: <strong>{{ $mailer }}</strong> · From: <strong>{{ $mailFrom ?: 'not set' }}</strong> · Office copy goes to: <strong>{{ $opsEmail }}</strong>.</p>
        @endif
        <form method="POST" action="{{ route('settings.test-email') }}" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:0">
            @csrf
            <input type="email" name="to" placeholder="send a test to… (default: your email)" style="flex:1;min-width:220px;padding:8px 10px;border:1px solid var(--line);border-radius:8px">
            <button type="submit" class="btn btn-dark" style="padding:8px 14px">Send test email</button>
        </form>
        <p class="hint" style="margin:8px 0 0">Sends a plain test message so you can confirm email works before a real booking. If it's set to <code>log</code> or the SMTP details are wrong, it'll tell you here.</p>
    </div>

    <script>window.CET_PLACES_URL = "{{ route('places.autocomplete') }}";</script>
    <script>
        document.getElementById('test-places').addEventListener('click', function () {
            var out = document.getElementById('test-result');
            out.textContent = 'testing…'; out.style.color = '';
            fetch(window.CET_PLACES_URL + '?q=Meadowhall Sheffield', { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.suggestions && d.suggestions.length) {
                        out.style.color = '#1f7a44';
                        out.textContent = '✓ Working — e.g. "' + d.suggestions[0] + '"';
                    } else {
                        out.style.color = '#b32020';
                        out.textContent = '✗ No results — save the key above, or check it allows Places API (New) with no HTTP-referrer restriction.';
                    }
                })
                .catch(function () { out.style.color = '#b32020'; out.textContent = '✗ Request failed.'; });
        });
    </script>
@endsection
