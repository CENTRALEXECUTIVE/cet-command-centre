# CET house email style (approved by the directors, Nov 2026)

**This is the standard for every outreach and business email.** It replaces the
earlier "personalised opener" drafts (cold-email-rules-v2.md), which the directors
rejected. When asked to write any prospecting or business email, copy this tone.

## The tone
- **Formal and confident.** "Dear [Name]," (or "Dear Sir or Madam," with no name).
  "Kind regards, Central Executive Transfers". Never sign with Abdi's or Maj's name.
- **About the service, not about them.** Do NOT open with observations about the
  prospect's site, news, projects or industry. It reads as odd. Say what we do and
  how we help.
- **Professional and premium**, plain British English. No slang, no hype words, no
  em dashes.
- **Structure:** one strong opening line about the value → one line saying who we
  are → "With a corporate account, you benefit from:" + a short bullet list of
  concrete benefits → one line on an extra service → one polite closing question.
- **Partners (chauffeur/transfer operators):** do NOT mention unbranded service or
  driver links. They expect it. Focus on coverage, fleet, standards and reciprocal work.
- Facts only from `docs/growth/BUSINESS_FACTS.md`.

## Approved email 1: businesses (subject: `Corporate chauffeur account`)
Dear [Name],

Your executives and visiting clients deserve transport that's professionally managed from booking through to arrival.

We're Central Executive Transfers, a Sheffield-based operator providing executive chauffeur services nationwide, 24/7.

With a corporate account, you benefit from:

- Executive cars, Mercedes V-Class and minibuses
- Reliable airport, rail and business transfers
- Flight monitoring and driver details ahead of collection
- Fixed fares, agreed in advance
- One monthly VAT invoice with PO references or cost codes
- Convenient 30-day payment terms

We also provide hourly chauffeur hire, keeping the vehicle available throughout meetings, roadshows and business events.

Could we send you a brief overview of our corporate account service for future travel requirements?

Kind regards,
Central Executive Transfers

## Approved email 2: chauffeur operators (subject: `Reciprocal partnership: Sheffield chauffeur operator`)
Dear [Name],

We're looking to establish relationships with professional chauffeur operators who value reliable regional coverage and reciprocal work.

Central Executive Transfers is a Sheffield-based, council-licensed private hire operator, trading since 2024.

We can support your bookings with:

- Coverage across Sheffield, South Yorkshire and nationwide
- Executive saloons, estates, Mercedes V-Class and minibuses
- Airport transfers, corporate journeys, events and hourly hire
- 24/7 availability and fixed prices agreed in advance
- Council-licensed, enhanced-DBS-checked chauffeurs
- Flight tracking and driver details before every pickup

We're equally interested in building relationships with operators elsewhere in the UK to whom we can refer work in their respective areas.

Would you be interested in exchanging operator details and discussing a reciprocal partnership?

Kind regards,
Central Executive Transfers

## Where it lives in the app
- First emails: `email_subject` / `email_body` in `docs/growth/sales/*.csv`, loaded by
  the draft-refresh migration (only never-emailed, unedited prospects).
- Follow-ups, signature, opt-out: `config/outreach.php`.
- `[Name]` → contact first name, else "Dear Sir or Madam," (`OutreachEmailComposer`).
