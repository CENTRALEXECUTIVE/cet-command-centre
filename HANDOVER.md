# CET Command Centre — session handover (Oct 2026)

Hand this, plus `CLAUDE.md`, to the next Claude Code session. It captures the
current state, what was built recently, how to continue, and the live go-live
checklist. **No secrets are in this file** (keys live in the staging database).

## Where to continue

- **Repo:** `centralexecutive/cet-command-centre`
- **Working branch:** `claude/cet-command-centre-guide-r1azew` (develop + push here;
  this overrides the older `phase-1` branch named in CLAUDE.md).
- **Deploy (staging only — NEVER touch `public_html`):**
  ```
  cd /home/u2beq0g0k7mj/cet-staging && git fetch origin claude/cet-command-centre-guide-r1azew \
    && git reset --hard origin/claude/cet-command-centre-guide-r1azew \
    && php artisan migrate --force && php artisan optimize:clear && php artisan cet:opcache-clear
  ```
  The operator runs server commands (this session can't reach the staging DB).
  Config changes REQUIRE `optimize:clear`. No new migrations were added in this
  run, but run `migrate --force` anyway in case staging is behind.
- **Tests:** `php artisan test` (SQLite). Suite is green at **1592 passing**.
  A fresh container needs `composer install` + `php artisan key:generate` first.

## Current focus: GO-LIVE on card payments + email, then embed the widget

Two companies, routed by VAT (see CLAUDE.md "Card payments — two entities"):
- **VAT invoice** → Central Executive Transfers **Ltd** → **Square**.
- **No VAT** → sister company Central Executive Transfers **PVT LTD** → **Stripe**.

### Status of go-live tasks
- [x] Square keys entered in Settings → payments (VAT company). Webhook added in
      Square (`payment.updated` → `/webhooks/square`), signature key saved.
- [x] Stripe keys entered in Settings → payments (PVT LTD). Webhook added as a
      Stripe "event destination" (Snapshot payload, `checkout.session.completed`
      → `/webhooks/stripe`), signing secret saved.
- [ ] **Email/SMTP** — the blocker. `MAIL_MAILER` defaults to `log` (nothing
      sends). Operator must set real SMTP in the staging `.env` (cPanel email
      account), then verify with **Settings → 📧 Email → "Send test email"**.
      Needed: `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT` (465 ssl / 587 tls),
      `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION`, `MAIL_FROM_ADDRESS`,
      `CET_OPS_EMAIL` (office copy; defaults to admin@centralexecutivetransfers.co.uk).
- [ ] **Full end-to-end test** (customer = the operator): book on the widget with
      a real card → pay → thank-you page → customer paid-confirmation email +
      office "New web booking" and "Web booking PAID" emails/alerts → booking
      shows Paid + auto-allocated. Use the **🧪 £1 test card link** on a booking
      first (charges £1 for real via the booking's provider, uses a TEST-
      reference so it NEVER marks anything paid; refund in Square/Stripe).
- [ ] **Embed the widget on the real website.** MUST NOT edit `public_html`
      (production off-limits). Give the operator the embed snippet to paste;
      don't touch the live site. Ask how the widget is embedded (iframe/script).

### Open decisions to confirm with the operator
1. **Cover invoices** currently use the **main Square** account. Move to
   Stripe/PVT LTD, or leave on Square?
2. **Stripe live/test** — there's no live/test toggle in the Command Centre; the
   key prefix (`sk_live_` vs `sk_test_`) decides. Operator may test with test
   keys first then swap to live. A toggle could be added if wanted.

## What was built THIS session (newest first)

| Commit | What |
|---|---|
| 330ae9c | Settings: email status panel + one-click "Send test email" (admin). |
| ca1f552 | `PaymentGateway` routes public/widget + office card links by provider (Stripe for no-VAT, Square for VAT). Admin "↺ Reset payment to unpaid" to undo a test. |
| 584352e | 🧪 £1 test card link on a booking — real charge, TEST- ref, never marks paid. |
| 6d61951 | Explicit **VAT / No-VAT** two-button toggle on the booking; re-stamps billing entity so it switches company+provider even on pre-stamped bookings. |
| 08528fb | **Stripe** integration for the no-VAT sister (PVT LTD): `StripePaymentService` (hosted Checkout, `checkout.session.completed` webhook, signature verify), entity-aware invoices (`InvoiceProfile::companyFor($vat)` → PVT LTD, no VAT number), Settings fields, `/webhooks/stripe`. |
| bf64632, e8d916c | **Festive & special-event surcharges** (Halloween +60%, Christmas Eve +25%, Christmas Day +75%, Boxing Day +50%, NYE +25%/+50% split at 6pm, NYD +80%/+50% split at 6am). Now RECURRING every year (month-day + time match). `config/cet.php` `holiday_surcharges`; applied by `FareCalculator::holidayFactor`. |
| bd5b298, 7bf2c7b | Cover invoices can create a **Square card payment link** for the combined total (COVER- ref, never marks a booking paid), embedded on the invoice + inline AJAX feedback. |
| 1c943b0 | **Read-only masked-line SMS transcript** on a booking (super-admins only, live from Twilio, nothing stored) + **foldable booking sections** (tap-to-collapse, remembered). |
| 3b44c8c | Driver job offer flags **TODAY / TOMORROW** in bold. |
| 51794af | **Flight tracking fix** — Flightradar24 uses IATA (`u2542`) not ICAO (`ezy542`); display shows the clean IATA code so it matches the link. |
| 278ee2a | **Cover invoices** added to the admin menu (was corporate-only). |
| 698963d | Cover-job form: **operator picker** dropdown (fills saved email/phone). |
| d6c910f, 4247efb, 0f33c2b | **Searchable/browsable pickers** for combining bookings onto one invoice and matching a return leg (shared `partials/booking-picker.blade.php`). |

Earlier in the (pre-compaction) session: invoice redesign + VAT-on-top + auto-settle,
booker-billing, customer-import, instant-quote improvements, title-tag driver fix,
customer mis-link fix, match-return cash rules, default-90%-pay, and more — all in
Git history before 51794af.

## Key files (payments)

- `app/Models/Booking.php::billingEntity()` — VAT→transfers (Square), no-VAT→
  chauffeurs (Stripe) when configured, else falls back to transfers.
  `setVatInvoiceRequested()` re-stamps `meta['billing_entity']`.
- `app/Services/Payments/PaymentGateway.php` — the front door; picks provider.
- `app/Services/Payments/StripePaymentService.php` — Stripe (no SDK, raw HTTP).
- `app/Services/Payments/SquareBookingPaymentService.php` — Square.
- `app/Http/Controllers/WebhookController.php` — `square`, `squareChauffeurs`,
  `stripe` handlers. Routes: `routes/web.php` (`webhooks/*`, CSRF-exempt).
- `app/Support/InvoiceProfile.php::companyFor($vat)` — entity-aware invoice company.
- Settings UI: `resources/views/admin/settings/index.blade.php` +
  `app/Http/Controllers/Admin/SettingsController.php` (Square, Stripe, PVT LTD
  details, email test).

## Credentials — where they live (DO NOT COMMIT)

All payment keys were entered via **Settings** and are stored in the staging
**database** (`settings` table), which persists across deploys/rebuilds. They are
NOT in the repo and must never be committed. The operator keeps a copy in a
password manager; they can also be re-copied from the Square/Stripe dashboards.
The office email address (`CET_OPS_EMAIL`) and SMTP live in the server `.env`.
