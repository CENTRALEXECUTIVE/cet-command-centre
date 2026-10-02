# CET Command Centre — overall review (Oct 2026)

A grounded look at where the system stands and what to improve, after the booking
pages were brought up to the customer-widget standard. Nothing here is urgent-broken
— it's a prioritised list of what makes the business safer and the office faster.

## What's strong (leave it alone)

- **Mature and tested.** 279 routes, 66 controllers, **1330 passing tests** — money
  rules, calendar dedupe, masking and rotation all have regression cover.
- **Money is safe.** Cash-balance vs gross, paired-airport returns, deposits, and now
  full/part refunds on cancellation each have locked-in tests.
- **Resilient.** Calendar reads degrade gracefully; the calendar write is duplicate-proof
  (adopt-by-reference); masking, watchdog alerts, driver GPS/tracking and PWA push all built.
- **Consistent UI.** Customer widget + internal New Booking / Instant Quote / Paste-a-booking
  now share one premium look.

## Internal booking side — add next

| # | Add | Why | Priority |
|---|-----|-----|----------|
| 1 | **Airport reactivity** — flight no. required + landing time + meet & greet | Never miss key airport info | ✅ Done |
| 2 | **Customer lookup as you type** (name/number → pull VIP flag, preferred vehicle, saved notes) | Faster booking; stops duplicate customer records | **P1** |
| 3 | **Duplicate-booking guard at creation** (warn if same customer + pickup window already exists) | Prevents double entry — a real risk when busy | P2 |
| 4 | **Return-leg flight details** on airport returns (inbound + outbound) | Both legs tracked | P2 |
| 5 | **"Rebook last job"** for a known customer | One-tap repeat bookings | P3 |
| 6 | **Price breakdown** ("why this price") on New Booking | Confidence quoting on the phone | P3 |

## Overall Command Centre — what to improve

### P1 — Operational safety (highest value)

- **System Health page** *(strongly recommended build)* — one screen showing: the
  **scheduler heartbeat** (when each of the 18 cron jobs last ran), calendar sync
  freshness, and whether Square / Google Maps / Google Calendar / Twilio are actually
  connected, plus any failed calendar syncs or messages and the last DB backup time.
  - **Why this matters most:** 18 scheduled jobs underpin the system — auto-deploy,
    calendar sync, reminders, the watchdog, flight checks, invoicing, backups. If
    `schedule:run` stops, **all of it silently dies** and nothing on screen says so.
    The recurring "why isn't it updating?" this month was exactly that. This page turns
    a silent failure into an obvious one.
- **Scheduler heartbeat alert** — raise an admin alert if no cron has run in >5 minutes
  (reuses the existing watchdog/alerts plumbing).

### P2 — Confidence & polish

- **Error surfacing** — capture 500s to an admin-visible log (or a free Sentry tier) so
  problems aren't invisible until a customer reports them.
- **Bulk actions** on the bookings list beyond delete (reassign driver, export CSV).
- **Empty / first-run states** for a new admin so the app never looks broken when data is thin.

### P3 — Nice to have

- Deeper reporting (revenue by driver / airport / month — partly in Review already).
- Customer self-service expansion (amend/cancel from the tracking link).

## Go-live blockers (recap — see GO-LIVE.md)

1. **Scheduler cron running** (ties directly to P1 above — the single biggest risk).
2. Square keys + webhook; Google Maps key (rotate after go-live); Google Calendar
   credentials; widget iframe embedded on the website.

---

**Recommended next build:** the **System Health page** + scheduler heartbeat alert — it
de-risks go-live more than any feature, and directly prevents the "silently stopped
updating" problem. Then customer lookup on New Booking for day-to-day speed.
