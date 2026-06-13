# CET Command Centre

Custom booking & despatch platform for **Central Executive Transfers Ltd**
(Company No. 15749931, Operator Licence OP037, Sheffield City Council).
Built on **Laravel 11 / PHP 8.4 / MySQL**. Replaces ETO and surpasses KISS
Despatch. AI features use **claude-opus-4-8**.

Brand: Gold `#FBBA2A`, Black `#0b0b0b`, Inter.

---

## Phase 1 — Foundation (this milestone)

Delivered and tested:

- **Full database schema** (16 migrations) covering every module across all six
  phases: users/roles, fleet & vehicles, driver profiles, corporate accounts &
  contacts, customers (CRM), rotation engine, bookings + stops + status history,
  calendar events, GPS tracking & tracking links, payments/invoices,
  communications (WhatsApp/SMS/email/push), compliance tracker, AI pricing,
  flight monitoring, ad metrics, and governance/GDPR (audit log, consents,
  erasure requests, settings).
- **Role-based authentication** with three roles — **Admin** (Abdi & Maj),
  **Driver**, **Corporate Client** (JELD-WEN, LB Foster, Forged Solutions) —
  enforced by the `role` middleware (principle of least privilege), rate-limited
  login, deactivated-account blocking, and login/logout audit trail.
- **Rotation engine** (`App\Services\RotationService`) implementing the company
  rules exactly: Executive saloon only; per-airport/per-vehicle-type pointer;
  paired outbound+return share a driver and advance once; substitutions retain
  the original's position; unknown airports default to Abdi.
- **Smart booking form** with full server-side validation (capacity checks,
  mandatory corporate cost codes, GDPR consent, future-pickup, return legs, via
  stops) that creates the customer, both journey legs, the consent record, the
  formatted **calendar event** (bold title, 💰/👀 emoji, +1h end, notification
  offsets) and runs rotation allocation.

### Test coverage

```bash
php artisan test
```

22 passing tests across authentication, the rotation engine (all rules), and
booking creation/validation.

---

## Local setup

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite     # local dev uses SQLite
php artisan migrate --seed
php artisan serve
```

Seeded logins (password = `CET_SEED_PASSWORD`, default `ChangeMe!2026`):

| Role             | Email                                       |
|------------------|---------------------------------------------|
| Admin (Abdi)     | abdi@centralexecutivetransfers.co.uk        |
| Admin (Maj)      | maj@centralexecutivetransfers.co.uk         |
| Corporate client | bookings@jeld-wen.example (+ LB / Forged)   |

> Change all seeded passwords immediately after first login.

---

## Production (GoDaddy cPanel)

- Home dir `/home/u2beq0g0k7mj`, deploy under `public_html`.
- Set `DB_CONNECTION=mysql` and the MySQL credentials in `.env`.
- Secrets live in `.env` only — never in code.
- HTTPS only; `APP_ENV=production`, `APP_DEBUG=false`.
- After every deploy:

  ```bash
  cd ~/public_html && php artisan view:clear
  php artisan route:clear   # when routes change
  ```

- If `.php` upload is blocked, zip and **Extract** on the server.

### Blade gotchas observed

- Inline `<script>` is wrapped in `@verbatim … @endverbatim`.
- Front-end CSS is a static file (`public/css/app.css`) — no build step, and no
  Blade parsing of CSS / `@` tokens.
- Email addresses in Blade use `@@` to escape the `@`.

---

## GDPR & security

UK GDPR throughout: encrypted-at-rest/in-transit deployment, least-privilege
RBAC, full audit log, right-to-erasure workflow, consent capture at every
collection point, ICO number on customer-facing pages, GPS retained ≤90 days
then auto-deleted, bcrypt password hashing, login/form rate limiting, and
card-details-never-stored (Tide payment links only).

---

## Roadmap

Phase 2 despatch board & driver app · Phase 3 GPS / compliance / payments ·
Phase 4 corporate portal & reports · Phase 5 AI pricing, flight delays, number
masking · Phase 6 ETO cutover.
