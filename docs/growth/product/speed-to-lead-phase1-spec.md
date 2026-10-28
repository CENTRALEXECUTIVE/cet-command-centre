# Speed-to-lead — Phase 1 spec (within current rules)

Owner: product · Date: 28 Oct 2026 · Status: ready for build review
Builds on: `docs/growth/PLAYBOOK.md` "AI speed-to-lead (design)". Includes the
**unpaid card bookings chase list** mini-spec (part 2).

## Hard boundary

**Nothing in Phase 1 sends anything to a customer automatically.** Every customer
message is drafted by the system and sent by a director pressing a button
(wa.me deep link, Graph "Send from Outlook", SMTP email, or `tel:`). Alerts and
escalations go only to the directors (internal), exactly like the driver/watchdog
pushes today. Calendar untouched. `public_html` untouched.

Two existing behaviours to be aware of:

- **Widget quote/booking requests already get an automatic confirmation email**
  when the director-approved setting `widget_customer_emails` is on (default on,
  `CustomerBookingMailer`). Phase 1 does not change that, and adds no new automatic
  send. Any further automatic reply (Phase 2 "we'll send your price in 15 minutes")
  needs the directors to change rule 2 explicitly.
- **`MissedCallService` auto-sends a WhatsApp to the caller** when Twilio WhatsApp is
  configured. That's against rule 2. Phase 1 removes the send and replaces it with
  an office alert (phase 1c).

## 1. Problem

Leads reach CET by four routes and none of them is timed or escalated:

| Route | Today (verified in code) | Gap |
|---|---|---|
| Widget quote request / unconfirmed web booking | Pending `Booking` (`source=web`, `meta['quote_request']`), **info** push "New quote request" + office email (`BookingWidgetController::store`, `PublicBookingController::store`) | Info severity: auto-fading snackbar, muted by "critical only", no timer, no escalation |
| Email enquiry | `cet:ingest-enquiries` **every 10 min** → `EmailEnquiry` with AI extraction, price, draft reply; `/enquiries` | **No alert at all**; up to 10 min before it even exists; possible AI re-processing leak (step 0) |
| Missed call | `/webhooks/missed-call` → `missed_calls` row + automatic WhatsApp | No screen, no alert, auto-send breaks rule 2 |
| WhatsApp to +447405172435 | Lands in the WhatsApp Business app on a phone; no API (deliberate — no paid messaging) | Invisible to the system; can only be logged by hand |

Target (PLAYBOOK): **every enquiry answered within 5 minutes, 07:00–23:00.**
Industry studies (vendor-reported, so treat as a rough guide) link replying within
5 minutes to much higher contact and conversion rates than replying after 30
minutes or more.

## 2. Users

- **Abdi and Maj (super-admins)** — receive alerts, reply, often while driving
  between jobs. Must never be alerted loudly while carrying a passenger
  (`User::busyForAlerts()` already handles this).
- **Future office staff (admins, not super)** — can work the inbox.
- **Customers** — indirectly: faster, priced replies.

## 3. User stories

1. As a director, when a web quote request or email enquiry arrives I get a push and
   a chime on my phone/dashboard within 2 minutes, showing name, journey, date and
   price, so I can reply before a competitor does.
2. As a director, I open the lead and see a ready reply with the price, vehicle and
   pickup time already filled in, and I send it by WhatsApp or email with one tap.
3. As a director, if neither of us has replied 5 minutes after a lead arrived (in
   working hours), we both get a critical alert, so no lead is ever forgotten.
4. As a director, if I replied from my own phone outside the app, I can mark the
   lead "responded" in one tap so the timer stops.
5. As a director, when I get a WhatsApp enquiry on the office phone, I can paste it
   into "Log a WhatsApp lead"; the AI reads it, prices it and drafts the reply.
6. As a director, missed calls show up as leads with a "Call back" button, and the
   caller is never messaged automatically.
7. As a director, I see each week's median and 90th-percentile first-response time,
   % answered within 5 minutes, conversion to bookings and booked revenue, by
   source and by director.
8. As a director, when a lead becomes a booking it's linked, so the conversion
   counts and the lead leaves the open list.

## 4. Acceptance criteria

**Capture**
- AC1. Every widget/public-form booking with `source=web` that is a quote request or
  not yet paid/confirmed creates exactly one lead (idempotent by source type+id).
  Creating a lead can never break or slow the booking: wrapped in try/catch, logged.
