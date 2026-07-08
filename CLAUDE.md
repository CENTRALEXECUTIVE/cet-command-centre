# CET Command Centre — project guide for Claude

This file is read automatically by Claude Code. It carries the context and the
rules any Claude account needs to continue this project safely. **Read it before
making changes.** Keep it up to date.

## What this is

**CET Command Centre** — the in-house booking, dispatch and admin system for
**Central Executive Transfers Ltd**, a Sheffield executive-chauffeur firm
(Company No. 15749931, Operator Licence OP037). It is replacing **EasyTaxiOffice
(ETO)**. Run by Abdi and Maj (the two directors/admins).

- **Stack:** Laravel 11 (`^11.31`), PHP 8.2, Blade views, Eloquent.
- **Database:** MySQL in production, SQLite in tests.
- **Frontend:** server-rendered Blade + plain CSS (`public/css/app.css`). No JS build step.
- **Brand:** Gold `#FBBA2A`, black, white. Inter font. Keep it clean and premium.
- **AI model:** `claude-opus-4-8` via `App\Services\AnthropicService`.
- **Cost rule:** everything free where possible — only Google Maps and Anthropic cost money.

## How to work here

- **Branch:** develop on `claude/cet-command-centre-phase-1-e0jtio`. Commit with
  clear messages and push (`git push -u origin <branch>`). Never push elsewhere
  without permission. Only open a PR if explicitly asked.
- **Tests:** `php artisan test`. Every feature gets a PHPUnit feature test
  (`RefreshDatabase`). Keep the suite green before committing.
- **Deploy (what the operator actually runs):** `git fetch` + `git reset --hard
  origin/<branch>` + `php artisan optimize:clear`. **They often skip
  `php artisan migrate --force`** — so if a change needs a migration, TELL THEM
  explicitly to run it, and prefer solutions that don't add migrations when
  reasonable.
- Migrations that would pollute test runs are guarded with
  `if (app()->environment('testing')) return;`. `phpunit.xml` pins
  `APP_TIMEZONE=UTC` for deterministic time tests.

## Non-negotiable rules (safety)

1. **Never edit or delete a Google Calendar event automatically.** Bookings can
   be edited/cancelled in the app, but that must NOT touch the calendar. If a
   calendar event needs removing, the operator does it by hand. Only ever act on
   a calendar event when the user names it explicitly and reconfirms.
2. **Nothing auto-sends to customers.** All customer WhatsApp messages are
   manual via `wa.me` deep links the operator taps. No paid messaging API.
3. **Timezone is UK / `Europe/London` (BST in summer).** ETO's journey times are
   **already UK local** — the same clock shown on the ETO booking screen, the
   confirmation email, and the calendar. Parse them in the app timezone
   (`createFromFormat($fmt,$val,config('app.timezone'))`) — do **NOT** treat them
   as UTC and add a BST hour. (An earlier UTC→London conversion in the CSV import
   and ETO audit silently pushed summer pickups an hour late and made the audit
   raise phantom "pickup time differs" flags; the email path was always correct.)
   Getting this wrong nearly sent a customer the wrong pickup time — treat time
   handling as high-risk.
4. **Office WhatsApp Business number: `+447405172435`.** Drivers' "message the
   office" button and driver-detail messages use it.
5. **Secrets:** the Google Maps key was shared in chat once — it must be
   regenerated after go-live. Never commit API keys.
6. **Model identity:** never put the model id `claude-opus-4-8` in commits, PR
   text, code comments, or anything pushed to the repo. Chat only.

## Roles & users

`App\Enums\UserRole`: `admin` (Abdi & Maj — full control), `driver`,
`corporate_client` (JELD-WEN, LB Foster, Forged Solutions).

- **Super admin** = `users.is_super_admin` boolean + role `admin`. Only a super
  admin can create/edit admin accounts or grant super-admin. See
  `User::isSuperAdmin()`.
- **User management** lives at **`/users`** (`Admin\UserController`), linked in
  the sidebar under **Fleet & admin → Users**. Create drivers, corporate
  clients, and (super-admin only) other admins there.

## Booking / calendar format (CET rules)

Calendar events are built by `App\Services\CalendarEventBuilder`. Key rules:

- **Title:** `*[emoji ]Name WHERE (TAG)*` — bold asterisks, `WHERE` is the
  airport code / destination word, `TAG` is ALWAYS a person (driver callsign
  ABDI/MAJ/COVER/named driver), never a vehicle type.
- **Emojis:** 💰 cash outstanding, 👀 card/Square/Stripe balance (outbound/one-way
  only), 🚼 child/booster/infant seat, none = fully paid.
- **Location field = the pickup address.** **Start** = pickup time, **end** =
  +1h, timezone `Europe/London`.
- **Description** = the "📑 Booking Confirmation" block (Date, Customer, Contact,
  Passengers, Luggage, Flight, Meet & Greet, Pickup, Drop-off, Vehicle, Payment,
  Ref, Notes). Notes carry "Booked by X" (the booker), the greeting/lead is the
  LEAD PASSENGER not the booker.

