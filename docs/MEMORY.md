# CET Command Centre — Locked Memory

This file is the single source of truth for the rules that must never drift.
If anything below changes, update this file **and** the code it points to.

## Driver rotation (current, live order)

Executive saloon jobs only (Abdi ↔ Maj). Booking-order based: first booking →
first driver; a job with outbound + return keeps the **same driver** for both
and the pointer advances **once**.

- **ABDI next:** MAN, LHR, HUY, BHX, STN, LGW, LPL, LTN, LBA, and **all other
  unbooked airports** (default).
- **MAJ next:** EMA, Free Roam.

Source of truth in code: `database/seeders/RotationSeeder.php`
(`cet:wipe-eto` and `cet:restore-base` re-apply it).

## Calendar formatting (locked)

- Title in bold asterisks: `*[emoji ]Customer AIRPORT (TAG)*`; paired arrival
  leg adds ` Return`. TAG = driver callsign (ABDI/MAJ) for rotation jobs, else
  the vehicle label (V CLASS / MINIBUS / ROLLS ROYCE / ESTATE).
- Emojis: 💰 cash outstanding, 👀 card/Square balance remaining (outbound/one-way
  only), 🚼 any child/booster seat, none = fully paid.
- Description header: `📑 *Booking Confirmation – <Type>*` (+ ` 🚼` if child seat).
- Date & Time: `DD/MM/YYYY – HH:MM`. Luggage: descriptive, never a bare number
  (e.g. `2 Suitcases + 1 Hand Luggage`). Payment: `Paid £X (Method)`.
- Location = pickup address; start = pickup time; end = +1 hour; tz Europe/London.
- No hyperlinks. Notifications: email 2h; popup 3h/7h/1 day; +3-day popup if a
  balance remains.

Source of truth in code: `app/Services/CalendarEventBuilder.php`.

## No duplicates, paid replaces unpaid

Bookings are keyed on `external_reference` (unique index on
`source_system + external_reference`). A later email for the same reference
**updates** the same booking/event (e.g. unpaid → paid) — it never creates a
second one. Old bookings that existed before automation are matched by
reference and never duplicated.

## Restore the calendar to the export ("the base")

If the calendar ever needs returning to the known-good export, run **one**
command on the server:

```
php artisan cet:restore-base
```

It pauses automation, fully wipes the calendar, resets the DB + driver order,
re-imports every event from `database/imports/base.ics` exactly, verifies, and
turns automation back on. Idempotent and safe to re-run.

⚠️ The calendar must only ever be managed by **one** system — the live server.
Never push to the same calendar from another machine at the same time, or the
two will fight and create duplicates.

## Calendar safety rule (why purge was fixed)

`cet:purge-calendar` (no flag) deletes **only** events created by the service
account. It must **never** match on the `*bold*` title style — the operator's
own historical bookings use that style, and matching it deleted real jobs. For
a deliberate full wipe use `cet:purge-calendar --all` (or `cet:restore-base`).