- AC2. Every new `EmailEnquiry` creates exactly one lead. Ingestion runs every
  **2 minutes**, but only after step 0 has shipped.
- AC3. Every missed-call webhook creates one lead (calls from the same number within
  30 minutes merge into the open lead, with a count). **No outbound message is
  created** (no `messages` row, no Twilio Messages API call).
- AC4. "Log a WhatsApp lead" accepts pasted text plus an optional "received at"
  time (default now) and creates a lead; extraction uses one AI call at most.
- AC5. Leads already linked to a confirmed/paid booking, cancelled bookings, test
  bookings (`TEST-` references) and the operator's own test addresses are not
  timed (outcome set straight away).

**Alert**
- AC6. Within 60 s of a lead being created, every active admin who wants
  `new_lead` alerts and is not `busyForAlerts()` gets a web push: title
  "New enquiry — {first name} · {source}", body "{date time} · {from} → {to} · £{price}
  or Price to confirm". Tapping it opens the lead.
- AC7. The lead appears in the alerts feed as `new_lead`; open admin pages play the
  **chime** (not the emergency siren) and show a sticky toast. Silent when the
  viewer is on a live job (`mute_sound`).
- AC8. `new_lead` is a new entry in `User::ALERT_TYPES` (default on). It is sent
  at severity `critical` so the "critical only" master switch does not hide it.
  The feed JSON gains `event_type`, and `public/js/cet-alerts.js` keys the sound off
  it: `new_lead` plays the chime and is excluded from `hasLiveCritical`, which today
  starts the siren for admins with the alarm preference on.

**Respond**
- AC9. The lead page shows the original text/fields, the price (from
  `QuoteService`/`FareCalculator`, never invented by the AI), a draft reply, and
  buttons: **WhatsApp** (if a phone exists), **Email** (Graph reply for email
  leads; SMTP with the "Sending as" label for others; mailto fallback),
  **Call**, **Mark responded**, **Not a lead / spam**, **Convert to booking**.
- AC10. Pressing WhatsApp/Email/Call records `responded_at` (first press only),
  `responded_by` and `response_channel` **before** opening the wa.me/mailto/tel
  link or sending; a `messages` row (type `lead_reply`) is written for WhatsApp/
  email for the audit trail. Email sends that fail leave the lead unresponded and
  show the error (`MailFailure::report`).
- AC11. The draft reply is a deterministic template (no AI call) using the
  extracted fields and price. An optional **"Polish with AI"** button makes one
  call on demand. The draft never contains the customer's data from any other lead.

**Escalate**
- AC12. A lead unresponded **5 minutes** after `received_at` (clock only runs inside
  the working window, default 07:00–23:00 Europe/London; an overnight lead's clock
  starts at 07:00) raises **one** `lead_unanswered` critical alert to every admin
  not busy (siren on open dashboards for admins with the alarm on, critical push).
- AC13. Optional third tier at **10 minutes**: ring the office line via
  `OfficeAlertCall` with "A customer enquiry has waited ten minutes". Setting
  `lead_call_escalation`, **off by default** (Twilio voice costs money).
- AC14. Escalations fire at most once per tier per lead, survive deploys
  (`optimize:clear` wipes the default cache, so state lives in the `leads` row, not
  cache), and stop the moment a response is recorded or the outcome is set.
- AC15. If every admin is busy, the alert is still written to the feed and the
  lead shows "nobody free" on the stats; no loud sound is played next to a
  passenger.

