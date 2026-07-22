# CET Command Centre — project guide for Claude

This file is read automatically by Claude Code. It carries the context and the
rules any Claude account needs to continue this project safely. **Read it before
making changes.** Keep it up to date.

## STATUS: THE LIVE WEBSITE IS SORTED. DO NOT TOUCH IT.

The live site at **centralexecutivetransfers.co.uk** is fully restored and working.
Homepage, hero section, fleet images, navigation menu, all 25 SEO pages and the legal
pages are live and correct. It was fixed manually and it is **done**.

Do not attempt to help with it, verify it, repair it, improve it, or "check" it.
It needs nothing from you.

## CRITICAL: PRODUCTION IS PERMANENTLY OFF LIMITS

**NEVER read from, write to, modify, move, delete, or overwrite anything under:**

```
/home/u2beq0g0k7mj/public_html
```

That directory is the live public website. It is business-critical: it is the company's
main source of customer bookings and its SEO rankings took months to build.

This includes, without exception:
- `public_html/routes/` — especially `web.php`
- `public_html/resources/views/` — especially `layouts/`, `livewire/`, `frontend/`
- `public_html/public/` — images, sitemap.xml, robots.txt
- `public_html/app/`
- `public_html/.env`
- Anything else anywhere beneath `public_html`

If a task appears to require touching `public_html`: **STOP and ask first.**
Do not proceed. Do not "helpfully" repair, restore, or patch anything in that directory.
Explain what you think needs doing and wait for explicit written confirmation.

### Why this rule exists

On 11 July 2026, work on this project overwrote
`public_html/resources/views/layouts/app.blade.php` and rewrote
`public_html/routes/web.php`. This took the live website down, broke the homepage, and
orphaned 28 named routes that the site's header depended on. Recovery took five hours.
There were no server backups. This must never happen again.

### Where the Command Centre actually runs

- The Command Centre is served from **`/home/u2beq0g0k7mj/cet-staging`** (a git
  checkout of this repo) at **staging.centralexecutivetransfers.co.uk**, with its
  own database (`cetstaging`). Deploys go THERE and nowhere else:
  `cd ~/cet-staging && git fetch origin <branch> && git reset --hard origin/<branch>
  && php artisan migrate --force && php artisan optimize:clear`.
- Test bookings, Twilio sessions and driver assignments run against **staging only**.
- Never create test data, test bookings, or Twilio sessions on the live site.
- Never add Twilio credentials or any live API keys to a staging `.env`, and never
  move live credentials between environments.
- If a change is destructive (overwrite, delete, move, or rewrite of a routes or
  config file), state clearly what you are about to do and get confirmation first.
  Prefer creating new files over modifying existing ones.
- If something breaks: do not silently self-repair by rewriting routes, layouts or
  config files. Report what broke and what you were doing, then wait for instructions.

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
- **Deploy — to `~/cet-staging` ONLY** (see the production-off-limits rule above;
  `public_html` is the live website, a different app, and is never touched):
  `cd ~/cet-staging && git fetch origin <branch> && git reset --hard
  origin/<branch> && php artisan migrate --force && php artisan optimize:clear`.
  **The operator sometimes skips `migrate`** — if a change needs a migration,
  TELL THEM explicitly, and prefer solutions that don't add migrations when
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
- **Driver push notifications (Web Push, free):** `App\Services\Push\WebPushService`
  (uses `minishlink/web-push` — run `composer install` after pulling). VAPID keys
  live in `.env` (`VAPID_PUBLIC_KEY`/`VAPID_PRIVATE_KEY`, generated once with
  `php artisan cet:make-vapid`); no-op until they're set. Drivers opt in from
  **My jobs** (`public/js/cet-push.js` → `push_subscriptions` table). Allocating a
  job pushes "New job — …" to the driver (`BookingStatusService`). `sw.js` has the
  `push`/`notificationclick` handlers. iOS needs the app installed to the Home
  Screen (16.4+); Android works either way. Push goes to DRIVERS (internal) — the
  "nothing auto-sends to customers" rule is unaffected.
