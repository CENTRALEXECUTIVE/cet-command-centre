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

## Phase 2 — Despatch & driver operations

- **Live despatch board** (admin): day view with status columns, one-tap manual
  driver allocation, one-tap rotation auto-allocate, and one-tap status changes
  across the full lifecycle (`App\Services\BookingStatusService`).
- **Driver mobile app** (mobile browser, no app store): Today / Tomorrow / This
  Week filters and large one-tap status buttons that capture the driver's GPS
  position at each change.
- **Status engine** with a single source-of-truth transition map on the
  `BookingStatus` enum, full audit trail (actor + GPS at every transition), and
  role-scoped permissions (drivers act only on their own jobs).
- **Live tracking link** generated and WhatsApp'd to the customer the moment the
  driver goes En Route; public token-based page (`/track/{token}`).
- **Automated WhatsApp** (Twilio, with a log fallback when unconfigured):
  instant booking confirmation plus scheduled 24h and 2h reminders, delivered by
  the `cet:send-due-messages` scheduled command.

## Phase 3 — Tracking, audit, compliance & payments

- **GPS tracking** (`DriverLocationService`): the driver app pings every 5
  minutes **only while on an active job**; pings are stored against that job and
  the server tells the app to stop once the job ends (location off when not
  working). Retained ≤90 days and pruned by `cet:prune-gps`.
- **Live tracking map feed**: the public tracking page polls a token-protected
  JSON endpoint for the driver's latest position.
- **Full audit trail** (`LogsActivity` trait): every create/update/delete on
  bookings and customers is logged with user, IP, user agent and before/after
  values; surfaced on the booking page alongside the status/GPS history.
- **Fleet & compliance tracker** (`ComplianceService`): syncs MOT, insurance,
  PHV vehicle licence, compliance test, PHV badge and DBS dates; classifies
  valid / due-soon / expired; sends automated WhatsApp renewal reminders via
  `cet:check-compliance`. Admin dashboard at `/compliance`.
- **Payments** (`PaymentService` + `TideService`): card bookings auto-generate a
  Tide hosted payment link (no card data stored), cash is flagged separately,
  and account bookings are auto-invoiced with VAT by `InvoiceService` /
  `cet:generate-invoices`. Invoices viewable per role at `/invoices`.

### Scheduled commands

`cet:send-due-messages` (every minute) · `cet:prune-gps` (daily) ·
`cet:check-compliance` (daily) · `cet:generate-invoices` (monthly).

## Phase 4 — Portal, reports, ads & reviews

- **Corporate portal** (`/account`): per-account booking history, spend totals
  and cost-code visibility, scoped so each client sees only their own account.
- **Auto-emailed VAT invoices**: `InvoiceService` emails the `InvoiceMail`
  markdown invoice to the account's billing address on generation and stamps
  `emailed_at`.
- **Revenue reports** (`ReportService`, `/reports/revenue`): earnings by driver,
  bookings by vehicle type, top routes, and period-over-period comparison.
- **Google Ads dashboard** (`AdsDashboardService`, `/reports/ads`): live ROAS,
  spend vs revenue, and the budget trigger alerts (40 conversions, 100 jobs,
  £14,000 revenue).
- **Automated review requests**: a WhatsApp review request is scheduled 30
  minutes after a job is marked Complete and delivered by the scheduler.

## Phase 5 (in progress) — AI pricing engine

- **AI pricing engine** built on **claude-opus-4-8** (`AiPricingEngine`): a
  transparent rule-based fare (`PricingRuleEngine` — base + distance + time +
  airport surcharge + night band + bank-holiday surcharge, floored at a minimum)
  is reviewed by Claude for demand/context, with the AI price clamped to a safe
  band (−25% / +50%) and a full fallback to the rule price when the AI is
  unavailable. Distance via Google Distance Matrix (`DistanceService`) or
  operator-supplied values. Quote → confirmed booking prefill at `/quotes`.

- **Fixed-price matrix** (`FixedPriceService`) — how CET actually prices its core
  airport/port work. A fixed fare for an (origin zone → destination, per vehicle
  type) is **authoritative** over distance/time pricing. Zones resolve from an
  explicit selection or the pickup postcode's outward code. Load the full matrix
  from an ETO CSV export with `php artisan cet:import-fixed-prices <file.csv>`;
  a verified starter subset is seeded.

- **Waiting list** (`WaitingListService`): when a booking is cancelled, every
  customer waiting for that date (and matching vehicle type) is auto-notified by
  WhatsApp that a slot has opened. Admin management at `/waiting-list`.
- **Missed-call auto-response** (`MissedCallService`): a secret-guarded
  `webhooks/missed-call` endpoint logs the call and instantly WhatsApps the
  caller so no booking is lost; known callers are linked to their customer.

> Remaining Phase 5 (needs third-party credentials / a live deployment to verify
> end-to-end): flight-delay monitoring and Twilio number masking.

## ETO data migration

- **`cet:import-eto-bookings <export.csv> [--dry-run]`** imports historical
  bookings from an ETO CSV export (`EtoBookingImporter`). Bookings are created
  directly — no live side effects (WhatsApp/calendar/rotation) fire on
  historical data — mapped to internal vehicle types, statuses and payment
  methods, with airports auto-detected from the address and original timestamps
  preserved. Rows are de-duplicated on the ETO reference (`external_reference` /
  `source_system`), so re-running is safe, and "Request quote" rows are skipped.
  Validated against the real export: 451 bookings imported, 6 quotes skipped, 0
  errors, fully idempotent on re-run.
- **ETO zone KML builder** (`tools/zones/`): generates a single ETO-importable
  KML of all pricing zones from real ONS-derived postcode boundaries (Doogal),
  with council-boundary rules encoded — no hand-drawn coordinates. See
  `tools/zones/README.md` (needs network access to doogal.co.uk to fetch).

### Test coverage

```bash
php artisan test
```

62 passing tests across authentication, the rotation engine (all rules), booking
creation/validation, the despatch board, the driver app (incl. GPS capture &
tracking), WhatsApp confirmations/reminders, GPS storage/prune, the audit trail,
the compliance tracker, Tide payments + VAT invoicing, the corporate portal,
revenue reports, the Google Ads dashboard, automated review requests, and the AI
pricing engine (rule maths, night/bank-holiday surcharges, AI clamping &
fallback).

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

UK GDPR throughout: HTTPS forced in production, least-privilege RBAC, full audit
log, consent capture at every collection point, ICO number on customer-facing
pages, GPS retained ≤90 days then auto-deleted, bcrypt password hashing,
login/form rate limiting, and card-details-never-stored (Tide payment links only).

**Right to erasure** (`/gdpr/erasure`, admin): a two-step workflow
(`DataErasureService`) anonymises and soft-deletes a customer, redacts their
messages/bookings PII, deletes addresses/GPS/waiting-list, withdraws consent,
and **purges PII-bearing audit logs** while leaving one clean erasure record and
the anonymised booking financials for accounting.

## Deployment

See **`docs/DEPLOYMENT.md`** for the full GoDaddy cPanel runbook (MySQL setup,
`.env`, data import, cache commands, scheduler/queue cron, integration webhooks,
smoke test, rollback, and the go-live GDPR checklist).

---

## Roadmap

Phase 2 despatch board & driver app · Phase 3 GPS / compliance / payments ·
Phase 4 corporate portal & reports · Phase 5 AI pricing, flight delays, number
masking · Phase 6 ETO cutover.