**Measure**
- AC16. `/leads/stats` shows, for a chosen week (default this week): leads by
  source, median and p90 first response (working-hours minutes), % within 5 min,
  % never responded, conversion to booking %, booked revenue (sum of linked
  bookings' fare), split by director. Self-reported "Mark responded" responses are
  counted but flagged.
- AC17. Dashboard tile: "Leads waiting: N (oldest Xm)" linking to `/leads`.

**Safety/regression**
- AC18. No change to calendar code paths; no customer message sent without a button
  press (test asserts no `Mail`/`Http` send to customer happens during ingestion,
  watchdog or escalation runs).
- AC19. The app never 500s if the `leads` migration hasn't been run: every lead code
  path checks the table once (cached per request) and no-ops, following the
  `WatchdogEvent::criticalCount()` pattern.

## 5. Design

### Step 0 — stop the AI re-processing (prerequisite, no migration)

Problem (verified): `EnquiryInboxService::ingest` and `OutlookBookingService::ingest`
only remember emails that **became** something. Every other email in the window
(newsletters, receipts, replies) goes back to the AI on every run (every 10 min and
every 2 min respectively, up to 1,000 messages each via `GraphMailClient::fetchRecent`).

Fix:
- A `SeenInboundEmail` helper backed by a **dedicated file cache store**
  (`config/cache.php` → `inbox_seen`, file driver, its own path). The default store
  is `database` and is flushed by `optimize:clear` on every deploy; a separate named
  store is not. Key: `sha1(graph_message_id)`, value: verdict (`not_enquiry`,
  `not_booking`, `enquiry:{id}`), TTL 35 days (longer than the 30-day scan window).
- Check before **any** AI call in both services; record after each verdict.
- Cheap pre-filter before the AI: skip `noreply`/`no-reply`/`notifications@`
  senders, CET's own domain, and known platform domains (Square, Stripe,
  Google, Microsoft, GoDaddy, Twilio) — configurable list in `config/cet.php`.
- Optional daily AI call cap (`cet.ai_daily_cap`, default 300) counted in the
  same store; over the cap the service logs and skips (the feature degrades,
  nothing breaks).
- Then move `cet:ingest-enquiries` from every 10 to every 2 minutes.

### Data model

**One new table is truly needed: `leads`.** Reasons: (a) stats across three
different source tables plus WhatsApp leads that have no source row at all;
(b) escalation state must survive deploys — the cache is wiped by
`optimize:clear`, and `job_nudges.booking_id` is NOT NULL, so it can't hold
non-booking leads; (c) `email_enquiries` and `missed_calls` have no `meta` column,
so a no-migration route would mean altering two tables anyway. A new table is
additive and touches no existing table (CLAUDE.md: prefer new over modifying).

```
leads
  id
  source            string(16)   web_quote | web_booking | email | missed_call | whatsapp | phone
  source_type       string(40)   nullable  (App\Models\Booking | EmailEnquiry | MissedCall)
  source_id         unsignedBigInteger nullable      unique(source_type, source_id)
  customer_id       nullable FK customers (nullOnDelete)
  booking_id        nullable FK bookings  (nullOnDelete)   linked booking once converted
  name              string nullable
  phone             string(32) nullable
  email             string nullable
  summary           string(300) nullable  "Thu 6 Nov 05:30 · S11 → MAN · 2 pax · Executive"
  quote_amount      decimal(10,2) nullable
  received_at       timestamp
  alerted_at        timestamp nullable
  escalated_at      timestamp nullable
  escalation_level  tinyint default 0     0 none · 1 five-minute alert · 2 call
  responded_at      timestamp nullable
  responded_by      nullable FK users
  response_channel  string(12) nullable   whatsapp | email | graph | call | other
  outcome           string(12) default 'open'   open | responded | booked | lost | spam
  meta              json nullable          merge count for repeat missed calls, self-reported flag, etc.
  timestamps
  index(outcome, received_at)
```

No full email bodies are copied into `leads` (they stay in `email_enquiries`).
It's a plain additive create, so no testing-environment guard is needed.
**Tell the operator explicitly to run `php artisan migrate --force` for this deploy.**

### Services (new files)

- `App\Services\Leads\LeadRecorder` — `fromWebBooking(Booking)`,
  `fromEmailEnquiry(EmailEnquiry)`, `fromMissedCall(MissedCall)`,
  `fromWhatsAppPaste(string $text, ?Carbon $receivedAt, User $by)`. Idempotent
  `firstOrCreate` on (source_type, source_id). Fires the `new_lead` alert.
- `App\Services\Leads\LeadReplyDrafter` — deterministic template per channel
  (WhatsApp short form, email long form, reusing `EnquiryInboxService::draftReply`
  wording and `NotificationTemplates['quote_request']`). Price from `QuoteService`.
- `App\Services\Leads\LeadEscalator` — called each minute; finds open leads,
  computes working-hours age, applies tiers, writes `escalated_at`/level.
- `App\Services\Leads\WorkingHours` — `minutesOpen(Carbon $from, Carbon $now)`
  within the configured window (`lead_hours_start`/`lead_hours_end` settings,
  default 07:00/23:00, Europe/London — treat as high-risk time code, test BST
  and GMT both).
- `App\Services\Leads\LeadStats` — weekly aggregates for AC16.

