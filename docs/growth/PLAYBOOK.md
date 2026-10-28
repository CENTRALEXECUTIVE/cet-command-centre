# CET growth playbook (Oct 2026)

Goal: more paid bookings, fast, for little money. The Command Centre is built;
the bottleneck is customers. Agents live in `.claude/agents/`, facts in
`BUSINESS_FACTS.md`, ready-made prompts in `PROMPTS.md`.

## The team

| Agent | Job | One number it's judged on |
|---|---|---|
| `google-ads-strategist` | Tracking, audits, keywords, ads, weekly changes | Cost per booking |
| `b2b-sales-hunter` | Corporate accounts + nationwide operator partners | Meetings → new accounts |
| `seo-local-search` | Search Console, pages, Google Business Profile, citations | Organic + map clicks/calls |
| `reviews-retention` | Google reviews, replies, repeat/seasonal campaigns | Reviews/week, repeat bookings |
| `ops-booking-qa` | Booking/payment flow never breaks; bug fixes | Zero lost bookings |
| `product-strategist` | Speed-to-lead, app polish, dispatch-SaaS plan | Lead response time |

Agents draft and analyse; a director sends, posts and clicks. Nothing goes out
automatically.

## 30-day plan

**Week 1 — stop the leaks**
1. ops-booking-qa: booking health check; director does one real Stripe and one
   Square booking end to end.
2. google-ads-strategist: GTM conversion tracking walkthrough, then the account
   cleanup (pause losers, negatives, presence-only location, manual CPC).
3. seo-local-search: Search Console sitemap + request indexing for money pages;
   Google Business Profile tidy-up.

**Week 2 — free/cheap revenue**
4. reviews-retention: send every review request; win-back message to recent
   airport customers.
5. b2b-sales-hunter: first 10 corporate prospects + 10 operator partners;
   referral ask to existing account clients.

**Weeks 3–4 — scale what works**
6. Ads: weekly change list; move budget to campaigns that book.
7. Sales: 10 new prospects/week + follow-ups. SEO: one new page/week.
8. product-strategist: speed-to-lead spec → ops-booking-qa builds phase 1.

**Every Monday:** run the "Monday brief" prompt (PROMPTS.md) — numbers, what
worked, the 5 jobs for the week.

## AI speed-to-lead (design)

Whoever replies first usually wins the booking. Target: **every enquiry
answered within 5 minutes**, 07:00–23:00.

- **Phase 1 (within current rules — build first):** a web quote request or
  enquiry triggers an instant critical push + chime to both directors, with an
  AI-drafted reply (price from the pricer, vehicle, pickup time) and one-tap
  WhatsApp/email send. If nobody taps within 5 min, escalate (second push / ring
  the on-call director). Log response time per lead; show it on the dashboard.
- **Phase 2 (needs the directors to change rule #2 in CLAUDE.md):** an instant
  automatic acknowledgement to the customer who just submitted the form
  ("Thanks — Abdi will send your fixed price within 15 minutes"). It's
  customer-initiated and transactional, but it IS an automatic send — decision
  for the directors.
- **Phase 3:** missed-call capture (Twilio already wired) → instant office alert
  with caller number and a "call back" button; after-hours voicemail to text.

## Replit and selling the dispatch system

- **Don't rebuild in Replit.** The Command Centre is already a working Laravel
  app with ~1,800 automated tests. Rebuilding would take months, reintroduce
  bugs that were fixed with real money, and stop growth work. "Professional
  app" comes from polish on what exists: the installable app (PWA) already
  works on phones; add a system health page, error tracking, onboarding and
  cleaner screens.
- **Selling it to other operators is a real opportunity — later.** It needs
  multi-tenancy (every booking, driver, setting and API key separated per
  company), self-serve onboarding, subscription billing, support and GDPR
  data-processor terms. Realistic order: (1) 3 months running CET with no
  incidents; (2) product-strategist writes the SaaS spec and competitor/pricing
  review; (3) pilot free or cheap with 1–2 friendly partner operators — the same
  firms b2b-sales-hunter signs as cover partners; (4) then decide.

## Other ideas worth doing

- **Event calendar selling:** World Snooker at the Crucible (Apr–May, global
  players/media), Utilita Arena concerts, university graduations, Sheffield
  United/Wednesday hospitality, Christmas party season. Ads + outreach 4–6 weeks
  before each.
- **Hotel concierge partnerships:** Sheffield hotels and serviced apartments
  (airport runs for guests) — simple referral arrangement.
- **Microsoft (Bing) Ads:** import the Google campaigns once clean; cheaper
  clicks, many business travellers.
- **Corporate rate card + one-page "Why CET for business"** PDF to attach to
  every outreach email.
- **Return-trip prompt:** every one-way airport customer gets offered the return
  at booking (higher value per customer).
- **Pricing & profit check:** margin per job after driver pay; flag routes that
  lose money; compare against 3 local competitors each quarter.
- **Track the source of every booking** (Ads / organic / repeat / corporate /
  partner) so money follows what works.
