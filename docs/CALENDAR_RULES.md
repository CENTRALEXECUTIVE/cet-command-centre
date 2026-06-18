# Calendar & booking rules (as implemented)

The ETO email → Google Calendar pipeline follows these rules. Where a rule is
enforced in code it's noted in brackets.

## Calendar
- All events go to `admin@centralexecutivetransfers.co.uk`
  [`Setting::get('calendar_id', …)` / `CET_CALENDAR_ID`].
- Title in **bold asterisks**: `*Name AIRPORT (TAG)*` [`CalendarEventBuilder::title`].
- Pickup address always in the **location** field.
- Start = booking time exactly; end = **+1 hour**.
- Timezone `Europe/London` — Google applies BST (+01:00) / GMT automatically.
- After every add the event is **read back from Google to confirm** it's present
  before being marked synced [`GoogleCalendarService::confirmInCalendar`].

## Title emojis [`CalendarEventBuilder`]
- 💰 = cash balance outstanding (outbound / one-way only).
- 👀 = card / Stripe / Square balance remaining (outbound / one-way only).
- 🚼 = any child / booster / infant seat.
- No emoji = fully paid.
- Return legs never carry the money emoji — the outbound holds the balance.

## Notifications [`CalendarEventBuilder::notifications`]
- All events: email 2h before, push 3h, push 7h, push 1 day before.
- Balance (👀 / 💰) jobs: add a 3-day push **on the event itself**.

## Import rule [`OutlookBookingService::upsertFromParsed`]
- Only import when payment shows **Paid / deposit / partial**. A brand-new
  booking that is still entirely **Pending** is left unread for a human.
  (An already-imported booking still updates on amendment emails.)

## Vehicle mapping [`FixedPriceService::COLUMN_TO_SLUG` + `CalendarEventBuilder::VEHICLE_TAG`]
- Executive 8 Seater → **V CLASS**
- 8 Seater / 8 Seater XL → **MINIBUS**
- Luxury → **Rolls Royce**
- Executive tag = the assigned driver's first name (rotation jobs).

## Lead passenger rule [`EtoEmailParser`]
- When a lead passenger is given, their **name + mobile** become the Customer
  Name and Contact No on the title and description; the booker is kept in notes
  only.

## Description format [`CalendarEventBuilder::description`]
```
📑 Booking Confirmation – Departure / Arrival / Arrival (Meet & Greet) / Transfer
• *Date & Time:* …
• *Customer Name:* …
• *Contact No:* …
• *Passengers:* …
• *Luggage:* …
• *Pickup Location:* …
• *Flight Number:* …            (if applicable)
• *Meet & Greet:* Required      (if applicable)
• *Drop-off Location:* …
• *Vehicle Type:* …
• *Payment:* …
• *Booking Reference:* …
• *Notes:* …                    (if applicable)
```

## Driver rotation (rotation engine — applied on dispatch, not on email import)
- Executive jobs rotate between **ABDI** and **MAJ** only.
- Minibus / V Class / Estate / Rolls Royce / third party = no rotation change.
- Same customer outbound + return = same driver, rotation moves once.
- One-way bookings = normal rotation, independently.
- Substitute cover = original driver keeps rotation position.
- New / unbooked airport = ABDI first.
- Airport-hotel jobs (e.g. Radisson Blu Manchester Airport) = class as that airport.
- Process in booking date/time order so rotation assigns accurately.