- **Customer tracking** `/track/{token}`: public, throttled, token is the
  secret. Shows driver FIRST name only (`Booking::driverPublicName()`), status
  message (`trackingMessage()`), live map only while en_route/arrived/collected.
  Terminal status caps the link expiry to +2h (`BookingStatusService`).
- **Driver GPS**: tracking starts on the **Set off** tap (En Route) and stops on
  Complete — the server rejects pings outside en_route/arrived/collected
  (`Booking::scopeTracked`). Driver has a visible start/stop sharing toggle on
  the job screen; pruned after 90 days (`cet:prune-gps`). Fleet map flags stale
  GPS (> 2× ping interval) in red/faded, with heading-arrow markers.

## Number masking (Twilio Proxy)

- **Drivers NEVER see a customer's real number** — the driver job screen shows
  only the masked line from the open `ProxySession` (`Booking::
  driverContactNumber()`), or "via the office" when masking is off. Customers
  get the masked line in driver-details messages (`customerMaskedNumber()`).
  Admins keep full visibility everywhere else.
- `Telephony\TwilioProxyService` (raw HTTP, **no SDK — deliberate**, so deploys
  need no composer step): opens a session on **Allocated**, closes on
  complete/cancel/no-show/decline/un-tie, swaps on reassign; session expiry =
  drop-off + 4h (`cet:close-proxy-sessions` every 5 min is the safety net,
  Twilio `DateExpiry` backs it up). Silent no-op until
  `TWILIO_PROXY_SERVICE_SID` is set (uses existing `TWILIO_SID`/`TWILIO_AUTH_TOKEN`).
- Audit: `proxy_events` (opens/closes/webhook callbacks at
  `/webhooks/twilio-proxy`, secret-guarded; message BODIES are stripped —
  metadata only). Purged on the 90-day GPS schedule. WhatsApp masking is out
  of scope. The legacy single-number bridge (`MaskingService`, `/webhooks/voice`)
  still works as a fallback.

## Status watchdog & alerts (ops room)

- **`cet:status-watchdog`** runs every minute: driver push nudges (set off at
  drive-time+10min before pickup clamped 20–60 min — flat 30 without GPS;
  urgent at pickup−10; geofence-detected arrived/POB/complete, ≥2 consecutive
  pings, skipped when GPS stale >3 min; clock-based complete fallback). Max 2
  sends per type per job (`job_nudges` table). Covers allocated AND accepted.
- **Admin escalations** (same run, `Watchdog\AdminAlerts`): unallocated ≤2h
  before pickup (critical, every 30 min until allocated), driver unacted 5 min
  after the 2nd nudge (critical, once), GPS lost >5 min mid-job (warning,
  once); no-show/cancel and calendar imports push immediately. Per-admin prefs
  in `users.notification_preferences` (super-admin page **Settings →
  Notifications**), incl. a critical-only master switch + optional chime.
- **Alerts feed**: `watchdog_events` (30-day retention, pruned by
  `cet:prune-gps`) → dashboard control-tower panel (30s poll, acknowledge
  flow) + unack-critical badge in the topbar (crash-safe pre-migration).
- Pickup/drop-off coords are geocoded lazily into `booking.meta['geo']` by the
  watchdog (CLI-side); geofence rules skip gracefully without them.
- **Ops-room theme**: navy glass shell driven by CSS variables in `:root`
  (`--bg`, `--panel`, `--accent`, `--glow`, `--blur`); dashboard radar (dark
  map backdrop + glass KPIs + next-4h countdown rail), glowing dispatch
  columns + live ping-age chips, dark fleet map with heading arrows.
  `prefers-reduced-motion` and no-backdrop-filter fallbacks throughout.

## Handover to another Claude account

Everything is in Git — nothing lives only in a chat. To continue elsewhere:
point Claude Code at the same repo (`centralexecutive/cet-command-centre`) and
the same branch, and it inherits this file. Brief it: "CET Command Centre,
Laravel 11, continue on branch `claude/cet-command-centre-phase-1-e0jtio`."
