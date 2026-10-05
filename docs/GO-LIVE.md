# CET Command Centre — Go-Live Connection Checklist

The app is **built and green** (full PHPUnit suite passing). Nothing below changes
code — it's the wiring that turns CET on: connecting Square, Google Maps, embedding
the booking widget on the website, and confirming the background jobs run.

Work through the **Required** section to go live. The **Optional** section is for
when you're ready. Order matters only where noted.

> Deploy target is **`~/cet-staging` only**. `public_html` (the live marketing site)
> is never touched — we embed the widget there by iframe, nothing more.

---

## 0. Deploy the latest code + RUN MIGRATIONS ⚠️

Branch: `claude/cet-command-centre-guide-r1azew`

```
cd ~/cet-staging
git fetch origin claude/cet-command-centre-guide-r1azew
git reset --hard origin/claude/cet-command-centre-guide-r1azew
php artisan migrate --force     # ← DO NOT SKIP THIS TIME
php artisan optimize:clear
php artisan cet:opcache-clear
```

**This deploy adds database tables — `migrate` is mandatory.** New migrations:

- `seed_editable_fixed_price_matrix` — makes the widget's fixed prices editable in `/pricing`
- `create_recurring_bookings_table` — standing / repeat bookings
- `add_availability_to_driver_profiles` — driver weekly availability

If you skip `migrate`, those pages will 500. (`cet:auto-deploy` pulls the branch
every 2 min but **does not always migrate** — run it by hand once for this deploy.)

---

## REQUIRED to go live

### 1. Connect Square (card payments)

Where: **Settings → Card payments (Square)** (or add to `.env`).

From the Square Developer Dashboard (Production), copy into the settings page:

| Setting | Square Dashboard name |
|---|---|
| Environment | `production` |
| Application ID | Application ID |
| Access token | Production Access Token |
| Location ID | the location you take payments against |
| Webhook signature key | Webhook subscription → Signature Key |

Secrets are write-only: saving blank **keeps** the stored value, so you never have
to re-paste a key you've already set. (Separate `SQUARE_CHAUFFEURS_*` fields exist
if the chauffeur arm uses a second Square account — leave blank to reuse the main one.)

### 2. Register the Square webhook(s)

In Square → Developer → Webhooks, add a subscription pointing at:

```
https://staging.centralexecutivetransfers.co.uk/webhooks/square
```

Subscribe to **payment** events. Paste the subscription's Signature Key back into
step 1. If the chauffeur arm uses a second Square account, add a second subscription:

```
https://staging.centralexecutivetransfers.co.uk/webhooks/square-chauffeurs
```

This is what marks a booking **paid** automatically when the customer pays the link.

### 3. Connect Google Maps

Where: **Settings** → Google Maps key (or `GOOGLE_MAPS_API_KEY` in `.env`).

Needed for distance/route pricing, the live fleet map and geocoding. Enable
**Maps JavaScript**, **Directions**, **Geocoding** and **Places** on the key.

> The Maps key was shared in chat once during build — **regenerate it after go-live**
> and restrict it to the staging domain.

### 4. Connect Google Calendar (put bookings on the calendar)

Where: `.env` — `GOOGLE_CALENDAR_CREDENTIALS` + `CET_CALENDAR_ID`.

Now that CET is the booking system, it writes every booking onto the Google
Calendar in the exact CET format (title + full confirmation block), so the
calendar keeps working the way you read it. **This is off until credentials are
set** — then it turns on automatically.

1. In Google Cloud, create a **service account**, enable the **Calendar API**,
   download its JSON key.
2. Put the key on the server and point `GOOGLE_CALENDAR_CREDENTIALS` at it (path
   or the JSON itself); set `CET_CALENDAR_ID` to the calendar's id (default
   `admin@centralexecutivetransfers.co.uk`).
3. **Share the calendar with the service account's `client_email`** with
   *"Make changes to events"*.
4. Verify: `php artisan cet:test-calendar` — it reports exactly where it fails if
   anything's off (clock, sharing, API not enabled).

**No duplicates — by design.** Before adding an event, the sync searches the
calendar for the same **Booking Reference** and *updates that one* instead of
posting a second copy (so events you or ETO already placed are adopted, not
duplicated). It stores the Google event id and reads the event back to confirm.
`cet:sync-calendar` runs every 5 min (needs the scheduler — step 6).

