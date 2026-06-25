CENTRAL EXECUTIVE TRANSFERS — COMPLETE BOOKING RULES
Automation Handover Document
Company No. 15749931 | Operator Licence OP037
====================================================


1. VEHICLE MAPPING
==================
Executive            → EXECUTIVE  (affects rotation)
Executive 8 Seater   → V CLASS    (no rotation change)
8 Seater / 8 Seater XL → MINIBUS  (no rotation change)
Luxury               → ROLLS ROYCE (no rotation change)
Estate               → ESTATE     (no rotation change)


2. DRIVER ROTATION
==================
CORE RULES
- Only Executive jobs affect rotation between ABDI and MAJ
- Minibus, V Class, Estate, Rolls Royce, third party = no rotation change
- Rotation is assigned in ORDER OF BOOKING DATE/TIME (not journey date)
- If two bookings have same booking date, use booking time to determine order
- Any airport not yet in rotation = ABDI goes first

PAIRED BOOKINGS (a/b suffix or same customer same trip)
- Same customer outbound and return = same driver for both legs
- Rotation moves once only (not once per leg)
- Process outbound (a) first, then return (b) regardless of submission order

ONE WAY BOOKINGS
- Normal rotation applies independently to each one-way job

COVER / SUBSTITUTE DRIVER
- If a driver is unavailable and cover steps in = original driver retains rotation position
- Cover driver does NOT take the rotation slot
- This applies even if ABDI or MAJ covers for each other
- Show cover driver name in title (e.g. COVER, CHINNY, HAMZA etc.)

CANCELLATIONS
- Cancelled jobs = delete calendar event
- Cancelled job does NOT count toward rotation
- Cover driver on a cancelled job = original driver still retains position

DRIVER NAMES
- Standard: ABDI, MAJ
- Third party / cover: COVER, CHINNY, HAMZA, KASH, JABIR, TOSEEF, ASAD, ALI, V14 etc.
- NEVER change a driver name unless explicitly instructed — custom names are intentional


3. CALENDAR SETTINGS
====================
CALENDAR
- Primary calendar: admin@centralexecutivetransfers.co.uk
- Calendar ID: 31 (always use this — check 36 if 31 returns empty)
- All events must be status: Confirmed (never Tentative)

TIMEZONE
- BST (+01:00) = 29 March to October
- UTC (+00:00) = November to March
- Always verify offset matches the booking date

EVENT TIMING
- Start time = booking pickup time exactly
- End time = always exactly 1 hour after start
- Pickup address always in the location field (first pickup only)

NOTIFICATIONS
- Email: 2 hours before (120 mins)
- Push: 3 hours before (180 mins)
- Push: 7 hours before (420 mins)
- Push: 1 day before (1440 mins)
- 3-day push (4320 mins): add to any event with 👀 or 💰 emoji
- No separate reminder events — all notifications embedded in booking event


4. EVENT TITLE FORMAT
=====================
FORMAT
- Always wrapped in bold asterisks: *Name Airport (DRIVER)*
- Use lead passenger name if provided — not booker name
- Emojis go BEFORE the name in the title
- Airport codes always UPPERCASE: MAN, LHR, BHX, EMA, LBA, HUY, LPL, STN, LGW
- Vehicle types always UPPERCASE in brackets: (MINIBUS), (V CLASS), (ESTATE), (ROLLS ROYCE)
- Driver names always UPPERCASE in brackets: (ABDI), (MAJ), (COVER) etc.
- Names must be properly capitalised (e.g. Barry Hughes, not barry hughes)

RETURN SUFFIX
- Any arrival job that is part of a paired booking = must say Return in title
- One way arrivals = Return in title only if contextually a return leg
- One way departures = no Return suffix
- Free Roam jobs = no Return suffix unless explicitly a return leg of paired booking

EXAMPLES
- *Barry Hughes LBA (ABDI)*
- *👀 Kelsie Draycott MAN (ABDI)*
- *🚼 Amy Baines MAN (MINIBUS)*
- *💰 Giles Coke LBA (MAJ)*
- *Jon Holden LHR Return (MAJ)*
- *Caldic UK Ltd BHX Return (V CLASS)*


5. EMOJI RULES
==============
EMOJI MEANINGS
- 👀 = card/Stripe/Square balance remaining (pending payment)
- 💰 = cash balance outstanding
- 🚼 = child seat, booster seat or infant seat required
- No emoji = fully paid

PAIRED BOOKINGS
- 👀 on OUTBOUND only + 3-day push notification on outbound event
- 💰 on OUTBOUND only + 3-day push notification on outbound event
- Return event shows no emoji
- Cash return: return shows Paid only if FULL cash for both legs collected on outbound
- If full cash not yet collected on outbound, return also shows cash due

