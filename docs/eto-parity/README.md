# ETO → CET parity tracker

Abdi is replacing EasyTaxiOffice (ETO) with the CET Command Centre and wants to
match everything ETO does that matters — and skip the clutter. As he sends ETO's
settings screens, each one is catalogued here: every ETO option mapped to CET's
current state and a recommendation.

**Status key:** ✅ have · 🟡 partial (works but not as configurable / slightly
different) · ❌ missing.

**Recommendation key:** **Need** (real gap for go-live / daily use) · **Nice**
(worth having, not urgent) · **Skip** (clutter, or not how CET should work).

Go-live target for the booking side: **30 September 2026.**

**Plan (agreed with Abdi):** catalogue every ETO settings screen first, then build
the whole **Need** list in one coherent pass — don't build screen-by-screen.

**Confirmed decisions:**
- Ferry / seaport jobs: **YES** — CET does them, so ferry fields + seaport
  detection are in scope (a Need, not clutter).

## Screens catalogued
- [Web Booking Widget](web-booking-widget.md) — the customer-facing online booking form.
- [Operating Areas](operating-areas.md) — **PARKED, to discuss** (radius / out-of-area pricing).
- [Payment Methods](payment-methods.md) — CET uses Account, Cash, Card (Square); 15 other gateways skipped. **No card surcharge (decided).**
- [Styles](styles.md) — **Skip** (CET booking pages already brand-styled in code).
- [Email](email.md) — works via `.env`/SMTP; confirm deliverability (SPF/DKIM/DMARC).
- [Notifications](notifications.md) — CET model (WhatsApp manual + email for web + driver push + panel); **SMS skipped (cost)**. Decision: opt-in auto customer emails?
- [Locations](locations.md) — import airports + cruise ports; postcode zones on hold for Pricing.

## The big gaps so far (the "Need" list)
1. **Minimum booking notice** on the public form — reject/flag web bookings inside
   X hours of pickup (ETO default 8h). CET has none.
2. **Auto-confirm policy, configurable** — ETO: confirm if pickup > X h away, else
   reject. CET currently: paid web booking → confirmed; widget → always pending.
3. **Return journey on the public form** — returns are common; ETO has a one-tap
   return. Confirm whether CET's public form offers it.
4. **Flight time + "waiting time after landing"** capture on airport bookings —
   feeds the set-off/lead-time logic. CET captures flight number only.
5. **Account payment method** for company-account customers on the form.
6. **Ferry / seaport capture** — ferry name / time / terminal on pickup + dropoff,
   plus seaport detection (mirrors the airport handling).
7. **Seed the location library** — import ETO's airports + cruise ports (CSV in
   `data/`); airports enrich detection, cruise ports enable ferry/seaport jobs.

## Decisions still open
- **Notifications model** — keep CET's manual-WhatsApp + web-email model, or add
  opt-in auto customer emails (confirmed / en-route+tracking / completed+invoice)?
  SMS stays off (cost).
- **Operating Areas** — parked (radius / out-of-area pricing).
- **Postcode pricing zones** — only if the Pricing screens show CET prices by zone.

## Decisions made
- Ferries/seaports: **in scope.**
- Card surcharge: **none — do not add.**
- 15 unused ETO payment gateways: **skip.**
- Styles admin: **skip** (brand is in code).
- SMS notifications: **skip** (paid; WhatsApp covers it).