### Hooks into existing code (small, wrapped in try/catch)

- `BookingWidgetController::store` and `PublicBookingController::store`: replace the
  info-level `adminAlerts->notify('web_booking', …)` for quote requests/unpaid
  requests with `LeadRecorder::fromWebBooking` (one push, not two). Paid bookings
  keep their existing alerts.
- `EnquiryInboxService::createEnquiry` → `LeadRecorder::fromEmailEnquiry`.
- `MissedCallService::handle` → remove the `WhatsAppService::send` call; call
  `LeadRecorder::fromMissedCall`. Update `MissedCallTest` (it currently asserts the
  auto-response). **This is a deliberate rule-2 alignment; get the directors' sign-off
  in the PR description.**
- `BookingController::store` (when `enquiry_id` is present, existing code marks
  the enquiry booked) → also set `leads.booking_id`, outcome `booked`.
- `BookingStatusService::onFarePaid` / confirm → web lead outcome `booked`;
  cancel → `lost`.

### Routes (admin, `role:admin`)

```
GET  leads                     Admin\LeadController@index         leads.index
GET  leads/stats               Admin\LeadController@stats         leads.stats    (before {lead})
GET  leads/waiting             Admin\LeadController@waiting       leads.waiting  (JSON: count + oldest, for the tile)
POST leads/whatsapp            Admin\LeadController@storeWhatsApp leads.whatsapp throttle:30,1
GET  leads/{lead}              Admin\LeadController@show          leads.show
POST leads/{lead}/respond      Admin\LeadController@respond       leads.respond  throttle:60,1  (channel=whatsapp|email|call|other)
POST leads/{lead}/outcome      Admin\LeadController@outcome       leads.outcome  throttle:60,1
POST leads/{lead}/polish       Admin\LeadController@polish        leads.polish   throttle:10,1  (optional AI)
```

`respond` with `whatsapp` returns a redirect to the wa.me link (built with
`Support\Phone`); with `email` it sends via Graph (email leads) or the mailer
(others) and redirects back; with `call` it redirects to `tel:`. For web leads the
lead page links to the existing booking page (resend confirmation / payment link
already live there).

### Views

- `resources/views/leads/index.blade.php` — open leads oldest-first with a live
  age chip (green < 3 min, amber 3–5, red > 5), source badge, price, buttons; a
  "Done today" fold. Empty state: "No enquiries waiting. New ones appear here and
  on your phone within 2 minutes."
- `resources/views/leads/show.blade.php` — two-column like `enquiries/show`.
- `resources/views/leads/stats.blade.php` — numbers table, no charts library.
- Sidebar: **Sales → Leads** with a count badge, above the existing Enquiries link
  (keep `/enquiries` working; it becomes the email-source detail view).
- Dashboard tile (AC17).
- `public/js/cet-alerts.js` + `AlertsController::buildFeed` changes per AC8. Bump
  the script `?v=`. Optionally a distinct `public/sounds/lead.wav`; bump
  `CACHE_VERSION` in `sw.js` only if the file is precached.

### Scheduler

`routes/console.php`: `Schedule::command('cet:lead-watchdog')->everyMinute()->withoutOverlapping(5)`
— a new command, kept separate from `cet:status-watchdog` so a lead bug can never
stall driver nudges. Writes `Heartbeat::beat('lead-watchdog')`; show it on `/health`.

### Cost

- **No new paid service.** Push is free (VAPID). Email via existing SMTP/Graph.
- **AI:** web leads use structured form fields — **zero AI calls**. Email leads: the
  existing one extraction call (and far fewer after step 0). WhatsApp paste: one call
  via the existing intake extractor. "Polish with AI": on demand only. Optional
  config to point extraction at a cheaper model tier (directors' choice; set via
  `.env`, never named in the repo).
- **Google Maps:** distance pricing for non-matrix journeys — one lookup per lead.
  **Verify** `DistanceService` caches by route pair; if not, add a 30-day cache.
- **Twilio voice:** only if AC13 is switched on.

### Data / privacy impact

- New personal data: name, phone, email and a short journey summary for people who
  may never book. Lawful basis: steps at the person's request before a contract /
  legitimate interest in answering an enquiry.
- **Retention:** leads with outcome other than `booked` are anonymised after
  **12 months** (name/phone/email/summary nulled, timings kept for stats) by a step
  in the existing `cet:prune-gps` daily run. `email_enquiries` currently keeps full
  bodies indefinitely — recommend the same 12-month prune `[CONFIRM with directors]`.
