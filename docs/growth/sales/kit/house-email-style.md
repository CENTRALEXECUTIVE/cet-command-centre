# CET house email style (approved by the directors, updated 3 Nov 2026)

**This is the standard for every outreach and business email.** The two emails
below (SCS, Rolls-Royce and Sheffield DocFest) are the directors' chosen examples and replace the
earlier approved emails. Copy their tone, length and shape exactly.

## The tone
- **Formal, warm and confident.** "Dear [Name]," — or "Dear [Company] Team," when
  writing to a company without a named contact. Plain British English, no slang,
  no hype words, no em dashes.
- **Open with who we are:** "I'm reaching out from Central Executive Transfers, a
  Sheffield-based [council-licensed] executive transport [operator/company] ..."
- **Then ONE short line linking us to them**, built on a plain, checkable fact
  about the prospect (their site/office location, or that they're expanding),
  framed around how WE can support them. E.g. "With your Advanced Blade Casting
  Facility based in Rotherham, we'd welcome the opportunity to support your team
  with ...". One line only, factual, never flattery or commentary on their news.
- **A short bullet list** ("Our corporate service includes:" / "We can offer:") of
  concrete benefits: fleet, coverage, airports, 24/7, licensed chauffeurs, fixed
  fares, flight monitoring, meet and greet, payment terms, VAT invoices with PO refs.
- **One extra line** (hourly hire for businesses; referring work back for operators).
- **One polite closing question**, a single clear ask: exchange operator details,
  or "Could you kindly direct me to the person responsible for corporate travel
  arrangements or transport suppliers at ...?" when writing to a general inbox.
- **Sign off** "Kind regards," and stop. The directors' mail app adds the signature
  (updated 5 Nov 2026): **Matthew Green / Central Executive Transfers / 0114 399 8242 /
  www.centralexecutivetransfers.co.uk** + the CENTRAL logo. Drafts therefore carry no
  signature block and no opt-out line (`config/outreach.php`). Never sign with Abdi's or
  Maj's name. Replies may come back addressed "Hi Matthew"; that's expected.
- **Partners (chauffeur/transfer operators):** do NOT mention unbranded service or
  driver links. They expect it. Focus on coverage, fleet, standards and reciprocal work.
- **Medical, care, school and council prospects:** say the chauffeurs are licensed and
  enhanced DBS-checked. **Wedding venues:** add the Rolls-Royce Ghost (on request).
- Facts only from `docs/growth/BUSINESS_FACTS.md`.

## Approved email 1: chauffeur operator (SCS)
Subject: `Regional partner: South Yorkshire and North Derbyshire`

Dear Garry,

I'm reaching out from Central Executive Transfers, a Sheffield-based, council-licensed executive transport operator.

With SCS expanding its national presence, we'd welcome the opportunity to become a trusted regional partner, supporting your corporate and airport transfer bookings across South Yorkshire and North Derbyshire.

We can offer:

- A fleet of Mercedes-Benz executive saloons, estates, V-Class vehicles and minibuses
- Reliable coverage across Sheffield, Rotherham, Doncaster, Barnsley and Chesterfield
- Manchester, East Midlands and Leeds Bradford Airport transfers
- 24/7 operational support and professional, licensed chauffeurs
- Fixed trade rates, flight monitoring and clear booking communication

We also work with operators across the UK and would be happy to refer suitable bookings in your area, building a mutually beneficial relationship.

Would you be open to exchanging operator details and discussing how we could support each other's bookings?

Kind regards,

Central Executive Transfers
0114 399 8242
admin@centralexecutivetransfers.co.uk
www.centralexecutivetransfers.co.uk

## Approved email 2: business (Rolls-Royce)
Subject: `Executive travel for your Rotherham team`

Dear Rolls-Royce Team,

I'm reaching out from Central Executive Transfers, a Sheffield-based executive transport company supporting businesses across South Yorkshire and nationwide.

With your Advanced Blade Casting Facility based in Rotherham, we'd welcome the opportunity to support your team with reliable executive travel, airport transfers and transport for visiting clients.

Our corporate service includes:

- Mercedes-Benz executive cars, V-Class vehicles and minibuses
- 24/7 availability for business and airport transfers
- Professional, licensed chauffeurs and fixed, pre-agreed fares
- Flight monitoring and meet-and-greet services
- Flexible payment options, including payment on booking or monthly invoicing with 30-day terms
- VAT invoices with PO references where required

We also offer dedicated hourly chauffeur hire for meetings, site visits and business events.

Could you kindly direct me to the person responsible for corporate travel arrangements or transport suppliers at your Rotherham facility?

Kind regards,

Central Executive Transfers
0114 399 8242
admin@centralexecutivetransfers.co.uk
www.centralexecutivetransfers.co.uk

## Approved email 3: events organisation (Sheffield DocFest)
Subject: `Transport for DocFest guests and speakers`

Dear Sheffield DocFest Team,

I'm reaching out from Central Executive Transfers, a Sheffield-based executive chauffeur company providing professional transport services across the UK.

As one of Sheffield's major international events, DocFest welcomes filmmakers, industry professionals and guests from around the world. We'd welcome the opportunity to support your team with their transport arrangements.

Our services include:

- Executive Mercedes-Benz cars, V-Class vehicles and minibuses
- Airport and railway station transfers for visiting guests
- VIP, speaker and delegate transportation
- Dedicated chauffeurs for festival events and multi-day bookings
- 24/7 availability, flight monitoring and fixed, pre-agreed fares
- Flexible payment options, including payment on booking or monthly VAT invoicing with 30-day terms

Being based locally, we're well positioned to provide reliable transport throughout the festival and for events held during the year.

We'd be pleased to discuss becoming one of your trusted transport partners for future events.

Could you kindly direct me to the person responsible for festival transport and logistics?

Kind regards,

Central Executive Transfers
0114 399 8242
www.centralexecutivetransfers.co.uk

## Where it lives in the app
- First emails: `email_subject` / `email_body` in `docs/growth/sales/*.csv`, loaded by
  the draft-refresh migration (only never-emailed, unedited prospects).
- Follow-ups, signature, opt-out: `config/outreach.php`.
- `[Name]` → contact first name, else "Dear [Company] Team," (`OutreachEmailComposer::teamName`
  strips "(Sheffield office)" etc.); "Dear Sir or Madam," only with no company name.

## Replies to customers and partners (directors, 11 Nov 2026)

The directors prefer short, plain replies. Two they wrote themselves and want as the model:

> Good morning Nicola,
>
> Thank you for getting in touch.
>
> We can confirm availability for both journeys on 14th and 16th December.
>
> Kind regards,

> Hi,
>
> Thank you for getting back to us.
>
> We operate with a combination of our own fleet and trusted, fully licensed chauffeurs who work closely with us.
>
> Our fleet includes 4 Mercedes V-Class vehicles and several Mercedes E-Class vehicles.
>
> Regarding pricing, this would depend on the journey, vehicle requirements and duration. We'd be happy to offer competitive trade rates on any enquiries you send our way.
>
> We look forward to working together.
>
> Kind regards,

Rules for replies:
- Answer exactly what was asked, in 2 to 5 short sentences. No bullet lists, no feature lists, no sales pitch.
- Open "Good morning/Hi [Name]," then "Thank you for getting in touch / getting back to us."
- Never leave placeholders like [£XXX] in a draft. If a figure isn't known, say it depends and offer to quote.
- No quoted prices unless the directors give them; for partners, "competitive trade rates".
- Don't ask for lots of details up front; confirm first, details follow.
- End at "Kind regards," (the Outlook signature adds Matthew Green / Central Executive Transfers; never type the company name above it).
- Facts confirmed 11 Nov: own fleet plus trusted fully licensed partner chauffeurs; 4 Mercedes V-Class and several Mercedes E-Class; no S-Class or i7.
