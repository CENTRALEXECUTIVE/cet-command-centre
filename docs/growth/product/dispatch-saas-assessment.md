# Selling the Command Centre to other operators — viability assessment

Owner: product · Date: 28 Oct 2026 · Status: assessment, not a plan to build now

## Bottom line

**Not yet, and probably not on its own.** The Command Centre is strong for CET,
in places stronger than ETO (masked calls, watchdog and emergency calls, AI
paste-a-booking, the airport cash rules, two VAT entities). But it is built
*as CET*: a single tenant, CET's names and rules in the code, CET's Google
Calendar as the source of truth, and auto-deploy from a branch to shared
hosting. The low end of the market, where small chauffeur firms buy, is
priced at about **£35–45 a month** (ETO, reported). At that price, ten
customers bring in less than one good corporate account. The effort, support
load and risk to CET's brand are real.

**Recommendation:** keep the option open at near-zero cost (stop adding new
CET-specific hard-codes; see the smallest first step). Hit the CET reliability
bar first: 90 days with no incidents. Then run a **free pilot with 1–2 friendly
partner operators** on an **instance per operator**. Decide go/no-go on
measured pilot results, not on hope.

## 1. Competitor landscape (verified 28 Oct 2026)

Prices are what search results reported. Several come from third-party listings
that disagree with each other. Treat them as ranges and confirm with each vendor
before quoting. ETO's own site couldn't be fetched from this session (network
allow-list), so its figures come from search snippets of its pricing page.

| Product | Who it's for | Pricing reported | Notes |
|---|---|---|---|
| **EasyTaxiOffice (ETO)** — UK | Small taxi/PH/chauffeur operators; CET's current system | Cloud **Starter £35/mo ex VAT, up to 5 drivers (+£2/extra)**; **Business £45/mo ex VAT, up to 15 drivers (+£1/extra)**; passenger app **£200 setup + £20/mo**; 30-day trial; only drivers who accepted at least one job in the month are billed. An older article gives £30/£40. | Dispatch panel, web booking widgets, driver and passenger apps, corporate accounts with deferred payment and periodic invoices, a notification matrix that includes fleet operators. Competitors describe its UI as dated — that's competitor marketing. |
| **Autocab (Ghost)** — UK | Mid–large taxi/PH fleets | Quote-based. Historic **£2/car/week** (2015 press). One listing says $150/mo up to 10 drivers + $15 per driver (unverified). | Incumbent; says it serves 90,000+ UK private hire vehicles. Capterra has only 4 reviews, with low support scores. |
| **iCabbi** — UK/IE | Mid–large fleets | Quote only; no public price | Enterprise sales motion |
| **Cab Treasure** — UK | Small–mid taxi firms | **~£15/vehicle/mo** (one third-party listing, unverified) | Driver app needs a company activation code |
| **TaxiCaller** — global | Small–mid fleets | **~CAD 29/vehicle/mo** pay-as-you-go, 14-day trial, volume discounts above 20 vehicles | Self-serve |
| **Cabica** — UK | Small fleets | From **£30** flat (listing) | |
| **Moovs** — US | Chauffeur/limo, small–mid | **$129–199/mo**, enterprise $499–999 (third-party, conflicting) | Quote/deal follow-up automation; chauffeur-first |
| **Limo Anywhere** — US | Chauffeur/limo | **$79–219/mo** tiers capped by trip volume (third-party, conflicting); onboarding fees reported | Large affiliate/farm-out network ("LA Net", 5,400+ affiliates claimed) |

**What this tells us**

- UK small-operator software is **cheap and per-driver**. ETO is the price anchor
  for exactly the firms CET would target.
- US chauffeur tools charge 3–5x more ($129–219/mo) and sell on **quote follow-up,
  affiliate farm-out and polish**. That's the closest analogue to CET's positioning.
- None of the products checked is sold as "built and run by a working executive
  operator". That's CET's only truly unique angle, and it is also the risk:
  customers will expect the vendor to answer at 5am.

## 2. Positioning (if it ever goes ahead)

