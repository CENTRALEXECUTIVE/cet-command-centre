# ETO → CET data migration (cutover)

The ETO **Customers** and **Locations** exports are the cutover data. **The
customer export is NOT stored in this repo — it contains personal data (names,
emails, phones, addresses).** It is imported from the temp upload at cutover and
never committed, per the project PII rule.

## Customers export — what it tells us
- ~63 customers; a mix of private individuals and **corporate account** contacts
  (Account payment = Yes), each with company name + (some) VAT/company numbers.
- Some duplicate people (e.g. one person under two emails) — the CET leaderboard's
  same-person merging (email/phone/name) already handles these on reports; the
  importer should also de-dupe on email/phone.

## Corporate accounts to pre-seed (with their email domains)
These drive the automatic domain roll-up on the leaderboard/reports. Seed the
account, then every traveller on the domain groups under it automatically:

| Business | Email domain |
|---|---|
| JELD-WEN UK | `jeldwen.com` |
| LB Foster | `lbfoster.com` |
| Forged Solutions | `forged-solutions.com` |
| ASSA ABLOY | `assaabloy.com` |
| VolkerWessels UK | `volkerwessels.co.uk` |
| EMG Solicitors | `emgsolicitors.com` |
| Caldic UK | `caldic.com` |
| (others appear as one-off company customers — tag individually) | |

> Note: JELD-WEN's real email domain is **`jeldwen.com`** (no hyphen), though the
> company name is styled "JELD-WEN". The domain match keys on the email, so it's
> correct.

## Migration tasks (for cutover, not now)
1. **Customer importer** — CSV → customers, de-duped on email/phone, mapping
   company + VAT/company number, and Account-payment flag → corporate account.
   (CET imports ETO *bookings* today; a *customer* importer is the gap.)
2. **Pre-seed corporate accounts** (above) with their domains/billing emails so
   the roll-up and account billing work from day one.
3. **Locations** — import airports + cruise ports from `data/eto-locations.csv`.
4. **Forward bookings** — import any future-dated ETO bookings (existing ETO CSV
   booking import + audit reconcile).
5. **Verify** with the ETO audit + the `cet:corporate-domains` command.

## Migration gaps → build list
- **Customer CSV importer** (Need for cutover).
- Corporate-account seeder with domains (Need — small).
