# Central Executive Transfers — business facts (single source of truth)

Every growth agent reads this file first. **Never invent a fact, price, review,
statistic or credential.** If something you need is marked `[CONFIRM]` or missing,
write `[CONFIRM: …]` in your draft and list it for the directors — don't guess.
Keep this file current; it's what stops the agents contradicting each other.

## Company

- **Trading name:** Central Executive Transfers (CET)
- **Base:** Sheffield, South Yorkshire. Serves Sheffield, Rotherham, Barnsley,
  Doncaster, Chesterfield, North Derbyshire and onward UK-wide.
- **Central Executive Transfers Ltd** — Company No. **15749931**, **VAT registered**
  (VAT invoices issued by the Ltd; card payments via Square). VAT number: `[CONFIRM]`.
- **Central Executive Transfers PVT LTD** — sister company for non-VAT private
  customers (card payments via Stripe).
- **Licensed private hire operator** — Operator Licence **OP037** (licensing
  authority `[CONFIRM: Sheffield City Council?]`).
- **Directors:** Abdi and Maj (run the business, also drive).
- Years trading / number of journeys completed: `[CONFIRM]`.
- Insurance (hire & reward, public liability): `[CONFIRM]` before claiming.
- Driver vetting (DBS / licensed drivers): `[CONFIRM]` exact wording before claiming.

## Contact

- **Phone (calls):** 0114 399 8242
- **WhatsApp:** +44 7405 172435
- **Email:** admin@centralexecutivetransfers.co.uk
- **Website:** https://centralexecutivetransfers.co.uk — online booking + instant
  price at the booking page (CET's own widget).

## Fleet / vehicle classes

| Class | Seats | Bags | Notes |
|---|---|---|---|
| Executive saloon (Mercedes E-Class type) | 4 | 2 | Core product; driven by the directors |
| Estate | 4 | 4 | |
| Mercedes V-Class | 7 | 7 | Executive people-carrier |
| Standard minibus (Vito-type) | 7 | 5 | |
| Standard minibus XL | 8 | 8 | |
| Rolls-Royce Ghost | 3 | 2 | On request / quote only — weddings & VIP |

Larger classes may be fulfilled by vetted partner drivers. Never promise a
specific car model/colour in ads.

## What makes CET different (use these — they're true and built)

- **Fixed prices quoted up front** — instant price online; festive surcharges are
  published, no hidden fees.
- **Flight tracking** on airport pickups — driver adjusts to delays; **meet & greet**
  in arrivals.
- **Live driver tracking link** for the customer; driver details sent before pickup.
- **Number-masked calls/texts** between customer and driver (privacy).
- **Card, Square/Stripe payment links, or cash**; **corporate accounts with VAT
  invoices** and combined/monthly invoicing.
- **Directors drive the executive jobs** — a small, accountable, owner-run firm.
- 24/7 bookings `[CONFIRM operating hours]`.

## Example prices (executive saloon, fixed; from the price matrix)

- Barnsley → Manchester Airport **£110** · → Heathrow **£320** · → East Midlands **£100**
- S20 Sheffield → Liverpool Airport **£155**
- Distance pricing elsewhere: first 10 miles flat £50, then per-mile tiers,
  rounded to £5. Full matrix lives in the Command Centre (Pricing). Quote "from"
  prices only after the directors confirm them.

## Customers

- **Private:** airport transfers (Manchester, East Midlands, Leeds Bradford,
  Heathrow, Gatwick, Stansted, Luton, Liverpool, Doncaster/Humberside), events,
  occasions.
- **Corporate accounts:** existing manufacturing/engineering clients in South
  Yorkshire. **Do not name any client publicly** (ads, website, outreach) without
  written permission from that client.
- **Trade:** cover work for other operators (we invoice them; unbranded driver
  link available so our branding never shows to their customer).

## Brand

- Colours: gold `#FBBA2A`, black, white. Font: Inter. Tone: calm, premium,
  plain-English, British spelling. No hype, no emojis in B2B copy.

## Hard rules for all agents

1. **Nothing sends itself.** Agents draft; a director sends every customer
   message, email, LinkedIn note and review reply.
2. **Never touch the live website** (`public_html`). Website changes are delivered
   as files for the directors to upload. App code changes go to staging only.
3. **No fake reviews, testimonials, stats or "No.1" claims.** Google Ads and the
   ASA/CAP code require claims to be provable.
4. **No competitor brand names in ad text.** (Bidding on them is a separate,
   deliberate decision.)
5. **UK GDPR / PECR:** B2B emails to company addresses are fine with a clear
   opt-out; sole traders and private individuals need consent or a prior booking
   (soft opt-in) plus opt-out. Never buy email lists.
6. **Customer data stays private** — booking exports with names/phones go in
   `docs/growth/data/` (git-ignored), never committed, never pasted into public tools.