- **Who:** UK executive/chauffeur operators with **2–15 vehicles**, owner-drivers,
  airport-heavy, some corporate accounts. Today they run on ETO, or on
  WhatsApp + Google Calendar + spreadsheets.
- **Promise:** "Run a premium chauffeur firm from your phone — airport jobs, cash
  vs card balances, driver tracking and masked calls done right."
- **Differentiators that are real and built:** airport-pair cash rules and the
  deposit/balance logic; masked calls/texts; watchdog + emergency call for
  at-risk jobs; AI paste-a-booking; driver PWA with geofenced status; two-entity
  VAT/no-VAT billing; Google Calendar mirror for calendar-first operators.
- **Not a fit:** high-volume taxi firms (Autocab/iCabbi territory) or operators who
  want automatic SMS everywhere (against CET's own model).

### Pricing hypothesis (to test in the pilot, not to publish)

- **Bring your own keys:** each operator uses its own Google Maps, Twilio, Square/
  Stripe and SMTP. That keeps CET's cost rule intact and avoids reselling
  telecoms or payments. AI: either their own Anthropic key, or a capped allowance
  included in the fee.
- **£59–99/month flat for up to 10 drivers**, plus a one-off onboarding fee
  (£150–300) for importing ETO data and setting up the pricing matrix. That's
  above ETO's £35–45, justified only if the pilot proves the extras matter.
- **Revenue reality:** 10 operators × £79 ≈ **£790/month (£9.5k/yr)**; 25 ≈ £2k/month.
  Against that: hosting (£30–80/mo for a proper VPS/managed host), insurance
  `[CONFIRM: professional indemnity + cyber quotes]`, payment fees on
  subscriptions, and above all **director time**. One new corporate account for
  CET's own chauffeur work is likely worth more, sooner, with no new risk.

## 3. What multi-tenancy requires in THIS codebase

Counted from the repo on 28 Oct 2026: **48 Eloquent models**, ~72 migrations, ~280
named routes, **62 console commands** (22 on the schedule), **1,811 tests** (all
single-tenant). **No tenant/company column exists anywhere** (`grep
tenant|company_id|team_id` in `app/` only matches Microsoft Graph's own "tenant" id).

| Area | Today | What a second operator needs | Effort |
|---|---|---|---|
| **Data scoping** | Every model global; unique indexes global (e.g. `bookings.external_reference`) | Every query scoped: controllers, 62 console commands, services, `DB::table` calls, route-model binding, composite unique indexes. Or a separate database per operator. | L |
| **Settings & secrets** | `settings` table is one global key/value store (Square/Stripe keys, Maps key, mail, `driver_pay_percent`, booking settings); `config/cet.php` holds CET values (holiday surcharges, ops email, company details, min notice, AI model) | Per-operator settings, secrets encrypted at rest, a settings UI for everything now in `config/cet.php` | M–L |
| **CET hard-codes** | ~178 references to CET/Abdi/Maj/Sheffield across 68 files in `app/` (some are comments). Rotation is Abdi → Maj, callsigns ABDI/MAJ, `DriverRosterService`, emergency routing to `abdi@`/`maj@`, `BookingNotifier` wording and signature, `CalendarEventBuilder` CET title format, `InvoiceProfile` two CET entities, office WhatsApp number | All behind per-operator config; rotation and calendar title rules become options | L |
| **CET data migrations** | Several migrations change CET's own records (`restore_booking_c96396`, `fix_n8caajb_passenger_count`, `create_meps_corporate_account`, `set_customer_masking_line`, `seed_editable_fixed_price_matrix`…) | Must be fenced so they never run against another operator's database | S–M |
| **Integrations** | One Google Calendar credential, one Graph mailbox (the ETO email feed), one Twilio Proxy service, one VAPID pair, Square/Stripe webhooks at fixed URLs | Per-operator credentials; webhooks resolve the operator (by URL token or account id); calendar mirror optional (most operators aren't calendar-first) | M–L |
| **Branding** | PWA manifest/icons/`sw.js`, widget, emails, invoices, tracking page, CSS variables — all CET gold/black | Per-operator name, logo, colours, sender address, legal entities, VAT numbers | M |
| **Domains & widget** | One staging domain; widget CSP `frame-ancestors` for CET's site | Per-operator subdomain/custom domain, CSP per operator | M |
| **Billing** | None | Subscriptions, trials, failed-payment handling, suspension (e.g. Stripe Billing — fees apply) | M |
| **Files, backups, retention** | One private disk (booking files, driver documents), one backup job, GPS and alert pruning global | Per-operator storage paths, per-operator backups and **tested restores**, export on exit | M |
| **Roles & support access** | admin / super-admin / driver / corporate client; impersonation exists | Office-staff role; a **platform support role** that cannot browse an operator's data without a logged, time-limited grant | M |
| **Release process** | `cet:auto-deploy` pulls the branch every minute or two onto shared GoDaddy hosting; OPcache workarounds; a DNS outage on 21 Sep took the site down | Versioned releases, a staging tenant, rollback, change notes, maintenance windows. Auto-deploy on push is unacceptable once others depend on it. | M |
| **Hosting & SLA** | Shared cPanel/LiteSpeed | VPS or managed Laravel hosting, monitoring, uptime target (e.g. 99.5%), incident process | M (+ monthly cost) |
| **Tests** | Single-tenant | Cross-tenant isolation tests on every controller and command (operator A can never see B's booking, driver, file, invoice, GPS) | L |
| **Onboarding** | CET seeded by hand and by data migrations | Wizard: company, vehicles, pricing matrix, drivers, payment keys, ETO CSV import (`ImportController` + `EtoBookingImporter` already exist — a genuine head start) | M |

### Architecture options

| Option | How | Isolation | Effort | Verdict |
|---|---|---|---|---|
| **A. Instance per operator** | Same repo; one checkout + DB + subdomain + `.env` per operator; CET-specific code moved into config | Strongest (separate DBs, files, keys) | **3–6 weeks** to config-ise hard-codes, fence data migrations, script provisioning, release process | **Use for the pilot (1–5 operators).** Cost grows per operator but there's no data-leak class of bug |
| B. Database per tenant, one app | A tenancy package switches DB per request/command | Strong | 2–4 months; scheduler and queue per tenant; all 22 schedules iterate tenants | Only after go criteria with 5+ paying operators |
| C. Shared DB with `tenant_id` | Global scopes on 48 models | Weakest — one missed scope leaks a rival's customers | 3–6 months plus permanent vigilance | **Don't** — the worst risk for a firm selling to its own competitors |

## 4. GDPR — CET becomes a data processor

For another operator's customers and drivers, that operator is the **controller**
and CET is the **processor** (UK GDPR Art. 28). ICO guidance requires a written
contract covering: the subject, duration, nature and purpose of processing; types
of data and data subjects; acting only on the controller's instructions; staff
confidentiality; security measures (Art. 32); help with data-subject rights,
breach notification and DPIAs; **deleting or returning all data at the end**;
audits/inspections; and **no sub-processors without prior written authorisation**,
with flow-down contracts. The processor remains liable for its sub-processors.

CET-specific points:

- **Sub-processors to list:** hosting provider, Google (Maps, Calendar), Microsoft
  (Graph, if used), Twilio, **Anthropic** (AI extraction of booking/enquiry text,
  US-based, so an international transfer mechanism is needed — confirm the
  current UK route, e.g. IDTA/UK Addendum or the UK–US data bridge, with an
  adviser), push services. Square/Stripe would be the operator's own accounts, so
  the operator is their customer directly.
- **Competitor trust:** CET would hold rival operators' customer lists. This needs
  a contractual ring-fence and a technical one: no standing access, logged and
  time-limited support access, and CET staff never using pilot data commercially.
  Without both, no sensible operator should sign. This may be the biggest
  commercial objection.
- **Licensing records:** operators' councils typically require booking records,
  including cancellations, for at least 12 months (council conditions vary). If
  CET loses their data, their licence compliance is at risk too. Backups with
  tested restore become a contractual duty.
- CET already pays the ICO fee as a controller `[CONFIRM]`. The privacy notice,
  DPA template, sub-processor list and a breach runbook are needed before pilot day one.

## 5. Risks (honest)

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| A bug at a pilot operator (wrong pickup time, wrong cash amount) damages CET's name in a small local trade | Medium | High | Pilot only after 90 clean CET days; instance per operator; pilots told it's a pilot; limitation-of-liability terms |
| Director time pulled away from CET's growth (ads, corporate sales) | High | High | Cap at ~4 h/week; pause the pilot if CET growth targets slip |
| Support at unsociable hours (airport runs start at 03:00) | High | Medium | Published support hours; pilots keep a fallback (ETO export) for the first months |
| Data leak between operators | Low with option A / higher with C | Severe (ICO, trust) | Option A only; isolation tests; no shared admin |
| Hosting outage takes every customer down (shared GoDaddy, DNS incident 21 Sep) | Medium | High | Move to a VPS/managed host before the pilot; monitoring; backups off-site |
| Channel conflict: pilot operators are also CET's cover partners/suppliers | Medium | Medium | Pick partners where the relationship is already good; keep commercial terms separate |
| Maintainability: much of the code was AI-written at speed; 1,811 tests help but domain rules are CET-shaped | Medium | Medium | Refactor to config before tenant #2; keep "money rules locked in" tests per operator |
| Price ceiling: ETO at £35–45/mo | High | Medium | Sell on the chauffeur extras, or don't sell |

## 6. Pilot plan (only after gate 1 below)

1. **Months 0–3 (now): CET only.** Ship the roadmap's safety items (error tracking,
   scheduler alarm, driver change alert). Stop adding new CET hard-codes. Measure
   incidents.
2. **Month 4: prepare (about 4–6 weeks of build).** Config-ise CET hard-codes;
   fence data migrations; per-instance provisioning script; versioned releases
   instead of auto-deploy; move hosting; DPA, pilot terms, privacy notice,
   sub-processor list; insurance quotes.
3. **Months 5–7: pilot with 1–2 friendly partner operators** (from the operators
   the sales agent signs as cover partners, ideally an executive firm of 3–10 cars
   already on ETO). Free for 3 months, own keys, ETO data import, a weekly
   30-minute check-in, a shared issue list. CET keeps running on its own instance,
   untouched by pilot changes until they're proven.
4. **Month 8: decide** using the checklist below.

**Pilot success measures:** operator uses it as the main system for at least 8
weeks; at least 20 bookings/week through it; zero data incidents; no more than
2 hours of support a week per operator; operator says they'd pay at least £59/month
(ideally pre-signs).

## 7. Go/no-go checklist

**Gate 1 — before any pilot**
- [ ] CET has 90 consecutive days with **no Sev-1 incidents** caused by the system
      (wrong time/price/cash sent, missed job, lost booking, money mis-marked).
- [ ] Error tracking and a scheduler-dead alarm live; backups restored in a test.
- [ ] Hosting off shared cPanel, or a written decision accepting the risk.
- [ ] Versioned release process replaces auto-deploy-on-push for the pilot instance.
- [ ] CET hard-code count in `app/` behind config, outside comments: **0** in
      shared code paths (enforced by a test).
- [ ] CET data migrations fenced from other instances.
- [ ] DPA, pilot terms, privacy notice, sub-processor list, breach runbook signed off.
- [ ] Professional indemnity + cyber insurance in place `[CONFIRM cost]`.
- [ ] Both directors agree in writing, with a weekly time cap.

**Gate 2 — after the pilot (go = all true)**
- [ ] Pilot success measures met for at least one operator.
- [ ] No CET incident traceable to pilot work.
- [ ] Support load sustainable (no more than 2 h/week per operator) without
      eating into the directors' driving and sales time.
- [ ] Unit economics positive at the tested price (hosting + support time +
      insurance + billing fees < revenue, with margin).
- [ ] A named person (not a director on a 04:00 airport run) owns support hours.

**No-go triggers (stop immediately):** any cross-operator data exposure; a CET
customer affected by a pilot change; support above 5 h/week for a month; pilots
unwilling to pay at least ETO's price.

## 8. Cost (to CET)

- Build: 4–6 weeks of development time for option A, before any revenue.
- Running: hosting £30–80/mo; insurance `[CONFIRM]`; subscription billing fees;
  no change to the "only Maps and Anthropic cost money" rule for CET itself,
  because operators bring their own Maps/Twilio/payment keys.

## Sources

- [EasyTaxiOffice — Cloud pricing](https://easytaxioffice.co.uk/pricing/cloud/) (figures via search snippet; page not fetchable from this session)
- [EasyTaxiOffice — Purchasing/licensing FAQ](https://easytaxioffice.co.uk/purchasing-licensing-faq/)
- [Taxi dispatch system cost 2026 (older ETO figures)](https://www.aslpreservationsolutions.com/how-much-does-a-taxi-dispatch-system-cost)
- [EasyTaxiOffice KB — dispatch & compliance system](https://kb.easytaxioffice.com/help/easytaxioffice-private-hire-dispatch-and-compliance-system)
- [EasyTaxiOffice — Dispatch features](https://easytaxioffice.co.uk/solutions/taxi-dispatch-system/dispatch-features/)
- [A to Z Dispatch vs EasyTaxiOffice (competitor view)](https://atozdispatch.com/atoz-vs-easy-taxi-office), [Yelowsoft — ETO alternatives (competitor view)](https://www.yelowsoft.com/alternatives/easytaxioffice-alternatives/)
- [Ghost Cloud (Autocab) — Capterra UK](https://www.capterra.co.uk/software/153213/ghost-cloud), [Autocab and CityFleet deal](https://www.thebusinessdesk.com/northwest/?p=2055825), [Autocab dispatch system — Capterra](https://www.capterra.ca/reviews/172161/autocab-dispatch-system)
- [iCabbi — toolradar](https://toolradar.com/tools/icabbi), [iCabbi pricing tracker](https://getpulsesignal.com/pricing/icabbi)
- [Cab Treasure — Slashdot comparison](https://slashdot.org/software/comparison/CAB-X-vs-Cab-Treasure/), [CabTreasure driver app](https://apps.apple.com/us/app/-/id1636504343)
- [TaxiCaller FAQ](https://taxicaller.com/en/faq), [TaxiCaller Quebec pricing page](https://www.taxicaller.com/campaigns/en/quebec)
- [Cabica — GetApp](https://www.getapp.co.uk/software/2141618/cabica)
- [Moovs pricing — Capterra](https://www.capterra.com/p/209139/moovs/pricing/), [Moovs — SoftwareSuggest](https://www.softwaresuggest.com/moovs/pricing), [Moovs — SoftwareFinder](https://softwarefinder.com/fleet-management-software/moovs)
- [Limo Anywhere — SoftwareSuggest pricing](https://www.softwaresuggest.com/limo-anywhere/pricing), [ITQlick](https://www.itqlick.com/limo-anywhere/pricing), [Limo Anywhere affiliate management](https://www.limoanywhere.com/?p=13792)
- [ICO — contracts between controllers and processors](https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/accountability-and-governance/guide-to-accountability-and-governance/contracts/)
- Operator licence record-keeping: [Harlow conditions](https://www.harlow.gov.uk/sites/default/files/documents/Private%20hire%20operator%20licence%20conditions.pdf), [Wolverhampton](https://wolverhampton.moderngov.co.uk/mgConvert2PDF.aspx?ID=272775)

## Smallest first step:

Add one PHPUnit "ratchet" test that counts CET-specific identifiers (Abdi, Maj,
ABDI/MAJ callsigns, `abdi@`/`maj@`, the office WhatsApp number, "Central Executive")
in `app/` outside comments, and fails if the count **goes up**. It changes no
behaviour and carries no risk to CET. It costs nothing, and every later feature
is then pushed towards config instead of new hard-codes. That keeps the SaaS
option open while the business stays focused on CET.
