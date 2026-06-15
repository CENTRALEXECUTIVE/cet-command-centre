# Trialling CET Command Centre with the integrations switched ON

This turns the staging instance from "everything logs" into a **fully working
trial** — real WhatsApp, AI pricing, maps, calendar, etc. — while keeping it
safe. Do it on **staging** (`APP_ENV=staging`), not the live site.

## Golden rules for a safe trial
1. **One at a time.** Add one integration's keys, run its test, confirm, move on.
   If something misbehaves, blank the key again and it instantly falls back.
2. **Point it at yourself first.** Use *your own* mobile and email, and each
   provider's **test/sandbox mode**, so no real customer is contacted or charged.
3. After editing `.env`, always run: `php artisan config:clear`.
4. Keep `APP_DEBUG=true` on staging so you see any error in full.

## Make the automation run (needed for reminders, flights, calendar, email)
The scheduler drives reminders, flight checks, calendar sync and Outlook
ingestion. Add ONE cron in cPanel → *Cron Jobs* (every minute):
```
* * * * * cd /home/u2beq0g0k7mj/cet-staging && php artisan schedule:run >> /dev/null 2>&1
```
Check it's registered: `php artisan schedule:list` (should list 8 jobs).

---

## The integrations, in the order I'd switch them on

### 1. AI pricing — Anthropic (claude-opus-4-8)  💷 paid per call (tiny)
- Get a key at console.anthropic.com → `.env`: `ANTHROPIC_API_KEY=sk-ant-...`
- **Test**: Quote screen → generate a quote → the breakdown shows an *AI
  rationale* and `model: claude-opus-4-8`. (Blank key = rule price, no rationale.)

### 2. Maps / distances — Google Maps  💷 paid (free tier covers a trial)
- Google Cloud → enable **Distance Matrix API** → `.env`: `GOOGLE_MAPS_API_KEY=...`
- **Test**: make a quote *without* typing a distance → quote breakdown shows
  `distance_source: google`.

### 3. WhatsApp — Twilio  💷 paid (sandbox is free)
- Twilio → **WhatsApp Sandbox** (Messaging → Try it out). Join the sandbox from
  *your own* phone per the instructions.
- `.env`:
  ```
  TWILIO_SID=AC...
  TWILIO_AUTH_TOKEN=...
  TWILIO_WHATSAPP_FROM=whatsapp:+14155238886   # the sandbox number Twilio gives you
  ```
- **Test**: create a booking using **your own mobile** as the customer →
  you receive the confirmation WhatsApp. Reminders/En-Route/review messages then
  flow via the scheduler.
- *(Going live later needs a WhatsApp-approved sender number + message templates;
  the sandbox is fine for the trial.)*

### 4. Email (invoices, etc.) — SMTP
- Use your GoDaddy/Office365 mailbox SMTP in `.env`:
  ```
  MAIL_MAILER=smtp
  MAIL_HOST=...   MAIL_PORT=587   MAIL_USERNAME=...   MAIL_PASSWORD=...
  MAIL_FROM_ADDRESS=bookings@centralexecutivetransfers.co.uk
  ```
- **Test**: generate a corporate invoice → the account's billing email receives
  it with the PDF attached. (Set the test account's billing email to your own.)

### 5. Payments — Tide  💷 real money in live mode
- Add `TIDE_API_KEY=...` when you have API access. Until then a **placeholder
  payment link** is generated so the flow is testable.
- **Test**: card booking → the booking's payment block shows a Tide link.
- *Never test with a real card on staging.*

### 6. Flight monitoring — flight API (e.g. AviationStack)  💷 free tier
- `.env`: `FLIGHT_API_KEY=...`
- **Test**: create a booking with a **real flight number** arriving soon, then
  `php artisan cet:check-flights`. A delayed flight pushes the pickup time and
  messages the (your) customer number.

### 7. Google Calendar — service account
- Create a Google service account, share the calendar
  `admin@centralexecutivetransfers.co.uk` with it, put the credentials JSON path
  in `.env`: `GOOGLE_CALENDAR_CREDENTIALS=/home/.../google.json`
- **Test**: `php artisan cet:sync-calendar` → new bookings appear on the calendar
  with the correct title/emoji/notifications.

### 8. Outlook booking emails — Microsoft Graph
- Azure app registration with `Mail.ReadWrite` (application) →
  `MS_GRAPH_CLIENT_ID / _SECRET / _TENANT_ID`, and `MS_GRAPH_MAILBOX`.
- **Test**: email a sample booking to the mailbox → `php artisan cet:ingest-outlook`
  → a booking is created from it.

### 9. Number masking — Twilio voice  💷 paid
- Buy a Twilio number → `.env`: `TWILIO_PROXY_NUMBER=+44...`; set its **Voice
  webhook** to `https://staging.centralexecutivetransfers.co.uk/webhooks/voice?secret=<CET_WEBHOOK_SECRET>`.
- **Test**: while you have an active job, call the proxy number from the
  customer's phone → it connects to the driver without showing real numbers.

### 10. Missed-call auto-reply — Twilio voice
- Set the number's *no-answer* webhook to
  `.../webhooks/missed-call?secret=<CET_WEBHOOK_SECRET>`.
- **Test**: miss a call to it → an auto WhatsApp goes back to the caller.

### 11. Google Ads dashboard
- Easiest for the trial: export a Google Ads CSV and run
  `php artisan cet:sync-ads --csv=storage/app/ads.csv`. (Live API needs
  `GOOGLE_ADS_*`.) The dashboard + budget alerts then show real numbers.

---

## Load your real data for the trial
- **Fixed prices**: enter/confirm them in the admin **Pricing** screen
  (`/pricing`) — a row per zone → destination with a fare per vehicle type,
  mirroring the ETO Fixed Prices screen. (A CSV import also exists if you ever
  export one: `php artisan cet:import-fixed-prices file.csv`.)
- **Zones**: already seeded from the zone brief (postcode → zone), so postcode
  pricing works out of the box. The separate Doogal KML (see `tools/zones`) is
  only needed if you also want to import zone polygons into ETO.
- **Historical bookings**: `php artisan cet:import-eto-bookings storage/app/eto_bookings.csv`

## Where messages/errors show during the trial
- Outgoing messages/emails (when a key is blank) and any errors:
  `storage/logs/laravel.log` — `tail -f storage/logs/laravel.log` in Terminal.
- Every booking's WhatsApp/SMS history is also recorded in the **messages** table
  and visible against the booking.

When you've trialled each piece and you're happy, follow `docs/DEPLOYMENT.md`
§5–§10 to promote to the live domain (`APP_ENV=production`, `APP_DEBUG=false`,
rotate passwords).
