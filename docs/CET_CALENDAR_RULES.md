# CET Calendar Rules and Format Specification

Authoritative ruleset for CET booking calendar entries (calendar ID 31,
admin@centralexecutivetransfers.co.uk). Use this as the source of truth for the
Command Centre automation and any future calendar writes.

## 1. Event title
Format: `*Customer AIRPORT[ Return] (DRIVER)*`
- Always wrapped in bold asterisks.
- Customer is the LEAD PASSENGER, never the booker.
- AIRPORT is the three-letter code (MAN, LHR, BHX, LBA, EMA, HUY, STN, LGW, LPL, LTN) or FREE ROAM for non-airport jobs.
- "Return" is appended for the arrival/second leg.
- DRIVER is always a PERSON: ABDI, MAJ, COVER, or a named third party
  (KASH, MEHTZ, CHINNY, SOHAIB, JABIR, HAMZA, LEWIS, TOSEEF).
- DRIVER is NEVER a vehicle type. EXECUTIVE / V CLASS / MINIBUS / ESTATE / ROLLS ROYCE
  appearing in the bracket is an error.
- Emoji prefix where applicable: 💰 cash outstanding, 👀 card balance pending,
  🚼 / 🚸 child seat.

## 2. Vehicle type mapping (ETO term -> calendar term)
- Executive 8 Seater  -> V CLASS
- 8 Seater / 8 Seater XL / Minibus XL -> MINIBUS
- Luxury / Rolls Royce Ghost -> ROLLS ROYCE
- Executive -> Executive
- Estate -> Estate
Vehicle type appears only on the "Vehicle Type" line, never in the title bracket.

## 3. Lead passenger vs booker
- "Lead passenger name" field = the customer on the title AND the "Customer Name" line.
- The booker ("Passenger name" field) goes ONLY in a Notes line: "Booked by X".
- Common corporate bookers seen: Abi Atkin (LB Foster), Suzanne Winkett, Zhihao Qi,
  Achim Haberstock, ASSA ABLOY / Rosemary Hartland, Jackie Donoghue (JELD-WEN),
  Claire Green (JELD-WEN), Bliss Christopher-Brearley.

## 4. Addresses
- No bare city names ("Sheffield", "Manchester") in pickup or drop-off.
- Every address must include street + area (and postcode where available).
- Pull the full address from source; never abbreviate to the city.

## 5. Description block
```
📑 *Booking Confirmation – Departure|Arrival|Transfer[ (Meet & Greet)]*
• *Date & Time:* DD/MM/YYYY – HH:MM
• *Customer Name:* <lead passenger>
• *Contact No:* <phone>
• *Passengers:* <n>
• *Luggage:* <descriptive, e.g. 1 Suitcase + 1 Hand Luggage>   (or *Hand Luggage:* <n>)
• *Flight Number:* <code>            (if applicable)
• *Meet & Greet:* Required           (if applicable)
• *Pickup Location:* <full address>
• *Drop-off Location:* <full address>
• *Vehicle Type:* <mapped type>
• *Payment:* <see section 6>
• *Booking Reference:* <ref>
• *Notes:* Booked by <booker>        (if applicable)
```
- No blank line after the 📑 header.
- No hyperlinks in the description.

## 6. Payment formats
- Card fully paid:        `Paid £X (Stripe)` | `(Square)` | `(Stripe + Square)`
- Cash outstanding:       title gets 💰 prefix; line `Paid £X (method) + 💰 Cash £Y`
                          (cash collected on outbound only for paired legs; return shows Paid or same split)
- Card balance pending:   title gets 👀 prefix; line `Pending £X`; add 3-day push (4320 min)
- Account / invoice:      `Pending £X (Account)` or `Paid £X (Account)`, NO 👀 (intentional)
- NEVER use a dash in payment text. ("£90 Cash Due", "£81.50 Paid – £8.50" are wrong.)

## 7. One reference = one event
- a/b suffixes are separate legs (both valid, both kept).
- Two events sharing one reference at the same time = duplicate; delete the malformed copy.

## 8. Timing and notifications
- End time = start + 1 hour.
- Notifications: email 120 min; push 180, 420, 1440 min.
  Add push 4320 min (3-day) when a 👀 card balance is pending.
- Timezone: BST (+01:00) from late March to late October; UTC (Z) November to March.
- Start time must match the booking exactly.

## 9. Exclusions (never in calendar)
- Status "Request quote" bookings.
- Cancelled bookings.

## 10. ICS re-import (known corruption source — keep DISABLED)
The old ICS re-import sync repeatedly corrupted June. Fingerprints to watch for:
- Duplicate events, IDs clustered around 5810–5835.
- Vehicle type shown as the driver in the title.
- Bare addresses with a "UK" suffix.
- A blank line after the 📑 header.
- Old deposit-style payment formats.
The Command Centre must write to the calendar directly via the API and never
round-trip through ICS import.

## 11. Driver rotation (current)
- MAN / LHR / BHX / LBA: ABDI next.
- EMA: MAJ next.
- Free Roam: MAJ next.
- HUY / STN / LGW / LPL / LTN / all other unbooked airports: ABDI first.
- V Class, Minibus, Estate, Rolls Royce, and third-party jobs do NOT affect rotation.
- Same customer outbound + return = same driver; rotation moves once only.
- When a substitute covers, the original driver keeps their rotation position.