ONE WAY BOOKINGS
- 👀 on that job + 3-day push notification
- 💰 on that job + 3-day push notification

CHILD SEAT RULE — VITAL — NEVER MISS
- 🚼 MUST appear in BOTH the title AND the description
- Applies to: child seat, booster seat, infant seat
- Note child age and seat type in description if provided


6. DESCRIPTION FORMAT
=====================
HEADER LINE
- 📑 *Booking Confirmation – [Type]*
- Types: Departure / Arrival / Arrival (Meet & Greet) / Transfer
  - Departure = outbound airport drop-off
  - Arrival = inbound airport pickup (no meet & greet)
  - Arrival (Meet & Greet) = inbound with meet & greet service
  - Transfer = non-airport local/free roam job

FIELD ORDER (all labels bold asterisks on both sides)
• *Date & Time:*
• *Customer Name:* (lead passenger name if provided)
• *Contact No:* (lead passenger number if provided)
• *Passengers:*
• *Luggage:* X Suitcases + X Hand Luggage (omit if none)
• *Child Seats:* X (if applicable)
• *Booster Seats:* X (if applicable)
• *Pickup Location:*
• *Stop 1:* / *Stop 2:* (if applicable)
• *Flight Number:* (if applicable)
• *Meet & Greet:* Required (if applicable)
• *Drop-off Location:*
• *Vehicle Type:*
• *Payment:*
• *Booking Reference:*
• *Notes:* (if applicable)

NOTES FIELD — WHEN TO USE
- Booker name differs from lead passenger = note booker name and number
- Driver instructions from customer = add to Notes
- Pickup location subject to change = note in Notes
- Golf bag or oversized luggage = note in Notes
- Play card / name board required = note in Notes
- Child age for seat type = note in Notes
- Flight arrival time if different from pickup time = note in Notes
  (e.g. lands 22:45, pickup 00:15)


7. PAYMENT FORMAT
=================
- Always use £ sign — never GBP, p, or any other format
- Paid £X (Stripe) / (Square) / (Cash) / (Account)
- Deposit £X Paid – £X Balance Pending (Stripe/Square)
- Deposit £X Paid – £X Cash Due
- £X Pending (Stripe/Square)
- If deposit via one processor, balance via another = note both
  e.g. Deposit £25 Paid (Stripe) – £215 Paid (Square)
- Account payment (corporate) = treat as Paid, no emoji


8. BOOKING TYPE & CLASSIFICATION
=================================
FREE ROAM
- Any job with no airport = FREE ROAM
- Local transfers, hotels, venues, stations = FREE ROAM
- V Class and Minibus FREE ROAM = no rotation change
- Executive FREE ROAM = rotation applies normally
- Rolls Royce = always FREE ROAM regardless of destination

AIRPORT HOTEL RULE
- Transfers to/from hotels AT airports = classify as that airport for rotation
- e.g. Radisson Blu Manchester Airport = counts as MAN job
- e.g. Hilton Garden Inn LHR = counts as LHR job

PRIVATE CHARTER AT EMA
- All private charter pickups at EMA = Advantage Flight Support Ltd, Viscount Road, Castle Donington, Derby DE74 2SA
- Still classified as EMA job for rotation
- Flight number in description = Private Charter
- Applies to Kerry Moseley and any other private charter at EMA

MULTI-STOP JOBS
- Via stops go in description as *Stop 1:*, *Stop 2:* etc.
- Pickup location field = first pickup address only
- Stopover charge in summary = include in total, note via stop in description


9. LEAD PASSENGER RULE
=======================
- If lead passenger provided = use their name and contact number as Customer Name and Contact No
- Booker details go in *Notes:* only if relevant
- Title uses lead passenger name — not booker/company name
- Exception: if no lead passenger listed, use customer name


10. PAIRED BOOKING IDENTIFICATION
===================================
- Same reference number with a/b suffix = paired outbound and return
- Same customer, same ref, two journey dates = paired
- Process outbound (a suffix) first, then return (b suffix)
- If ref has no a/b but same customer clearly same trip = treat as paired using judgement
- Different refs, different dates, same customer = independent bookings, normal rotation each


11. FLIGHT NUMBER FORMATTING
==============================
- Remove spaces: FR 5073 → FR5073, EI 3676 → EI3676, SK 2548 → SK2548
- Arrival time in brackets if different from pickup: DL0058 (arr 06:45)
- Private charter = write Private Charter as flight number
- Multiple passengers on different flights = list all
  e.g. DL0058 (arr 06:45) + AA50 (arr 06:20)


