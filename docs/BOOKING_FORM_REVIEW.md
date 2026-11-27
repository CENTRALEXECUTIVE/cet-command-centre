# Booking form: customer's-eye review (27 Nov 2026)

Walked through as 12 typical bookings against the current widget (`resources/views/widget/book.blade.php`).
Nothing changed yet. Directors pick what to build.

## Worth fixing (ranked by lost/messy bookings)

1. **No PO number / cost code field.** Business customers (UK Fusion Energy needs a PO on every job; JELD-WEN-type
   accounts use cost codes) have to bury it in notes or email it after. The office form already has cost code +
   reference; the website doesn't. → Optional "PO number / cost code" box when VAT invoice = Yes.
2. **Confirmation only goes to the booker.** An assistant booking for a traveller (Claire for Bill) can't send the
   traveller a copy, so the office forwards it by hand. → Optional "Passenger's email (send them a copy)" when
   "I'm booking for someone else" is ticked.
3. **VAT company details re-typed every time.** A repeat business customer types company name, address and VAT number
   on every booking. → Pre-fill them from their last VAT booking when the same email is entered.
4. **9+ passengers has no route.** Passengers stop at 8, so a group of 10 hits a dead end. → A "9 or more" option that
   becomes a quote request ("we'll arrange two vehicles").
5. **Short-notice message only offers the phone** (and the landline had problems on 20 Nov). → Add the WhatsApp and
   email links to the "we need X hours' notice" message.
6. **Return has no stops.** The outbound can add stops; the Return page can't. → "Add a stop" on the Return page.

## Small polish

7. Label dates "(UK time)" for overseas travellers booking from abroad (the form already works in UK time; it just
   doesn't say so).
8. Airport pickups: say what the price includes for waiting ("we wait free for X minutes after landing") right under
   "Flight landing date & time". Needs the directors' waiting policy figure.

## Checked and fine

One-way, return (own page, own addresses, passengers, luggage, flight), airport arrival either leg, booking for someone
else, child/booster seats, via stops (outbound), VAT invoice, card/cash/account, Rolls-Royce quote request, odd phone
numbers (never block), page left open/expired, double-click, a form left half-done (logged + alert).