## ETO import & audit

- **Imports** (`/imports`, `Admin\ImportController`): upload the ETO bookings
  CSV or a Google Ads report. Keyed by reference — no duplicates, calendar
  untouched. Files are read from the temp upload and never stored (customer PII).
- **ETO audit** (`/audit`, `Admin\AuditController` +
  `Services\Import\EtoAuditService`), sidebar **Fleet & admin → ETO audit**:
  upload the ETO CSV and it reconciles every booking one reference at a time —
  exists in the system, on the calendar, synced, title in CET format, **pickup
  location hasn't dropped off the event**, details block present, pickup
  date+time match ETO and the calendar, addresses present, fare stored. Problems
  are written to `booking.meta['audit_issues']` and show a ⚠ marker on the
  booking page and in the bookings list. Read-only against the calendar.
- Real ETO CSV columns the code relies on: `Journey date`, `Reference number`,
  `Lead passenger name`, `Passenger name`, `Status`, `Total`, `Pickup`,
  `Dropoff`, `Payments`, `Vehicle type`, `Source`. Semicolon **or** comma
  delimited. "Request quote" rows are skipped.
- The audit page also has a **search** (`?q=`) to reconfirm a single booking by
  reference or customer name without a CSV, and lists results **newest-first**.

## New booking from a message (paste → preview → confirm)

- **`/intake`** (`Admin\BookingIntakeController` + `Services\BookingIntakeService`),
  sidebar **Sales → Paste a booking** (also linked on the ETO audit page). The
  operator pastes a booking message; the AI extracts the fields and a **non-saved
  preview** of the exact CET title + details block is shown. NOTHING is created
  and NOTHING touches the calendar until the operator reviews and confirms.
- On confirm: creates customer + booking, allocates a rotation driver for
  executive saloons, builds the calendar event, and pushes to Google **only when
  the integration is live** (else left pending — never silently dropped). Pickup
  time is UK-local so it doesn't shift. `CalendarEventBuilder::preview()` renders
  the format without saving.

## Driver rotation

- **`/rotation`** (`Admin\RotationController`), sidebar **Fleet & admin → Driver
  rotation** — read-only. Shows the order (Abdi → Maj), who's up next per airport
  × executive vehicle type (from `RotationState`), and the `RotationLog` history.
  The engine is `Services\RotationService` (allocate advances the pointer; return
  legs keep the same driver; substitutes don't advance). Nothing on the page
  changes the rotation.

## Other built areas (quick map)

- **Dispatch board** `/despatch`, **Live map** `/fleet`, **Review** `/review`
  (Completed vs Reserved revenue, hidden data-check, Google Ads analysis).
- **Driver app** `/driver/*`: my jobs, job screen with live tracking indicator,
  "message the office" button, FR24 flight deep links. Cancel/no-show are
  admin-only.
- **Drivers directory** `/cover-drivers`. Directory drivers merge into existing
  admin/director accounts — no duplicate driver logins
  (`DriverRosterService`). Driver numbers carry `+44`.
- **Messaging:** `Services\Messaging\BookingNotifier` builds the exact office
  reminder wording (`*Booking Reminder*` / `Hi {lead first name},` / `*Driver
  details*` bullets / `*Central Executive Transfers*`), sent manually, 08:00–23:00
  window, rendered at send time.

## PWA / app experience

- Installable app: `public/manifest.webmanifest`, `public/sw.js`,
  `public/offline.html`, icons in `public/icons/` (regenerate with GD if the
  brand changes). Head tags + SW registration live in `partials/pwa.blade.php`
  (included by the app layout and the login page). Laravel routes serve the
  three files too so tests/odd servers work; bump `CACHE_VERSION` in `sw.js`
  when precached assets change.
- **Privacy rule in the service worker: only static assets are cached — never
  HTML/pages/booking data.** Offline navigation falls back to `offline.html`.
- **Customer tracking** `/track/{token}`: public, throttled, token is the
  secret. Shows driver FIRST name only (`Booking::driverPublicName()`), status
  message (`trackingMessage()`), live map only while en_route/arrived/collected.
  Terminal status caps the link expiry to +2h (`BookingStatusService`).
- **Driver GPS**: pings only while on an active job (server rejects otherwise),
  driver has a visible start/stop sharing toggle on the job screen, pruned
  after 90 days (`cet:prune-gps`). Fleet map flags stale GPS (> 2× ping
  interval) in red/faded.

## Handover to another Claude account

Everything is in Git — nothing lives only in a chat. To continue elsewhere:
point Claude Code at the same repo (`centralexecutive/cet-command-centre`) and
the same branch, and it inherits this file. Brief it: "CET Command Centre,
Laravel 11, continue on branch `claude/cet-command-centre-phase-1-e0jtio`."
