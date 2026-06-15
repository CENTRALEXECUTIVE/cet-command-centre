# Test CET Command Centre safely (staging) — and walk through it all

This is the recommended way to **try the whole system before it touches your live
site**. You run it on a **separate staging subdomain** with its own database and
demo data, so nothing affects `centralexecutivetransfers.co.uk`.

---

## A. Stand up a private staging copy (GoDaddy cPanel + Terminal)

Follow top to bottom. ~20 minutes. Nothing here touches your live site.

### 1. In cPanel (point & click)
- **PHP version**: *MultiPHP Manager* → set the account to **PHP 8.2 or 8.3**.
- **Subdomain**: *Domains* → *Create A New Domain* → `staging.centralexecutivetransfers.co.uk`.
  Set its **Document Root** to: `cet-staging/public`  ← type exactly this.
- **Database**: *MySQL Databases* → create database `..._cetstaging`, create a user
  with a strong password, then **Add User To Database** with *All Privileges*.
  Write down the **database name, username, password**.

### 2. Open Terminal (cPanel → *Terminal*) and get the code
Check PHP is 8.2+ first:
```bash
php -v        # if this shows PHP 7.x, use the full path below instead of "php":
              # /opt/cpanel/ea-php83/root/usr/bin/php
```
Get the code onto the server (the project lives on a feature branch):
```bash
cd ~
git clone https://github.com/centralexecutive/cet-command-centre.git cet-staging
cd cet-staging
git checkout claude/cet-command-centre-phase-1-e0jtio
```
> **If git asks for a login**, paste a GitHub *Personal Access Token* as the
> password, **or** skip git: on GitHub open the branch → *Code ▸ Download ZIP*,
> upload it via cPanel *File Manager* into `cet-staging`, and *Extract* it there.

### 3. Install dependencies
```bash
composer install --no-dev --optimize-autoloader
```
> No `composer` command? Run:
> `php -r "copy('https://getcomposer.org/installer','c.php');" && php c.php && php composer.phar install --no-dev --optimize-autoloader`

### 4. Configure
```bash
cp .env.example .env
php artisan key:generate
nano .env        # edit the values below, then Ctrl+O, Enter, Ctrl+X to save
```
Set these in `.env` (leave every integration key BLANK for now):
```
APP_ENV=staging
APP_DEBUG=true
APP_URL=https://staging.centralexecutivetransfers.co.uk
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=..._cetstaging
DB_USERNAME=...
DB_PASSWORD=...
MAIL_MAILER=log
CET_SEED_PASSWORD=ChooseATestPassword1!
```
> Blank keys = WhatsApp / email / Tide / AI / flights all **log instead of
> sending**, so you test everything without messaging real customers or charging.

### 5. Build the database + demo data
```bash
php artisan migrate --force
php artisan db:seed --force
php artisan db:seed --class="Database\Seeders\DemoSeeder" --force
php artisan storage:link
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

### 6. Open it
Go to **https://staging.centralexecutivetransfers.co.uk** and sign in.

**Logins** (password = the `CET_SEED_PASSWORD` you set above):
| Role | Email |
|------|-------|
| Admin (Abdi) | abdi@centralexecutivetransfers.co.uk |
| Admin (Maj) | maj@centralexecutivetransfers.co.uk |
| Corporate client | bookings@jeld-wen.example |

To reset the demo at any time:
`php artisan migrate:fresh --seed --force && php artisan db:seed --class="Database\Seeders\DemoSeeder" --force`

> Stuck on any step? Copy the exact error text to me and I'll tell you the fix.

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