- **AI processing:** email and WhatsApp enquiry text is sent to Anthropic for
  extraction (already true today for email). The privacy notice should name AI
  processing and Anthropic as a sub-processor `[CONFIRM the current notice does]`.
- No PII in push payloads beyond first name + journey summary (lock screens are
  visible) — no phone numbers or emails in push text.
- Erasure: `DataErasureService` must also null matching `leads` rows (add a test).

### Test plan (PHPUnit, `RefreshDatabase`)

- Step 0: same Graph message on two runs → AI mocked `completeJson` called once;
  pre-filtered sender → never called; cap reached → skipped, no exception; the
  seen store survives a flush of the default cache store.
- `LeadRecorder`: widget quote request → one lead (twice → still one); paid web
  booking → no open lead; email enquiry → one lead; missed call → one lead and
  `Message::count() === 0` and no Twilio Messages request (`Http::fake` +
  `assertNotSent`); repeat call within 30 min → merged.
- Alerts: `new_lead` pushes to two non-busy super-admins, skips a busy one,
  is delivered with `critical_only` on; feed JSON contains `event_type`.
- Escalation: lead at 10:00 → nothing at 10:04, one `lead_unanswered` at 10:05,
  still one at 10:06; responded at 10:03 → none; lead at 23:30 → first escalation
  07:05 next day; BST and GMT dates both pass (`APP_TIMEZONE=UTC` in phpunit, so
  assert London-local behaviour explicitly); call tier off by default → no
  `OfficeAlertCall` call; on → one call at 10:10.
- Respond: WhatsApp press stamps `responded_at` once and redirects to
  `https://wa.me/44…?text=…`; second press doesn't move `responded_at`; email failure
  leaves it unresponded.
- Conversion: booking from lead sets `booking_id` + `booked`; cancelled web booking
  → `lost`.
- Stats: fixture of 5 leads → correct median/p90/within-5 %.
- Pre-migration safety: drop the `leads` table in a test → widget booking still
  succeeds, dashboard renders, `/leads` shows a "run migrate" notice.
- Regression: no customer `Mail::to(customer)` and no Twilio Messages call during
  `cet:lead-watchdog` or ingestion runs.

### Phased rollout (staging, `~/cet-staging` only)

| Phase | Ships | Exit criterion |
|---|---|---|
| 0 | Step 0 (seen store, pre-filter, cap); 2-minute enquiry ingestion; `new_lead` push + chime (the smallest first step below) | Anthropic daily usage drops or stays flat for 3 days; directors confirm the chime is right |
| 1a | `leads` table, recorder hooks, leads inbox, respond buttons. **Shadow mode: no escalation**, stats page live | One week of baseline response-time data |
| 1b | 5-minute escalation (AC12–15) | A week with no false escalations (e.g. on leads already answered elsewhere) |
| 1c | Missed calls → leads, auto-WhatsApp removed (director sign-off in the PR) | Every missed call appears with call-back |
| 1d | Log a WhatsApp lead; dashboard tile; optional call tier | Directors use it for a week |

Each phase: full suite green, deploy note says whether `migrate` is needed (only 1a).

### Risks

- **Alert fatigue** (siren for every lead) → leads chime; only the 5-minute
  escalation uses the siren.
- **Response measured on button press, not actual send** (wa.me opens WhatsApp; the
  director still presses send there) — same trust model as reminders today; flagged
  in stats as "tap-recorded".
- **WhatsApp leads are only as fast as someone logging them** — honest limitation
  of the no-paid-API rule. Their timer starts at the "received at" the director enters.
- **Missed-call webhook only works if the business line routes through Twilio**
  `[CONFIRM how 0114 399 8242 is routed]`.

---

## Part 2 — Unpaid card bookings chase list (mini-spec)

### Problem

`/payments` (`PaymentController::index`) lists card/account jobs that have
**already run** unpaid, plus upcoming cash. Nothing lists **upcoming** card bookings
that aren't paid — abandoned widget checkouts, payment links never opened,
balances after a re-price. A driver can set off on a job whose card money never
arrived, and an abandoned checkout is a warm booking nobody chases.

### Users and stories

- As a director, I see every upcoming card booking with money still due, soonest
  pickup first, with how much, how long it's been waiting, and when we last sent a link.
