# CET Command Centre — Locked Memory

This file is the single source of truth for the rules that must never drift.
If anything below changes, update this file **and** the code it points to.

## Calendar is PAUSED by default (safety switch)

The system does **not** read or write the Google Calendar unless deliberately
resumed. This is a hard guard so the calendar can never be touched by surprise.

- Pause:  `php artisan cet:calendar-pause`
- Resume: `php artisan cet:calendar-resume`  (then `cet:sync-calendar` to push
  anything captured while paused)
- Hard override: `CALENDAR_SYNC_ENABLED=false` in `.env` forces it OFF no matter
  what the pause setting says.

While paused: bookings still flow into the database from the email feed; they
sit `pending` and are pushed only after resume. Source of truth:
`GoogleCalendarService::active()` (default `Setting('calendar_paused', true)`),
the scheduled calendar jobs `->skip()` in `routes/console.php`, and
`tests/Feature/CalendarDedupeTest`.

## The system does not store pre-automation bookings

Old bookings that pre-date automation are **not** kept in the database. The
operator manages the historical calendar themselves (their own ICS import).
The system only tracks bookings created by the live email feed (source =
`outlook`) from go-live onward. To clear imported historical rows from the DB
**without touching the calendar**: `php artisan cet:forget-bookings`
(removes source = `ics` / `import` only; leaves `outlook` live bookings alone).

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

**Authoritative spec: `docs/CET_CALENDAR_RULES.md`.** That file is the source of
truth; the summary below must agree with it. Key points the code enforces:
the title bracket is ALWAYS a person (ABDI/MAJ/COVER/named third party), NEVER a
vehicle; no blank line after the 📑 header; no dash in payment text; the booker
goes in Notes as "Booked by X"; ICS re-import stays DISABLED (rule 10 — it was
the June corruption source, so the system writes the calendar via the API only,
never round-trips through ICS).


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

**Calendar-side guard (the permanent fix):** before adding any event, the
sync searches the Google Calendar for an event whose description already
carries the same `Booking Reference` and, if found, **updates that event in
place** instead of posting a copy. This means it cannot duplicate events that
were imported by hand or left by an earlier run — so the calendar never needs
the "delete everything and re-add" dance again.
Source of truth: `GoogleCalendarService::findEventIdByReference()` /
`push()`; locked by `tests/Feature/CalendarDedupeTest.php`.

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
