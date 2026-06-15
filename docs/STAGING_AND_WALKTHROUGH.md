# Test CET Command Centre safely (staging) — and walk through it all

This is the recommended way to **try the whole system before it touches your live
site**. You run it on a **separate staging subdomain** with its own database and
demo data, so nothing affects `centralexecutivetransfers.co.uk`.

---

## A. Stand up a private staging copy (GoDaddy cPanel)

> Full detail is in `docs/DEPLOYMENT.md`. This is the short, staging-only version.

1. **Subdomain**: in cPanel → *Domains* → create `staging.centralexecutivetransfers.co.uk`
   and set its **document root to the app's `public/` folder**.
2. **Database**: cPanel → *MySQL Databases* → create a NEW db + user
   (e.g. `..._cet_staging`) — keep it separate from live.
3. **Code**: clone the repo (or upload a zip and Extract) into `~/cet-staging`,
   then in cPanel *Terminal*:
   ```bash
   cd ~/cet-staging
   composer install --no-dev --optimize-autoloader
   cp .env.example .env
   php artisan key:generate
   ```
4. **.env** (staging values — keep it clearly NOT production):
   ```
   APP_ENV=staging
   APP_DEBUG=true
   APP_URL=https://staging.centralexecutivetransfers.co.uk
   DB_DATABASE=..._cet_staging
   DB_USERNAME=...   DB_PASSWORD=...
   # Leave integration keys BLANK for now — everything safely no-ops/logs.
   MAIL_MAILER=log
   ```
   > With keys blank: WhatsApp/email/Tide/AI/flights all **log instead of sending**,
   > so you can test end-to-end without messaging real customers or taking payment.
5. **Build the test data**:
   ```bash
   php artisan migrate --force
   php artisan db:seed --force                                   # base data
   php artisan db:seed --class="Database\Seeders\DemoSeeder" --force   # demo bookings, invoice, ads, etc.
   php artisan storage:link
   php artisan config:cache && php artisan route:cache && php artisan view:cache
   ```
6. Open `https://staging.centralexecutivetransfers.co.uk` and sign in.

**Logins** (password = your `CET_SEED_PASSWORD`, default `ChangeMe!2026`):
| Role | Email |
|------|-------|
| Admin (Abdi) | abdi@centralexecutivetransfers.co.uk |
| Admin (Maj) | maj@centralexecutivetransfers.co.uk |
| Corporate client | bookings@jeld-wen.example |

> No cPanel Terminal? Use the **zip + Extract** route in `docs/DEPLOYMENT.md`,
> run Composer locally before uploading, and ask your host to enable Terminal —
> or tell me and I'll give a no-CLI variant.

To wipe and start the demo again at any time:
`php artisan migrate:fresh --seed --force && php artisan db:seed --class="Database\Seeders\DemoSeeder" --force`

---

## B. Walk through everything (test checklist)

Sign in as **Admin** unless noted. Expected result in *italics*.

### Booking & pricing
- [ ] **Quote** → New quote: Sheffield → Manchester Airport, Executive.
      *Instant price + breakdown; "Convert to booking" prefills the form.*
- [ ] **New Booking**: fill it in, accept the privacy box, submit.
      *Booking created; for an Executive airport job a driver is auto-allocated by rotation.*
- [ ] Try an invalid booking (past pickup time, 6 passengers in an Executive,
      a corporate account with no cost code). *Each is blocked with a clear error.*
- [ ] Open the new booking. *See payment block (Tide link for card), calendar
      event, status history and — as admin — the audit trail.*

### Despatch (admin)
- [ ] **Despatch** board: see today's jobs in status columns.
- [ ] On a Pending job tap **Auto (rotation)** or pick a driver + **Assign**.
- [ ] Move a job along: Accepted → En Route → Collected → Complete.
      *Illegal jumps are rejected. En Route generates a tracking link.*

### Driver app (sign in as Abdi, or use **My Jobs**)
- [ ] **My Jobs** on a phone: Today / Tomorrow / This Week filters.
- [ ] Open a job, tap a status button (allow location when prompted).
      *Status updates; GPS is captured into the booking's history.*

### Live tracking (no login)
- [ ] From an En Route job, open its tracking link (`/track/...`).
      *Public page shows status + a live map that updates.*

### Corporate portal (sign in as the JELD-WEN login)
- [ ] **Account**: only that account's bookings + spend totals.
- [ ] **Invoices** → open the seeded invoice → **Download PDF**.
      *A branded VAT invoice PDF.*

### Reports & marketing (admin)
- [ ] **Reports**: earnings by driver, top routes, vehicle mix, period change.
- [ ] **Ads**: ROAS, spend vs revenue, and the budget-trigger panel.

### Compliance & waiting list (admin)
- [ ] **Compliance**: MOT/insurance/PHV/DBS grouped valid / due-soon / expired.
- [ ] **Waiting List**: add someone; cancel a booking on their date.
      *(With WhatsApp configured they'd be auto-notified; here it's logged.)*

### GDPR (admin)
- [ ] **GDPR**: raise an erasure request for a customer, then process it.
      *Customer anonymised, bookings kept but PII stripped.*
- [ ] Cookie banner shows on first visit; choosing an option records consent.

### Scheduled automation (cPanel Terminal)
- [ ] `php artisan schedule:list` *— shows 8 jobs.*
- [ ] `php artisan cet:check-compliance` and `cet:send-due-messages`
      *— run without error (messages log to `storage/logs`).*

---

## C. When you're happy → go live

1. Turn on integrations **one at a time** on staging (add each key, re-test that
   one feature) — WhatsApp, Tide, Anthropic, Google Maps/Calendar, flights.
2. Load the **real fixed-price CSV** and **ETO bookings** (`cet:import-...`).
3. Run **both systems in parallel** with ETO for a short period (the bookings
   importer's `external_reference` lets you match them one-to-one).
4. Have someone do a quick **security/GDPR review**.
5. Point the **main domain** at the app (or promote staging), set
   `APP_ENV=production`, `APP_DEBUG=false`, rotate seeded passwords, add the
   `schedule:run` cron. See `docs/DEPLOYMENT.md` §5–§10.
