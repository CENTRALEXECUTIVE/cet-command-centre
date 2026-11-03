# CET house email style (approved by the directors, updated 3 Nov 2026)

**This is the standard for every outreach and business email.** The two emails
below (SCS and Rolls-Royce) are the directors' chosen examples and replace the
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
- **Sign off** "Kind regards," then the company signature (name, phone, email,
  website). Never sign with Abdi's or Maj's name.
- **Partners (chauffeur/transfer operators):** do NOT mention unbranded service or
  driver links. They expect it. Focus on coverage, fleet, standards and reciprocal work.
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

## Where it lives in the app
- First emails: `email_subject` / `email_body` in `docs/growth/sales/*.csv`, loaded by
  the draft-refresh migration (only never-emailed, unedited prospects).
- Follow-ups, signature, opt-out: `config/outreach.php`.
- `[Name]` → contact first name, else "Dear Sir or Madam," (`OutreachEmailComposer`).
