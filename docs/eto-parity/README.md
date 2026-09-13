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