> Safety: the calendar can be paused any time with `php artisan cet:calendar-pause`
> (resume with `cet:calendar-resume`), or hard-killed with `CALENDAR_SYNC_ENABLED=false`.
> Deleting a booking in CET never removes its calendar event — you do that by hand.

### 4b. ETO email feed (Microsoft 365) — recommended live source

Where: `.env` — `MS_GRAPH_*`. CET reads the **ETO confirmation emails** straight from
the **admin@centralexecutivetransfers.co.uk** inbox and creates/updates bookings by
reference (created / amended / cancelled, a/b legs paired). The emails are the
authoritative original, so this is the most reliable source.

1. **Microsoft Entra (Azure AD) → App registrations → New registration** —
   name "CET Command Centre", single tenant. Copy **Application (client) ID** and
   **Directory (tenant) ID**.
2. **Certificates & secrets → New client secret** — copy the secret **Value**.
3. **API permissions → Add → Microsoft Graph → *Application* permissions →
   `Mail.Read`** (add `Mail.Send` only if you want enquiry auto-reply drafts) →
   then **Grant admin consent**. (Optional hardening: an Exchange *Application
   Access Policy* can limit the app to just the admin@ mailbox.)
4. Set `.env` on `~/cet-staging` and `php artisan optimize:clear`:
   ```
   MS_GRAPH_CLIENT_ID=...
   MS_GRAPH_CLIENT_SECRET=...
   MS_GRAPH_TENANT_ID=...
   MS_GRAPH_MAILBOX=admin@centralexecutivetransfers.co.uk   # default is bookings@ — MUST override
   ```
5. Verify: `php artisan cet:test-graph` — reports exactly where it fails.
6. Backfill now: `php artisan cet:ingest-outlook`. After that it runs every 5 min
   via the scheduler (step 6). Idempotent — keyed by reference, no duplicates, and
   it never overwrites a field the office has edited.

### 5. Embed the booking widget on the website

Where: **Settings → Web widgets** — copy the ready-made iframe snippets.

Public widget URLs (served from the Command Centre, iframed into the live site):

- Mini price quote — `/widget/quote`
- Full booking — `/widget/book`
- Customer account — `/my-account`
- Open an account — `/open-account`

Paste the snippet into the marketing site's page editor (WordPress/HTML block) —
**do not edit `public_html` files**, just drop the iframe in through the CMS. The
widget is responsive and shows a standalone backdrop only when opened directly.

### 6. Confirm the scheduler is running

The watchdog, driver nudges, proxy-session cleanup, auto-deploy, recurring-booking
generation, **calendar sync** and calendar refresh all depend on Laravel's
scheduler. Confirm the cron is installed on the server:

```
* * * * * cd ~/cet-staging && php artisan schedule:run >> /dev/null 2>&1
```

Quick check: `php artisan schedule:list` should show the jobs. **If alerts/calls go
silent, this cron is the first thing to check.**

---

## OPTIONAL / when ready

### 7. Customer confirmation emails (default OFF)

Where: **Settings → Web widgets** → "Automatic customer confirmation emails".

Off by default (nothing auto-sends to customers). Turn on only if you want
website-widget bookings to auto-email the customer a confirmation. Office
notification emails are separate and already send to the ops inbox.

### 8. Number masking (Twilio) — optional

Silent no-op until set. To enable masked calls/texts between driver & customer, set
`TWILIO_PROXY_SERVICE_SID` (+ `TWILIO_SID`/`TWILIO_AUTH_TOKEN` and the line numbers)
in `.env`. Proxy webhook: `/webhooks/twilio-proxy`. WhatsApp masking is deferred by
design — don't build it.

### 9. Driver push notifications — optional

Generate VAPID keys once: `php artisan cet:make-vapid`, then set
`VAPID_PUBLIC_KEY` / `VAPID_PRIVATE_KEY` in `.env` and `composer install`. No-op
until set. Drivers opt in from **My jobs**.

### 10. Policy URLs + ops email

Confirm Terms / Privacy / Cancellation URLs and `CET_OPS_EMAIL` are set so
confirmations and the footer link to the right places.

---

## After go-live

- [ ] Regenerate the Google Maps key and domain-restrict it.
- [ ] Place one real test booking end-to-end (card link → paid → calendar event).
- [ ] Watch the dashboard control-tower panel for a day; acknowledge alerts.
- [ ] Confirm `calendar_last_sync_ok` is fresh (no staleness banner).

Everything here is wiring, not code. Once steps 1–6 are done, CET takes live
bookings and payments, and writes every booking onto the calendar.