- As a director, I resend the payment link (email) or send it by WhatsApp in one tap.
- As a director, I'm warned in the morning about card jobs in the next 48 hours
  that aren't paid.

### Acceptance criteria

- C1. New tab on `/payments`: **"Card due — upcoming"**. Rows: bookings where
  `payment_method = card`, not cancelled/no-show/postponed, `pickup_at >= now() - 2h`,
  `! fareIsPaid()`, and amount due (`transactionsAmountDue() ?? amountPayable()`) > 0.
  Excludes `TEST-` references and account jobs.
- C2. Columns: pickup (London time) with a countdown, customer, ref, entity
  (Ltd/Square or PVT LTD/Stripe — reuse the sending-as label), amount due, created
  ("booked 3h ago"), last link sent (from `meta['email_log']`, newest payment-link
  entry), source (web / office), risk chip: **red** < 24h to pickup, **amber** < 72h.
- C3. Buttons per row: **Resend payment link** (existing
  `bookings.resend-payment-link`, which reuses the open link for the exact amount),
  **WhatsApp link** (wa.me with the signed `widget.pay` URL and a short template —
  operator taps send), **Call**, **Mark paid** (existing `payments.paid`),
  **Switch to cash** (existing edit path; confirm dialog: "the driver will collect
  £X"). Every press appends to `meta['chase_log']` (time, user, channel; cap 20).
- C4. Daily 08:30 **internal** push to directors: "N card bookings in the next 48h
  are unpaid (£total)" — only when N > 0. New `ALERT_TYPES` key `unpaid_card`.
  No customer is contacted.
- C5. Driver safety: the driver sheet already shows payment state; no change. The
  office list is the control.

### Data, routes, views

- **No migration.** `meta['chase_log']` on the booking; everything else is computed.
- `GET payments/card-due` → `PaymentController@cardDue` (`payments.card-due`), or a
  `?tab=card-due` on the existing index.
- `POST bookings/{booking}/chase` → `PaymentController@chase` (`payments.chase`,
  throttle:60,1) records the press and redirects to wa.me/tel, or calls the existing
  resend action for email.
- View: `resources/views/payments/index.blade.php` gets the tab; empty state "No
  unpaid card bookings coming up."
- **Verify** how the widget stores an unpaid card choice (`$effectiveMethod` in
  `BookingWidgetController::store`; a comment says the booking "stays 'cash' until
  payment succeeds"). If card-intent bookings are stored as `cash`, include the
  pending `web_booking` ones that asked to pay by card too, or they'll be missed.

### Tests

Unpaid upcoming card booking listed; paid / cancelled / account / TEST- not listed;
£55 paid of £100 lists £45 due; resend uses the existing link (no second Payment
row); WhatsApp chase writes `chase_log` and redirects to wa.me with the signed URL;
08:30 push only when N > 0 and never to customers.

### Cost / privacy

Free. No new personal data (chase log holds user id + channel only).

**Smallest first step (chase list):** the read-only "Card due — upcoming" tab
with the existing "Resend payment link" button on each row — one query, one view
section, one test, no migration.

---

## Smallest first step:

Make new enquiries impossible to miss within two minutes, as one change that ships
in about a day:

1. Add the "seen message" store (step 0) to `EnquiryInboxService` (and the same
   check in `OutlookBookingService`'s AI fallback), so it's safe to poll quickly.
2. Change `cet:ingest-enquiries` from every 10 minutes to every 2.
3. Add a `new_lead` alert type (`User::ALERT_TYPES`) and fire it through the
   existing `AdminAlerts::notify` from `EnquiryInboxService::createEnquiry` and
   from the widget/public-form quote-request path. This replaces today's
   info-level "New quote request" push, so nobody gets two. Send it at **critical**
   severity so the "critical only" switch doesn't hide it.
4. Two small edits so a lead **chimes but never sounds the siren**: add
   `event_type` to the feed JSON (`AlertsController::buildFeed`), and in
   `public/js/cet-alerts.js` treat a new `new_lead` row as "play the chime" and
   leave it out of `hasLiveCritical` (which drives the siren). Bump the script `?v=`.

Tests: the same email on two runs → one AI call; a new enquiry and a widget quote
request each push `new_lead` to both non-busy directors, including one with
"critical only" on; a busy director gets nothing.

No migration, no customer-facing change, no new cost. The `leads` table, the timer,
escalation and stats follow in Phase 1a/1b.