12. ADDING TO CALENDAR — PROCESS
==================================
BEFORE ADDING
- Check if booking ref already exists in calendar — search by date and name
- If exists = UPDATE the existing event, never create a duplicate
- Only add if at least one payment line shows Paid or partial deposit paid
- Do NOT add if all payment lines show Pending only
- Test booking refs (1T0OUV, MRHES3) = skip, never add

AFTER ADDING
- Verify event appears in calendar by searching the date
- If event shows as Tentative, immediately update status to Confirmed
- If search returns empty after creation, check calendar 36
- Note the event ID returned for any same-session updates


13. FINDING & UPDATING EVENTS IN CALENDAR
==========================================
SEARCH STRATEGY
- Always search calendar 31 first
- Narrow date range (1-2 hours) for specific events
- Widen to full day if not found in narrow range
- If still not found, try calendar 36
- Cross-reference title, date and customer name to confirm correct event

IDENTIFYING DUPLICATES
- Two events same name same date = duplicate
- Keep the one with 📑 bold asterisk format description
- Delete plain text / old format one
- ETO-imported duplicates have vehicle type in title (EXECUTIVE, MINIBUS) = delete these

UPDATING
- Always search to get current numeric event ID before updating
- Event IDs from previous sessions may not be valid — always search first
- UIDs from ICS files (Google format) cannot be used in update/delete tools
- Must search calendar by date to get numeric ID for updates


14. CORPORATE ACCOUNTS
=======================
JELD-WEN
- Contact: Jackie Donoghue (JDonoghue@jeldwen.com)
- Lead passenger varies per booking — always check and use lead passenger name in title
- Booker name goes in Notes

LB FOSTER
- Contact: Abi Atkin (abi.atkin@lbfoster.com, +447969305166)
- Lead passenger varies — always check

FORGED SOLUTIONS GROUP
- Contact: Nicola Wright (nicola.wright@forged-solutions.com, +447387109751)
- Lead passenger varies — always check
- DRIVER NOTE (mandatory for Meadowhall pickup):
  Turn left at security barrier on arrival. Inform security (Mick) of passenger name — he will direct you.
- Pickup address: Forged Solutions Group, Meadowhall Road, Meadowhall, Sheffield

CALDIC UK LTD
- Contact: L. Ellis (l.ellis@caldic.com, +447736687784)
- Payment by Account — treat as Paid, no emoji
- May have simultaneous BHX and MAN vehicles on same dates — keep separate, do not merge


15. SPECIAL CUSTOMER NOTES
============================
KERRY MOSELEY
- Private charter EMA pickup = Advantage Flight Support Ltd, Viscount Road, Castle Donington, Derby DE74 2SA
- Still classified as EMA job

SAFAH RAJAH
- Frequent customer — multiple addresses: 28 Batworth Drive Sheffield, 54 Larch Avenue Rotherham, 136 Sandford Grove Road Sheffield
- All jobs are V Class — no rotation change
- Each booking is independent even if same customer
- Via stops must be carefully noted

BARRY HUGHES
- Same driver for outbound and return regardless of airport
- If ABDI does outbound, ABDI does return
- One-way job only = normal rotation applies

SHEREE / SHEREEE HALL
- IARIY1a = Sheree Hall (two e's) — outbound
- IARIY1b = Shereee Hall (three e's) — return
- Do NOT correct the spelling — it is intentional per the booking

GILES COKE
- NU1TZ2a outbound pickup: 20 Whirlow Grange Avenue, Sheffield S11 9RW
- NU1TZ2b return dropoff: 17 Linnet Way, Stannington, Sheffield S6 6GE
- Different addresses are correct — do not assume return dropoff matches outbound pickup


16. DO NOT CHANGE WITHOUT INSTRUCTION
=======================================
- Driver names (JABIR, KASH, HAMZA, CHINNY, COVER, TOSEEF, ASAD etc.)
- Payment amounts on historical bookings
- Events before May 2026 — leave in old format
- Custom emoji combinations set by user
- Spelling of Sheree/Shereee Hall
- Any driver assignment already confirmed by user


17. ICS FILE USAGE
===================
- ICS files contain full calendar data and are useful for auditing
- UIDs in ICS (Google format) CANNOT be used in update/delete calendar tools
- Use ICS to identify what exists and what needs fixing
- Then search calendar by date to get numeric event IDs for updates
- ICS file is the most complete source of truth for current calendar state


18. BOOKING COUNT & AUDIT
===========================
- Use code to count and categorise events efficiently
- Exclude non-booking events (e.g. reminders, admin events)
- Cross-reference against ETO CSV when available to identify missing bookings
- ETO test refs (1T0OUV, MRHES3) = skip in all counts


====================================================
END OF RULES
Central Executive Transfers Ltd
Company No. 15749931 | Operator Licence OP037
Confidential
====================================================
