# ETO parity roadmap — what a full dispatch/booking system has that CET doesn't (yet)

Owner: product · Date: 28 Oct 2026 · Status: proposal for the directors

The operator's ask: make the Command Centre "as effective as ETO was" — the
small skills and edits a fully functional dispatch/booking system has (resend
confirmation, send from the right address, resend payment link, "and so many
others"). This document lists every capability area, says honestly whether CET
has it (checked in the code, not from memory), why it matters for revenue or
safety, effort and priority, then gives the top 10 to build next.

**Read with:** `CLAUDE.md` (hard rules), `HANDOVER.md` (comms pack just built),
`docs/REVIEW.md`, `docs/eto-parity/*` (screen-by-screen ETO settings audit).

## How this was checked

- **CET:** `routes/web.php` (~280 named routes), the controllers, models, services
  and `resources/views/bookings/show.blade.php` were read for each area. Where a
  claim could not be fully verified it says **verify**.
- **ETO and peers:** web search on 28 Oct 2026. ETO's own site
  (`easytaxioffice.co.uk`) could not be fetched from this session (network
  allow-list), so ETO features come from search snippets of its own pages and
  knowledge base plus `docs/eto-parity/` (built from ETO's real settings screens).
  ETO advertises: dispatch panel + web booking widgets + driver and passenger
  apps working together; drivers pre-assigned to vehicles and notified by email
  (SMS as an add-on); corporate accounts with deferred payment and periodic
  invoices covering many bookings; a notification matrix of event x recipient
  (admin/customer/driver/**fleet operator**) x channel (email/SMS/push/panel). Its
  passenger app lets customers get a quote, book, manage/cancel bookings and
  download invoices. Sources at the end.

**Status key:** EXISTS · PARTIAL · MISSING. **Effort:** S = up to 1 day,
M = 2–5 days, L = over a week (one developer session plus operator review).
**Priority:** P0 = do now (money/safety/rules), P1 = next, P2 = soon, P3 = later.

## Two things found while checking that need attention first

1. **Possible AI cost leak in the inbox pipelines (P0, S).**
   `cet:ingest-outlook` runs **every 2 minutes** over 30 days of inbox and
   `cet:ingest-enquiries` **every 10 minutes** over 14 days
   (`routes/console.php`). Both fetch up to 1,000 messages
   (`GraphMailClient::fetchRecent`). An email that is *not* an ETO booking
   (`OutlookBookingService::parse` → AI fallback) or *not* an enquiry
   (`EnquiryInboxService::ingest` → `extract`) is **never recorded as seen**, so it
   is sent to the AI again on every run. With Graph and the Anthropic key both
   live, every newsletter, Square/Stripe receipt or supplier email in the
   window is re-classified up to ~720 + 144 times a day. **Action for the
   directors today:** check usage in the Anthropic console. If it's high,
   the fix is a "seen message id" store that `optimize:clear` doesn't wipe
   (see the speed-to-lead spec, step 0). No migration needed.
2. **Missed-call auto-reply contradicts hard rule 2 (P0, S).**
   `MissedCallService::handle()` sends the caller an **automatic WhatsApp** via
   the Twilio Messages API whenever `TWILIO_*` + `whatsapp_from` are set
   (`WhatsAppService::deliver`). CLAUDE.md says nothing auto-sends to customers,
   and the inbound WhatsApp sender is meant to be disconnected. It is probably
   inactive today (sender disconnected / not configured), but it is one `.env`
   line from going live. Recommend replacing the auto-send with an office alert
   plus a call-back button (speed-to-lead spec). Also, missed calls have **no
   screen**: nothing in `resources/views` shows the `missed_calls` table.

## Gap list

### A. Leads, quotes and booking capture

| Capability | CET status (verified) | Why it matters for CET | Effort | Priority |
|---|---|---|---|---|
| Web booking widget + instant price | EXISTS — `widget.*`, `public.book.*`, fixed matrix + free-roam + surcharges + extras, 8h minimum notice (`cet.public_min_lead_hours`) | Main source of new bookings | — | — |
| Quote request capture (price on request) | EXISTS — widget saves a pending booking with `meta['quote_request']`, office gets an **info** push "New quote request" | Every unanswered quote is lost revenue | — | — |
| Email enquiry inbox with AI-read journey, price and draft reply | EXISTS — `/enquiries`, `EnquiryInboxService`, "Convert to booking" prefill, Graph "Send from Outlook" or mailto | Fast, consistent replies | — | — |
| **Instant lead alert + response timer + 5-min escalation + response stats** | MISSING — new enquiries raise no alert at all; web quote requests raise an info push only; no timer, no escalation, no stats | Whoever replies first usually wins the job. This is the biggest revenue lever in the app. | M | **P1** (spec: `speed-to-lead-phase1-spec.md`) |
| One unified "leads" inbox (web, email, missed call, logged WhatsApp) | MISSING — three separate places (bookings list, `/enquiries`, nowhere for missed calls) | One place to work = nothing forgotten | M | P1 (same spec) |
| Missed-call capture to office | PARTIAL — webhook + table exist, no UI, auto-WhatsApp (see above) | Missed calls are the warmest leads | S | **P0** |
| Quote follow-up / expiry (chase quotes not accepted after 24h) | MISSING — `Quote` model has `expires_at`/`converted_booking_id` but nothing uses it for follow-up | Quotes that go quiet get a second chance | S–M | P2 |
| Paste-a-booking (AI) | EXISTS — `/intake` preview → confirm | Speed on WhatsApp/phone bookings | — | — |
| Customer lookup as you type on New Booking | EXISTS — `data-customer-search` → `customers.search` (`bookings/create.blade.php`). **Verify** it pulls VIP flag/notes/preferred vehicle as REVIEW.md asked | Fewer duplicate customers, faster booking | S (if gaps) | P3 |
| Duplicate detection | PARTIAL — after-the-fact flag + merge/keep-separate (`Booking::duplicateCandidates`, bookings list "dup?") exists; **no warning at creation** | Double entry when busy = two cars or a double charge | S | **P1** |
| Rebook last job / repeat booking | PARTIAL — recurring bookings exist (`/recurring-bookings`); no one-tap "rebook" from a customer | Repeat airport customers | S | P3 |
| Return-trip prompt on one-ways | PARTIAL — widget supports returns; office "create return leg" exists; no prompt to offer it | Higher value per customer (PLAYBOOK) | S | P3 |

### B. Amendments, cancellations, refunds

| Capability | CET status | Why it matters | Effort | Priority |
|---|---|---|---|---|
| Edit / reschedule / postpone / re-price | EXISTS — `bookings.update`, `reschedule`, `postpone`, `price`; edits stamp `meta['last_edited_at']` | Core | — | — |
| Customer "booking updated" email | EXISTS — manual button (`bookings.updated-email`), comms pack | ETO parity | — | — |
| **Driver told when an allocated job changes** | MISSING — `BookingController::update` / `BookingService::updateFromForm` do not push the driver; only status changes, flight changes (`FlightMonitorService`) and watchdog nudges push. The driver sees the new data only if they reopen the job | A time/address change the driver didn't notice = late or missed pickup. Safety-critical. Internal push only, so rule 2 is unaffected | S | **P0/P1** |
| Customer self-service amend/cancel | PARTIAL — customer account can *request* a change (`CustomerAccountController::requestChange` → warning alert); office applies it | Fine for a premium firm; keep human-approved | — | P3 |
| Cancellation with charge + cancellation email | EXISTS — cancel with reason, `cancellation-charge`, preview + manual send | — | — | — |
| Refunds | PARTIAL — refund amount/reason **recorded** (`setCancellationRefund`), money moved by hand. The success message always says "process it in **Square** by hand" — wrong for no-VAT bookings paid by **Stripe** (`BookingController` ~line 1486) | Wrong instruction could send the office to the wrong account | S (wording) / M (API refund with confirm) | P1 (wording) / P3 (API) |
| Calendar never auto-edited on amend/cancel | EXISTS by rule — reminders on screen | Hard rule 1 | — | — |

### C. Notifications (office-approved)

| Capability | CET status | Why it matters | Effort | Priority |
|---|---|---|---|---|
| Resend confirmation, sending-as label, email history, resend payment link | EXISTS — comms pack (28 Oct), `BookingEmailLog` | ETO parity | — | — |
| Templated customer/driver messages ("Send a notification") | EXISTS — `bookings.notify`, `NotificationTemplates`, wa.me links | — | — | — |
| Reminders queued for the office to send (08:00–23:00) | EXISTS — `cet:prepare-reminders`, "to send" list, `messages.sent` | — | — | — |
| Auto web-booking confirmation email | EXISTS (director-approved exception) — `CustomerBookingMailer`, web source only, setting `widget_customer_emails` default **on** | Note for the record: widget quote requests already get an automatic acknowledgement email when this is on | — | — |
| En-route tracking link / completed + invoice emails (one tap) | PARTIAL — link exists (`/track/{token}`), sent by hand; no one-tap "send tracking link" email | Fewer "where's my driver?" calls | S | P2 |
| iCalendar attachment, configurable email footer | MISSING | Nice to have (ETO has them) | S | P3 |
| SMS | Deliberately skipped (cost) | — | — | — |

### D. Payments and money

| Capability | CET status | Why it matters | Effort | Priority |
|---|---|---|---|---|
| Two entities, Square (VAT) / Stripe (no VAT), webhooks, reconcile safety net | EXISTS — `PaymentGateway`, `cet:reconcile-payments` every 5 min (commit 068869e) | — | — | — |
| Price-change balance payments | EXISTS — `onlinePaymentCoversFare`, stable pay link for the balance | — | — | — |
| **Unpaid card bookings chase list (upcoming + abandoned checkouts)** | PARTIAL — `/payments` lists card/account jobs that **have already run** unpaid, plus upcoming cash. Upcoming unpaid card jobs (abandoned widget checkouts, links never paid) are not listed anywhere | A job driven on an unpaid card booking is money at risk; abandoned checkouts are recoverable bookings | S–M | **P1** (mini-spec in the speed-to-lead file) |
| Invoice overdue chase (accounts) | MISSING — `InvoiceController` has no overdue/reminder logic; `payment_terms_days` exists on accounts | Cash flow from corporate accounts | S–M | P2 |
| Cash summary, payroll, 90% default pay | EXISTS | — | — | — |

### E. Dispatch, drivers, availability

| Capability | CET status | Why it matters | Effort | Priority |
|---|---|---|---|---|
| Dispatch board, allocate/auto-allocate/reassign, rotation | EXISTS | — | — | — |
| Driver app (PWA), push, GPS, status flow, nudges, watchdog, emergency call | EXISTS — stronger than ETO | — | — | — |
| Multi-vehicle jobs | EXISTS — extra drivers per booking, per-car status | — | — | — |
| **Driver clash / availability warning when allocating** | MISSING in use — `DriverProfile::isAvailableOn()` and `available_days` exist but **nothing calls them**; no overlap check for a driver already on a job at that time | Prevents allocating someone on their day off or to two overlapping jobs | S–M | **P1** |
| Driver time-off dates (holidays) | MISSING — weekly pattern only | Same | S | P2 |
| Fleet operator / subcontractor role (ETO has it) | PARTIAL — Drivers directory, unbranded driver link, cover invoices; no operator login | Fine at CET's size | — | P3 |
| Driver documents + compliance gate | EXISTS | Licensing safety | — | — |
| Waiting time (per stop) and extras | EXISTS | — | — | — |

### F. Accounts, invoicing, reporting

| Capability | CET status | Why it matters | Effort | Priority |
|---|---|---|---|---|
| Per-booking invoice/receipt, combine bookings, entity-aware | EXISTS | — | — | — |
| Monthly consolidated corporate invoice | EXISTS — `cet:generate-invoices` monthly (the parity doc `docs/eto-parity/invoices.md` still says missing; it's out of date) | — | — | — |
| Corporate portal | EXISTS — `corporate_client` can book and see `corporate.statement`. **Verify** multiple bookers per account (`CorporateContact` exists) | Account retention | — | — |
| Customer account (widget) | EXISTS — login, password reset, request change | — | — | — |
| Reports: revenue, profit, owed, VAT, business, ads, customers | EXISTS | — | — | — |
| Lead/source funnel (enquiry → quote → booked, response time, by source) | MISSING | Shows which ads/channels actually make money (PLAYBOOK "track the source of every booking") | M | P2 (stats part ships with speed-to-lead) |

### G. Trust, safety, operations

| Capability | CET status | Why it matters | Effort | Priority |
|---|---|---|---|---|
| Audit log ("who did what") | EXISTS — `AuditLog` + `/activity-log`, booking status history. **Verify** price/payment edits are logged | Disputes, licensing | S (if gaps) | P3 |
| Roles | PARTIAL — admin, super-admin, driver, corporate client. No limited "office staff/dispatcher" role (no payroll/settings/money) | Needed the day CET hires office help — and for SaaS | M | P2 |
| System health page + heartbeats | EXISTS — `/health`, `SystemHealth`, `Heartbeat` | — | — | — |
| **Error tracking (500s visible to admins)** | MISSING — `bootstrap/app.php` `withExceptions` is empty; errors only reach `laravel.log` on the server | Today a broken page is invisible until a customer complains | S–M | **P1** |
| External "scheduler is dead" alarm | PARTIAL — health page shows it, but the watchdog itself runs on the scheduler, so nothing *alerts* when cron stops | The #1 silent failure (REVIEW.md) | S | P1 |
| Booking-record retention (licence conditions) | PARTIAL — soft delete + trash; **force delete** can purge any booking. Many councils' operator conditions require booking records, including cancellations and no-shows, for at least 12 months `[CONFIRM: CET's licensing authority's exact condition]` | Licence compliance | S | P1 |
| GDPR erasure | EXISTS — `/gdpr/erasure` | — | — | — |
| Complaints / lost property intake | MISSING (ETO has feedback types) | Complaints logged properly protect the licence | S | P3 |
| Reviews requests | EXISTS — manual review request, reviews-sent log | — | — | — |
| Onboarding / empty states | PARTIAL | Matters for SaaS more than CET | M | P3 |

## Top 10 to build next (in order — smallest and highest value first)

| # | Build | Why now | Effort | Migration? |
|---|---|---|---|---|
| 1 | **Stop the inbox AI re-processing** (seen-message store + cheap pre-filter before any AI call) | Possibly the largest avoidable cost in the business; also a prerequisite for 2-minute enquiry ingestion | S | No |
| 2 | **Missed calls: remove the automatic WhatsApp, alert the office, add a call-back list** | Brings code back inside hard rule 2; turns the warmest leads into call-backs | S | No (uses existing `missed_calls`) |
| 3 | **Driver change alert** — push the allocated driver(s) when pickup time, address, flight, passengers or vehicle change; driver taps "Seen"; office sees if unseen after 15 min | Safety: prevents late/missed pickups after an amendment | S | No (`meta['change_ack']`) |
| 4 | **Refund wording + entity** — say Square or Stripe according to the booking's billing entity, show the provider dashboard link | Wrong instruction today for PVT LTD bookings | S | No |
| 5 | **Duplicate warning at creation** (New Booking, Paste-a-booking, widget review screen): reuse `duplicateCandidates()` before save, "Create anyway" | Prevents double bookings/charges | S | No |
| 6 | **Unpaid card bookings chase list** (upcoming + abandoned checkouts, one-tap resend link / WhatsApp link) | Money at risk before the car leaves | S–M | No |
| 7 | **Speed-to-lead Phase 1** (unified leads inbox, instant push + chime, timer, 5-min escalation, stats) | Biggest revenue lever | M | **Yes — one new `leads` table** (tell the operator to run `migrate`) |
| 8 | **Allocation clash/availability warning** (day off, overlapping job, documents expired) | Stops impossible allocations | S–M | No |
| 9 | **Error tracking** — record 500s (route, user, message, no PII bodies) to a small log the health page shows; push directors on the first error of a new kind | Turns invisible failures into visible ones | S–M | Prefer no (file log + cache); a table if wanted |
| 10 | **Booking-record retention guard + scheduler-dead alarm** — block force-delete of bookings younger than the licence period; a free external ping on a heartbeat URL that fails when the scheduler is stale | Licence compliance + the #1 silent failure | S | No |

Next after these: invoice overdue chase (P2), quote follow-up (P2), one-tap
tracking-link email (P2), office-staff role (P2), driver time-off dates (P2),
lead/source funnel report (P2), complaints log, iCal, footer (P3).

**Not recommended:** SMS, WhatsApp masking (parked by decision), automatic
customer messages of any kind without an explicit director rule change,
calendar auto-edits, a passenger native app (the PWA widget + account cover it).

## Data/privacy impact (summary across the list)

- Items 1–6, 8–10 add no new personal data; items 3 and 6 store small flags in
  `booking.meta`.
- Item 7 adds a `leads` table holding name/phone/email for enquiries that may
  never book — needs a retention rule (see the speed-to-lead spec).
- Item 9 must never store request bodies (card data, customer messages) — route,
  status, exception class and message only.

## Test plan (summary)

Every item gets a PHPUnit feature test with `RefreshDatabase`, plus a
regression test for the bug it fixes: item 1 (same message twice → one AI call),
item 2 (missed call → no outbound `messages` row), item 3 (time change on an
allocated job → one push; unallocated → none), item 4 (Stripe-entity cancel
→ message says Stripe), item 5 (same customer + window → warning, "create
anyway" works), item 10 (force-delete of a 3-month-old booking refused).
Suite stays green (1811 at 28 Oct).

## Rollout

Staging only (`~/cet-staging`), one item per deploy, each behind the existing
pattern of "silent no-op until configured". Items 1–6 need no migration. Item 7
needs one — say so explicitly at deploy time. Run each for a week on CET before
the next money-touching item.

## Cost

Nothing here adds a paid service. Item 1 reduces Anthropic spend. Item 7's
optional phone-call escalation uses Twilio voice (pennies per call) and ships
**off** by default.

## Sources

- [EasyTaxiOffice — Private Hire Dispatch & Compliance System (KB)](https://kb.easytaxioffice.com/help/easytaxioffice-private-hire-dispatch-and-compliance-system)
- [EasyTaxiOffice — Driver App](https://easytaxioffice.co.uk/solutions/driver-app/)
- [EasyTaxiOffice — Dispatch Features](https://easytaxioffice.co.uk/solutions/taxi-dispatch-system/dispatch-features/)
- [EasyTaxiOffice — Taxi Dispatch System](https://easytaxioffice.co.uk/solutions/taxi-dispatch-system/)
- [EasyTaxiOffice Passenger — App Store](https://apps.apple.com/us/app/easytaxioffice-passenger/id1536553168)
- [Limo Anywhere — booking software](https://www.limoanywhere.com/booking-software) (quote-to-booking, farm-out)
- [Private hire operator conditions — Harlow](https://www.harlow.gov.uk/sites/default/files/documents/Private%20hire%20operator%20licence%20conditions.pdf), [Wolverhampton](https://wolverhampton.moderngov.co.uk/mgConvert2PDF.aspx?ID=272775), [Mid Sussex](https://www.midsussex.gov.uk/business-licensing/taxi-licensing/private-hire-operator-information) (12-month booking records; check CET's own authority)
- `docs/eto-parity/*` — ETO settings screens as supplied by the directors

## Smallest first step:

Ship roadmap item 1 — a "seen message" store for both inbox pipelines, so an
email that has already been checked is never sent to the AI again. It's a one-file
change per service plus a test, needs no migration, and could take the biggest
avoidable cost out of the app the same day. Before building it, a director
checks the Anthropic console's daily usage to see how big the leak actually is.
