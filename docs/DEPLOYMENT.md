# CET Command Centre — Deployment Runbook (GoDaddy cPanel)

Target: GoDaddy shared cPanel hosting, home dir `/home/u2beq0g0k7mj`, MySQL,
PHP 8.2+. This turns the tested codebase into a live HTTPS site.

> Do a **staging** run first (a subdomain like `staging.centralexecutivetransfers.co.uk`)
> and the **parallel run vs ETO** before pointing the main domain at it.

---

## 1. One-time setup in cPanel

1. **PHP version**: set to **8.2 or 8.3** (MultiPHP Manager). Enable extensions:
   `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`,
   `bcmath`, `fileinfo`, `curl`.
2. **MySQL database** (MySQL Databases):
   - Create DB `u2beq0g0k7mj_cet` and a user with a strong password.
   - Add the user to the DB with **All Privileges**.
3. **Document root**: point the domain/subdomain's root at the app's **`public/`**
   folder (Domains → manage → Document Root). Laravel must serve from `public/`,
   never the project root.

---

## 2. Get the code onto the server

**Option A — Git (preferred):**
```bash
cd ~ && git clone <repo-url> cet && ln -s ~/cet/public ~/public_html  # if docroot can't be moved
```

**Option B — Zip + Extract (GoDaddy fallback):**
Build a zip locally (exclude `.git`, `node_modules`, `.env`), upload via File
Manager, **Extract** in place. (cPanel sometimes blocks direct `.php` upload —
zip + Extract avoids it.)

Install dependencies (cPanel Terminal, or run Composer locally and upload `vendor/`):
```bash
cd ~/cet
composer install --no-dev --optimize-autoloader
```

---

## 3. Environment

```bash
cp .env.example .env
php artisan key:generate
```
Edit `.env` for production:
```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://centralexecutivetransfers.co.uk

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=u2beq0g0k7mj_cet
DB_USERNAME=u2beq0g0k7mj_cet
DB_PASSWORD=********

# Secrets — fill from each provider (never commit):
CET_ICO_NUMBER=ZB-xxxxxx
CET_SEED_PASSWORD=<strong-one-time>
CET_WEBHOOK_SECRET=<random-32-chars>
ANTHROPIC_API_KEY=...
TWILIO_SID=...  TWILIO_AUTH_TOKEN=...  TWILIO_WHATSAPP_FROM=+44...
TIDE_API_KEY=...
GOOGLE_MAPS_API_KEY=...
CET_CALENDAR_ID=admin@centralexecutivetransfers.co.uk
```
- `APP_DEBUG=false` is mandatory in production.
- HTTPS is forced automatically in `production` (AppServiceProvider).
- Card data is never stored — only Tide links.

---

## 4. Migrate, seed and load data

```bash
php artisan migrate --force
php artisan db:seed --force                         # vehicle types, directors, airports, rotation, pricing, settings
php artisan cet:import-fixed-prices storage/app/fixed_prices.csv   # full ETO fixed-price matrix
php artisan cet:import-eto-bookings storage/app/eto_bookings.csv   # historical bookings
php artisan storage:link
```
**Immediately change the seeded director/corporate passwords** (they use
`CET_SEED_PASSWORD`).

---

## 5. Caches (run after EVERY deploy)

```bash
cd ~/public_html        # or ~/cet
php artisan config:cache
php artisan route:cache
php artisan view:cache
# If anything looks stale or after editing routes/views:
php artisan view:clear
php artisan route:clear
```

---

## 6. Scheduler + queue (cron)

The system relies on scheduled jobs (WhatsApp reminders, GPS prune, compliance,
invoices) and a database queue. In cPanel → **Cron Jobs** add:

```
# Laravel scheduler — every minute
* * * * * cd /home/u2beq0g0k7mj/cet && php artisan schedule:run >> /dev/null 2>&1

# Queue worker — process queued jobs, stop when empty (shared-hosting friendly)
* * * * * cd /home/u2beq0g0k7mj/cet && php artisan queue:work --stop-when-empty --tries=3 >> storage/logs/queue.log 2>&1
```

---

## 7. Integrations to point at the live URL

- **Twilio** (WhatsApp + missed-call): set the missed-call webhook to
  `https://centralexecutivetransfers.co.uk/webhooks/missed-call?secret=<CET_WEBHOOK_SECRET>`.
- **Google Calendar / Maps / Anthropic / Tide**: keys in `.env` only.
- **Tracking links** are public at `/track/{token}` — ensure HTTPS works.

---

## 8. Post-deploy smoke test

- [ ] `https://…/login` loads over HTTPS (no mixed content).
- [ ] Sign in as a director; dashboard + despatch board load.
- [ ] Create a test booking → confirmation queued, calendar event row created.
- [ ] Driver app `/driver/jobs` loads on a phone; status update works.
- [ ] `/track/{token}` opens for an En Route job.
- [ ] ICO number shows in the footer; `APP_DEBUG=false` (no stack traces).
- [ ] `php artisan schedule:list` shows the four scheduled commands.

---

## 9. Rollback

- Code: `git checkout <previous-tag>` (or re-extract the previous zip), then
  re-run the cache commands in §5.
- DB: restore from the cPanel backup taken before `migrate`. Always snapshot the
  database before deploying migrations.

---

## 10. GDPR / security checklist for go-live

- [ ] `APP_DEBUG=false`, HTTPS forced, valid SSL.
- [ ] ICO registration number set (`CET_ICO_NUMBER`) and shown on public pages.
- [ ] Right-to-erasure available at `/gdpr/erasure` (admin).
- [ ] GPS retention prune scheduled (`cet:prune-gps`, 90 days).
- [ ] All seeded passwords rotated; `.env` not world-readable (`chmod 600 .env`).
- [ ] DB backups scheduled in cPanel.
