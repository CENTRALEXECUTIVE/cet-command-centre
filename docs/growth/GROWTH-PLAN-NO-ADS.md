# Growth plan with no paid ads (Nov 2026 to Apr 2027)

**Written:** 5 Nov 2026. **Decision it follows:** on 4 Nov 2026 the directors stopped Google
Ads completely (cost). No paid spend of any kind. Growth now comes from free channels:
search (SEO), Google Business Profile and reviews, organic social, B2B outreach, partner
cover work, account clients and repeat customers.

**Sources:** `BUSINESS_FACTS.md`, `ads/2026-10-29-data-review.md`,
`ads/2026-10-30-bookings-vs-ads.md`, `ads/2026-10-30-keyword-review.md`, `ads/keywords.csv`,
`ads/competitors.md`, `seo/`, `social/90-day-growth-plan.md`, `sales/PIPELINE.md`,
`sales/partner-offer.md`, `PLAYBOOK.md`, `docs/HANDOVER.md`, and booking counts from the
private ETO export (totals only, no customer details). Every figure that is not in those
files is marked **[ASSUMPTION]**. Every fact that still needs a director is marked
**[CONFIRM]**.

**Rules that apply to everything below:** nothing sends itself (a director sends every
email, WhatsApp, post and review reply). Website pages are written here as files and
uploaded to the live site by the directors by hand; nobody works in `public_html` from
this repo. Never name the existing account clients in marketing. No prices in social
posts. Marketing copy never says the directors drive the cars.

---

## Summary

**Reality check added 5 Nov 2026. Read section 3.0 first: it replaces the old EXPECTED case.**

The first draft was too optimistic. Our own booking history shows bookings jumped from about
12 to 18 journeys a month (Oct 2025 to Feb 2026) to 80 to 110 a month from April 2026, the
same time the ads started. Summer, the new SEO pages and a growing repeat base helped too,
but the ads were a bigger engine than "12 to 20 bookings a month" suggests, because the new
customers they brought became the repeat customers who now make up 69% of bookings.

Realistic planning case: about **20% fewer bookings than with ads through the winter**
(roughly 55 to 70 journeys a month instead of 70 to 86), getting back to the with-ads level
around **late spring or summer 2027**, sooner only if we land one or two accounts or partner
operators (each can add 5 to 15 jobs a month). SEO will not replace the ads this winter:
aggregators (Holiday Extras, Suntransfers, Transfeero and others) hold the generic
"Sheffield airport transfer" results, so the winnable free spots are the Google Maps listing
and the specific route and town pages. The biggest levers are accounts, partner cover work,
reviews and replying fast, in that order.

---

## First 7 days (Mon 9 to Sun 15 Nov)

- [ ] **Director:** open the Growth dashboard and write down the baseline: average weekly
      bookings (all channels) and new customers per week for the 8 weeks to 1 Nov. This is
      the number the "restart ads only if" rule uses.
- [ ] **Director:** write today's Google review count and rating, and the follower counts on
      each platform, into the dashboard's weekly log (Week 0).
- [ ] **Director:** write down the Google review counts of 5 local competitors (King's Travel
      Sheffield, Airport Taxis UK, Izec Prestige, ETC Chauffeur, Sentura). Search each name
      on Google Maps. This sets the review-lead target.
- [ ] **Director:** send the partner-operator emails already scheduled for Thu 12 and Fri 13
      Nov (batch D: TBR, First Travel Solutions, Portman, Little's, Parkers), and chase the
      Transferz, Kiwitaxi and Transfeero portal applications.
- [ ] **Director:** decide the trade rate model (Option 1 discount off the public matrix, or
      Option 2 net table) in `sales/partner-offer.md`. Partners will ask for it in reply.
- [ ] **Director:** send the referral email to the three existing account clients
      (`sales/referral-ask-account-clients.md`), once each, personally.
- [ ] **Office:** GBP: two posts this week (Tue and Fri), 3 new own photos, answer the Q&A
      seeds in `seo/google-business-profile.md` if not done.
- [ ] **Office:** start the Christmas and New Year win-back list from the Command Centre
      (past private customers, airport and party bookings). Send the first 20 manually.
- [ ] **Claude:** draft the first gap page (Worksop airport transfers) as a file for upload.
- [ ] **Claude:** draft the Christmas/NYE win-back message and the corporate "festive dates"
      email in house style for a director to send.

---

## 1. Does SEO replace our paid search keywords?

**Partly, and not yet.** Most of the paid keyword themes already have a matching page on
the live site. A few have no page at all. Pages only start to win clicks once Google ranks
them on page one, and for local service searches that typically takes 3 to 6 months from
indexing **[ASSUMPTION: common industry range; brand-new pages on a young site are often
slower]**. Every page in the sitemap (32 URLs) was submitted for indexing between about 31 Oct and 4 Nov 2026,
so realistic ranking gains start around February to April 2027.

### 1.1 Paid keyword themes mapped to existing pages

Ad groups and keywords come from `ads/keywords.csv` (the last upload) and the older ad
groups in `ads/2026-10-30-keyword-review.md`. Results are Google's "conversions" for
1 Mar to 28 Oct 2026 (mostly calls; treat as a ranking, not as bookings).

| Paid keyword theme (examples) | What it did in ads | Existing page(s) | Cover |
|---|---|---|---|
| Sheffield airport transfers / airport taxi sheffield / airport chauffeur sheffield / executive airport transfer sheffield | Core of the account. "General" group: 129 conversions, £55 each | `/airport-transfers-sheffield`, `/airport-transfer-company-sheffield` | Good |
| Sheffield to Manchester Airport (taxi, transfer, chauffeur) | £2,631, 32.5 conv. 192 real web bookings, half of all web bookings | `/sheffield-to-manchester-airport-transfers` | Good outbound. Weak on the reverse "Manchester Airport to Sheffield" wording |
| Town to Manchester (Barnsley, Rotherham, Doncaster, Chesterfield to MAN) | Small spend, some conversions | Town pages + MAN page | Partial: needs a MAN section on each town page |
| Sheffield to East Midlands Airport | £473, 9 conv, 31 web bookings | `/sheffield-to-east-midlands-airport-transfers` | Good |
| Sheffield to Heathrow, plus Barnsley/Chesterfield/Rotherham to Heathrow | £359, 7 conv, 46 web bookings worth £14,340 | `/sheffield-to-heathrow-airport-transfers` | Good from Sheffield; partial from towns |
| Leeds Bradford, Birmingham, Gatwick, Luton, Stansted, Liverpool | Small groups; Birmingham 14 real web bookings | One page each, plus `/sheffield-to-newcastle-airport-transfers` | Good |
| Rotherham / Barnsley / Doncaster / Chesterfield airport transfers | `[airport taxi rotherham]` 7 conv at £29; Doncaster 4 at £33 | `/rotherham-…`, `/barnsley-…`, `/doncaster-…`, `/chesterfield-airport-transfers` | Good |
| Executive chauffeur Sheffield / private chauffeur / chauffeur service sheffield | Best non-brand group: £40 per conv. `"private chauffeur sheffield"` 9 conv at £12 | `/executive-chauffeur-sheffield`, `/vip-chauffeur-sheffield` | Good |
| Corporate and business travel / business taxi account | Part of the Exec/Corporate group | `/corporate-chauffeur-sheffield`, `/corporate-travel-sheffield`, `/open-account` | Good |
| V-Class and minibus (7 seater, 8 seater, people carrier, group airport transfer) | Small, cheap clicks | `/mercedes-vclass-hire-sheffield`, `/minibus-hire-sheffield` | Good |
| Long distance airport taxi | 5 conv at £45 | `/long-distance-chauffeur-sheffield` | Good |
| Weddings, prom, luxury car hire, wedding chauffeur | £548, 11 conv at £50 | `/prom-wedding-chauffeur-sheffield` (one shared page) | Partial: weddings and Rolls-Royce share a page with prom |
| Events | Not a paid group | `/event-chauffeur-sheffield` | Page exists |
| Brand: "central executive transfers" | £473 for 167 clicks, mostly existing customers | Homepage | Organic result already ranks for our own name **[CONFIRM in Search Console]**. Little is lost here |

### 1.2 Gaps: paid themes with no page of their own

| Gap | Evidence | Fix | Priority |
|---|---|---|---|
| **Worksop airport transfers** | `[airport taxi worksop]` 1 conv at £22, `worksop airport transfers` 0.5 at £34, `[airport transfers worksop]` 0.5 at £57. 6 real web bookings from Worksop | New page `/worksop-airport-transfers` | 1 |
| **Chauffeur service in the towns** ("chauffeur service doncaster", "executive chauffeur doncaster", "chauffeur doncaster", "chauffeur service chesterfield", "chauffeur rotherham", "chauffeur barnsley") | Doncaster chauffeur terms: about 6 conv across 4 keywords at £30 to £41. "chauffeur service chesterfield" 3.3 conv at £24. Town pages today are airport-only | New pages `/chauffeur-service-doncaster` and `/chauffeur-service-chesterfield`; Rotherham and Barnsley later if the first two get impressions | 2 |
| **Rolls-Royce and wedding car hire** ("rolls royce hire sheffield", "wedding cars sheffield/rotherham/barnsley/doncaster/chesterfield", "luxury car hire sheffield") | `"luxury car hire sheffield"` 4 conv at £21; wedding terms converted cheaply. Rolls-Royce Ghost is on request only (BUSINESS_FACTS) | New page `/rolls-royce-wedding-car-sheffield`, linked from the prom/wedding page. Couples book Jan to Mar | 3 |
| **Manchester Airport to Sheffield** (reverse direction) | `[manchester airport to sheffield taxi]` 3 conv; competitors (Transfeero, Suntransfers, MMA) have reverse-route pages | **Do not build a second page** (it would compete with the existing one; see `seo/drafts/README.md`). Add a "Manchester Airport to Sheffield" section and FAQ to the existing MAN page, using the drafts | 2 |
| **Town to airport combinations** (Barnsley/Chesterfield/Rotherham/Doncaster to MAN, EMA, Heathrow) | Several small converting keywords | Add a short section per airport to each town page, linking to the route page. No new pages | 4 |
| **South Yorkshire hub** ("airport transfers south yorkshire", "airport transfer yorkshire", "executive chauffeur south yorkshire") | `"airport transfer yorkshire"` 1.3 conv at £42 | Optional page `/airport-transfers-south-yorkshire` linking to every town and airport page. Only after the gaps above | 5 |
| **Dronfield / North Derbyshire** | No paid keyword, but 7 real web bookings from Dronfield | Optional `/dronfield-airport-transfers` | 6 |
| Generic "airport taxi service", "airport transfers near me", "book an airport taxi" | Converted in ads | No page fixes these. They are won by the Google Maps listing (GBP), reviews and the main Sheffield page | GBP |

Christmas party and NYE transport: a new page uploaded now will not rank before Christmas.
Add a seasonal section to `/event-chauffeur-sheffield` instead, and use that page as the link
in GBP posts, social and outreach. Keep the section live after New Year so it has a year of
age before next December.

### 1.3 What we lose in the short term

- **Top-of-page ad slots on high-intent searches.** From March to October the ads took 8,235
  clicks (about 1,030 a month at £60/day) and showed on about 41% of eligible searches. On
  "airport taxi sheffield", "sheffield to manchester airport taxi" and similar searches we
  are now absent from the ad block. airporttaxis-uk.co.uk and airportstaxitransfers.com keep
  those slots.
- **The bookings those clicks produced.** Non-brand ads recorded 231.7 conversions in 8
  months, about 29 a month. The data review's working assumption is that 40% to 70% were real
  bookings, so about **12 to 20 bookings a month** came from paid search at the £60/day
  budget. That is the most we lose.
- **What we keep.** Brand searches (people typing our name) were mostly existing customers;
  they still find us. Repeat customers made 550 of 799 bookings (69%) and are unaffected.

### 1.4 How Google Business Profile partly covers it

For local "near me" and "airport taxi sheffield" type searches Google usually shows a map
with three businesses above the normal results. That map pack is free. It is ranked on
relevance (category, services, description), distance and prominence (review count, rating,
recent activity). We control two of those three. A complete profile, two posts a week, new
photos weekly and a steady flow of reviews is the fastest free way back onto the first screen
for the searches the ads used to win. It will not cover the route searches ("sheffield to
heathrow taxi") as well; those come back through the SEO pages over months.

**[ASSUMPTION]** 25% to 35% of people who used to click our ad will now find us anyway through
the map pack, an organic result or a recommendation. This is used in section 3.

---

## 2. When can we expect bookings?

| Channel | First results | Meaningful volume | What drives it |
|---|---|---|---|
| Repeat customers and the 3 account clients | Now | Now (about 69% of bookings) | Fast replies, reliability, win-back messages at Christmas, half-term and Easter |
| Partner operators (cover work) | 2 to 4 weeks (first test job) | 2 to 3 months | Batch D/G emails, portal applications, trade rates decided |
| Outreach to companies (accounts) | 3 to 8 weeks (first reply to first job) | 3 to 6 months (an account books a few times a month) | 10 to 15 emails a day, follow-ups on the day they fall due |
| Google Business Profile and reviews | 2 to 6 weeks | 2 to 3 months | Review on every job, weekly posts and photos |
| SEO pages | Impressions in 2 to 6 weeks | 3 to 6 months for local terms, longer for new pages | Indexing, internal links, the gap pages, citations |
| Social media | 4 to 8 weeks (first enquiries) | 2 to 3 months (90-day plan target: 6 bookings in month 3) | 4 to 5 short videos a week, local collaborations |

---

## 3. Projections, Nov 2026 to Apr 2027

### 3.00 Update 6 Nov 2026 from the real Command Centre export (USE THIS FIRST)

Source: "Export for review", bookings that CAME IN 1 Oct to 6 Nov 2026 (63 trips, return
legs not double-counted, 4 cancelled legs excluded). Totals only; no customer data here.

| Week booked (Mon) | 28 Sep* | 5 Oct | 12 Oct | 19 Oct | 26 Oct | 2 Nov (Mon to Fri) |
|---|---|---|---|---|---|---|
| Trips booked | 10 | 20 | 12 | 8 | 11 | 2 |
| New customers | 7 | 11 | 7 | 3 | 7 | 1 |
| Returning | 3 | 9 | 5 | 5 | 4 | 1 |
| Value booked (ex-VAT, all legs) | £1,840 | £3,319 | £2,135 | £1,132 | £2,160 | £360 |

\* 1 to 4 Oct only.

- With ads, October ran at about **12 trips booked a week (about 50 a month), worth about
  £9k to £10k a month**. Average about £174 per trip including return legs.
- **57% of October's trips were NEW customers** (36 of 63). That is the part the ads fed.
  Returning customers ran steadily at about 5 a week.
- Median booking is made **4.4 days before pickup**, so stopping ads shows within 1 to 2
  weeks. The first week after the stop (2 to 6 Nov) brought **2 trips**. One week is not a
  trend, but it is a warning.
- Mix: Manchester 26 of 63 trips; Executive saloon 51 of 63; V-Class 7; minibus 4.
  Most bookings come in Monday to Thursday, midday to evening.
- A few loyal customers matter a lot (one has 22 bookings, two have 11, one has 9). Look
  after them personally.

**Rebuilt projection (trips BOOKED per month, no ads):**

| | Nov | Dec | Jan | Feb | Mar | Apr |
|---|---|---|---|---|---|---|
| Returning customers | 20 | 22 | 16 | 18 | 20 | 24 |
| New (website, Google Maps, word of mouth) | 8 | 9 | 7 | 9 | 11 | 13 |
| Outreach accounts + partner cover | 1 | 2 | 3 | 4 | 5 | 6 |
| **Total (realistic)** | **29** | **33** | **26** | **31** | **36** | **43** |
| Value at ~£174 | ~£5.0k | ~£5.7k | ~£4.5k | ~£5.4k | ~£6.3k | ~£7.5k |
| October with ads, for comparison | 50 | | | | | |

Low case (week of 9 Nov also under 5 trips): about 20 to 25 trips a month this winter.

**What this means:** without ads, booked value looks like falling from about £9k to £10k a
month to about £5k to £6k, roughly £3k to £4.5k a month less, against £1.8k a month of ad
spend saved. The free channels need months to close that gap.

**Option for the directors (not a recommendation to spend):** a lean restart of about £10 a
day (about £300 a month) on what the data says worked, which is the brand name, Sheffield to
Manchester Airport exact terms and Heathrow. That would cost about a sixth of before and aim
to keep most of the new-customer flow. Decide after the week of 9 Nov: if it brings 5 trips
or fewer again, the drop is real.


**Why the first projection was too rosy**
1. **Our own history.** Journeys a month (return trips counted once, from the ETO export):
   Oct 2025 16, Nov 12, Dec 12, Jan 2026 16, Feb 18, then ads started: Mar 42, Apr 79,
   May 76, Jun 112, Jul 110, Aug 101, Sep 105, Oct 78. Not all of that jump was the ads
   (summer peak, new SEO pages, ETO used for everything), but the timing points to the ads
   as the main source of NEW customers.
2. **New customers feed the repeat base.** About 27 to 29 new web customers a month came in
   while ads ran. If that falls to roughly 10 to 15, the repeat base stops growing and slowly
   shrinks, so "repeat stays at 69% of 90" was too generous.
3. **SEO is slow and the generic results are crowded.** Typical local SEO: map listing
   movement in 1 to 3 months, service pages 3 to 6 months, competitive terms 6 to 12 months.
   The generic airport-transfer results are dominated by national aggregators.
4. **B2B is slow.** Account decisions usually take 1 to 3 months, and a 5% to 8% reply rate
   is optimistic for cold email; plan on 2% to 5%.

**REALISTIC (use this for cash planning)**

| Journeys per month | Nov | Dec | Jan | Feb | Mar | Apr |
|---|---|---|---|---|---|---|
| Repeat and existing accounts | 55 | 57 | 42 | 47 | 50 | 58 |
| Website organic + Google Maps (new) | 10 | 10 | 8 | 9 | 11 | 13 |
| Outreach accounts (new) | 0 | 0 | 1 | 2 | 3 | 4 |
| Partner cover | 0 | 1 | 2 | 2 | 3 | 4 |
| Social (new) | 0 | 1 | 1 | 2 | 2 | 3 |
| **Total** | **65** | **69** | **54** | **62** | **69** | **82** |
| With-ads line (sec. 3.2) | 81 | 86 | 68 | 76 | 86 | 103 |
| Difference | -20% | -20% | -21% | -18% | -20% | -20% |
| Value at about £155 | ~£10.1k | ~£10.7k | ~£8.4k | ~£9.6k | ~£10.7k | ~£12.7k |

- Roughly **15 to 20 fewer journeys a month** than with ads, about £2.3k to £3k of fares,
  against about £1.8k a month of ad spend saved. Close to break-even in cash, but the
  business stops growing unless the free channels are worked hard.
- **What changes the picture fastest:** one account or partner operator booking weekly adds
  5 to 15 jobs a month. Two of those by February closes most of the gap.
- **Watch it weekly** on the Growth dashboard. If total journeys run 25% or more below the
  with-ads line for 4 weeks in a row (section 5.3), consider a small, tightly capped ads
  test on the proven routes only (Manchester, Heathrow, brand). Directors' decision.
- Tables 3.3 to 3.5 below are kept for reference only. 3.3 and 3.5 are best cases.


### 3.1 The numbers this is built on

| Figure | Value | Source |
|---|---|---|
| Bookings Mar to Oct 2026 | 799 (718 completed trips) | Data review |
| Bookings per month, autumn run-rate with ads | Sep 110 trips; Oct 81 trips in 28 days (about 87 for the month) | Data review / Command Centre |
| Repeat share | 550 of 799 bookings (69%) from 189 repeat customers | Data review |
| New-customer web bookings | About 27 to 29 a month (219 in 8 months, 57% of web bookings) | Bookings vs ads |
| Paid-search bookings | About 12 to 20 a month at £60/day (231.7 non-brand conv in 8 months × 40% to 70%) | Data review |
| Average fare | £144 to £157 | Data review |
| Social plan targets | 1 / 3 / 6 bookings in months 1 / 2 / 3 | 90-day social plan |

### 3.2 Assumptions (all of them)

1. **Run-rate with ads = 90 bookings a month** in autumn **[ASSUMPTION: between October's
   ~87 and September's 110, weighted to the recent month]**.
2. **Seasonal index** against that run-rate **[ASSUMPTION]**: Nov 0.90, Dec 0.95 (Christmas
   parties and NYE, fewer airport runs), Jan 0.75 (quiet), Feb 0.85 (half-term airport bump),
   Mar 0.95, Apr 1.15 (Easter airport peak; Easter Sunday is 28 Mar 2027, snooker at the
   Crucible from mid-April **[CONFIRM dates]**). Our own data from last winter is too thin to
   use (the business was still ramping up: 12 to 21 journeys a month Nov 2025 to Feb 2026).
3. **"With ads" line** = 90 × index: Nov 81, Dec 86, Jan 68, Feb 76, Mar 86, Apr 103. It
   assumes £60/day ads, so it is the line to beat.
4. **Repeat** = 69% of 90 = 62 × index (Nov 56, Dec 59, Jan 47, Feb 53, Mar 59, Apr 71).
   Win-back messages are counted here. LOW takes about 7% off; HIGH adds about 5%.
5. **Website organic + GBP today** ≈ 15 new bookings a month (about 29 new web customers minus
   about 15 from ads, rounded). Split about 9 website / 6 GBP and calls **[ASSUMPTION]**.
   Plus 25% to 35% recapture of former ad bookers (section 1.4).
6. **New account** = 2 to 4 bookings a month once it starts booking, first account
   December **[ASSUMPTION]**. **Partner** = 2 to 5 cover jobs a month once live **[ASSUMPTION]**.
7. **Revenue** uses £150 a booking **[ASSUMPTION: middle of £144 to £157]**. Cover jobs are
   at trade rates, so revenue there is lower **[CONFIRM trade rates]**.

**The dip, worked out:** about 15 ad bookings a month (middle of 12 to 20), less about 30%
recaptured, leaves about 10 a month lost, roughly 11% of November's with-ads line. The
EXPECTED table shows -10% in November because partner and social work have barely started.

### 3.3 First-draft EXPECTED (optimistic; superseded by 3.0)

| Bookings per month | Nov | Dec | Jan | Feb | Mar | Apr |
|---|---|---|---|---|---|---|
| Website organic (new) | 9 | 9 | 8 | 10 | 12 | 15 |
| GBP / calls / map (new) | 6 | 7 | 6 | 7 | 9 | 11 |
| Outreach accounts (new) | 0 | 1 | 2 | 4 | 6 | 8 |
| Partner cover | 1 | 2 | 3 | 4 | 6 | 7 |
| Social (new) | 1 | 3 | 4 | 5 | 6 | 7 |
| Repeat and existing accounts | 56 | 59 | 47 | 53 | 59 | 71 |
| **Total** | **73** | **81** | **70** | **83** | **98** | **119** |
| With-ads line | 81 | 86 | 68 | 76 | 86 | 103 |
| Difference | -10% | -6% | +3% | +9% | +14% | +16% |
| Revenue at £150 | £10,950 | £12,150 | £10,500 | £12,450 | £14,700 | £17,850 |

Expected: the dip is about 5 to 8 bookings a month in Nov and Dec, and it is **recovered in
late January**. From February the free channels add more than the ads did.

### 3.4 First-draft LOW (close to the realistic case in 3.0)

| Bookings per month | Nov | Dec | Jan | Feb | Mar | Apr |
|---|---|---|---|---|---|---|
| Website organic | 7 | 7 | 6 | 8 | 9 | 11 |
| GBP / calls | 5 | 5 | 4 | 6 | 7 | 8 |
| Outreach accounts | 0 | 0 | 1 | 2 | 3 | 5 |
| Partner cover | 0 | 1 | 1 | 2 | 3 | 4 |
| Social | 0 | 1 | 2 | 3 | 3 | 4 |
| Repeat | 52 | 55 | 43 | 49 | 55 | 66 |
| **Total** | **64** | **69** | **57** | **70** | **80** | **98** |
| Difference vs with-ads | -21% | -20% | -16% | -8% | -7% | -5% |

Low: a 20% dip through the winter, still about 5% short in April. This is the case the
"restart ads only if" rule (section 5.3) is there for.

### 3.5 HIGH (daily outreach, 2+ partners live by January, reviews and SEO move fast)

| Bookings per month | Nov | Dec | Jan | Feb | Mar | Apr |
|---|---|---|---|---|---|---|
| Website organic | 11 | 12 | 11 | 14 | 17 | 21 |
| GBP / calls | 8 | 9 | 8 | 10 | 12 | 14 |
| Outreach accounts | 1 | 2 | 4 | 6 | 9 | 12 |
| Partner cover | 2 | 3 | 5 | 7 | 9 | 11 |
| Social | 1 | 3 | 5 | 7 | 8 | 10 |
| Repeat | 58 | 62 | 50 | 56 | 62 | 75 |
| **Total** | **81** | **91** | **83** | **100** | **117** | **143** |
| Difference vs with-ads | 0% | +6% | +22% | +32% | +36% | +39% |

High: no visible dip. It depends on outreach going out every working day from 9 Nov.

### 3.6 Seasonal notes for planning

- **Christmas parties** (Fridays and Saturdays, late Nov to mid Dec): V-Class and minibus
  groups. Corporate and venue outreach for this goes out in November, not December.
- **NYE and 24 to 26 Dec**: published holiday surcharges apply (in the Command Centre). Do not
  put prices in social posts; the booking page shows them.
- **January**: quiet for private work. Use it for outreach, the wedding page, reviews and
  partners. Business travel restarts in the second week.
- **February half-term** **[CONFIRM: Sheffield school dates, likely week of 15 Feb 2027]**:
  airport runs. Win-back message to past airport customers in late January.
- **Easter** (Sunday 28 Mar 2027): the biggest airport peak in this window. Win-back in early
  March. **Snooker** at the Crucible from mid-April **[CONFIRM]**: outreach to hotels and
  World Snooker Tour 4 to 6 weeks before.

---

## 4. The 12-week action plan (Mon 9 Nov 2026 to Sun 31 Jan 2027)

Owners: **Director** (Abdi or Maj), **Office** (whoever is on the phone and bookings that day;
often a director), **Claude** (drafts, pages, packs, analysis; never sends or uploads).
Week numbers here start on 9 Nov; the social plan's week numbers start on 2 Nov.

### 4.1 Standing weekly routine (every week)

| Task | Owner | Time per week |
|---|---|---|
| Outreach: 10 to 15 first emails or follow-ups a day, Tue to Thu 08:00 to 10:00 for new emails, follow-ups on the day due (`sales/kit/maj-daily-routine.md`). Partner operators first. Never more than 20 a day | Director | 4 to 5 h |
| Reply to every enquiry within 5 minutes, 07:00 to 23:00 (PLAYBOOK target) | Office | Ongoing |
| Ask every completed customer for a Google review (standing practice) and reply to every review within 48 h | Office / Director | 30 min |
| GBP: 2 posts (Tue, Fri), 2 to 3 new own photos, check Insights on Monday | Office | 45 min |
| Social: 30-min filming session, 4 to 5 short videos posted, 15 min a day replying and commenting | Director | 2.5 h |
| LinkedIn: one post per director, 10 min a day commenting (social plan 5.3) | Director | 1 h |
| Win-back: manual messages to past customers (soft opt-in, opt-out line every time) | Office | 1 h |
| Growth dashboard check + Monday brief (`/monday-brief`) | Director + Claude | 20 min |
| Weekly social pack (`/social-week`), outreach drafts, SEO page drafts | Claude | (no director time) |
| Search Console weekly check (`seo/search-console-checklist.md` section 5) | Office | 10 min |

Director time in total: about 8 to 10 hours a week, mostly outreach.

### 4.2 Week by week

**Week 1 (9 to 15 Nov): baselines and partners first**
- Director: the "First 7 days" list above. Partner emails Thu 12 and Fri 13 Nov (batch C/D).
  Trade-rate model decided.
- Director: referral email to the 3 account clients.
- Office: GBP posts (corporate, fleet). Win-back list started; first 20 Christmas/NYE messages.
- Claude: `/worksop-airport-transfers` page drafted. Christmas/NYE section drafted for
  `/event-chauffeur-sheffield`. Win-back and festive corporate emails drafted.

**Week 2 (16 to 22 Nov): Christmas party push**
- Director: upload the Worksop page and the event-page Christmas section (with a backup, by
  hand, as before). Request indexing for both in Search Console.
- Director: 10 to 15 emails a day. Batch B hotels and venues (Mercure St Paul's, Kenwood Hall,
  Leopold, Whitley Hall, Tankersley Manor, Ringwood Hall, Cutlers' Hall): offer group
  transport home for their Christmas parties.
- Office: free citations, part 1: Bing Places (can import from GBP), Apple Business Connect,
  Yell, FreeIndex. Same name, phone (0114 399 8242) and website on every one.
- Claude: `/chauffeur-service-doncaster` drafted. Internal links list: every town page links to
  the MAN, EMA and Heathrow route pages and back.

**Week 3 (23 to 29 Nov): accounts and internal links**
- Director: send the account offer to any prospect who replied (why-cet-for-business-v2 +
  rate card once confirmed). Decide "no contract, no minimum spend" for accounts.
- Director: upload `/chauffeur-service-doncaster` and the internal link changes.
- Office: citations part 2: Thomson Local, Cylex, Hotfrog, Scoot, Yelp UK. Check the Facebook
  and LinkedIn pages carry the same details.
- Office: win-back: past airport customers who flew around Christmas or New Year last time.
- Claude: Manchester page "Manchester Airport to Sheffield" section and FAQ drafted (from
  `seo/drafts/manchester-content-ideas.blade.php.txt`).

**Week 4 (30 Nov to 6 Dec): checkpoint 1**
- Director: week-4 review against section 5.2. Day-30 social review (30 Nov).
- Director: upload the Manchester section. First partner test job targeted this week.
- Office: GBP post: "Book your Christmas party transport early". Christmas trading hours on GBP.
- Claude: `/chauffeur-service-chesterfield` drafted. Monday brief compares actual vs the
  three scenarios.

**Week 5 (7 to 13 Dec): peak party fortnight**
- Office: party nights first. Every group gets a review ask the next morning.
- Director: outreach continues at 10 a day (Tue to Thu); hotels' January corporate guests.
- Director: upload the Chesterfield chauffeur page.
- Claude: town-to-airport sections for Barnsley, Rotherham, Doncaster and Chesterfield pages.

**Week 6 (14 to 20 Dec): last outreach before Christmas**
- Director: last outreach send day Thu 17 Dec. Follow-ups wait until 5 Jan.
- Office: NYE bookings confirmed; driver cover arranged with partners for any overflow.
- Claude: January outreach batch prepared (new emails + follow-ups), dated for 5 to 7 Jan.

**Week 7 (21 to 27 Dec): minimum viable week**
- Office: jobs, replies, review asks. One GBP post (holiday hours). No outreach.
- Director: thank-you post on 24 Dec (social plan).

**Week 8 (28 Dec to 3 Jan): checkpoint 2**
- Director: week-8 review against section 5.2, day-60 social review (28 Dec).
- Office: NYE and New Year's Day jobs; review asks. Win-back to past February half-term
  airport customers drafted for sending on 18 Jan.
- Claude: `/rolls-royce-wedding-car-sheffield` drafted (Rolls-Royce Ghost on request only;
  no prices; no model promise beyond what BUSINESS_FACTS says).

**Week 9 (4 to 10 Jan): back to outreach**
- Director: outreach restarts Tue 5 Jan at 15 a day: follow-ups first, then new emails.
  Batch G international partners and the rest of batch D.
- Director: upload the Rolls-Royce wedding page. Contact 5 wedding venues and 5 wedding
  suppliers (planners, photographers) with a card swap and mutual tag.
- Office: GBP post: January business travel; corporate accounts.

**Week 10 (11 to 17 Jan): Search Console read**
- Office: Search Console: which pages have impressions, which queries sit at position 5 to 15.
- Claude: rewrite titles and descriptions for any page with high impressions and under 2% CTR.
  Optional `/airport-transfers-south-yorkshire` hub drafted if the town pages have impressions.
- Director: universities and hospitals: University of Sheffield (international office and
  events), Sheffield Hallam, Spire Claremont, Thornbury (enhanced DBS line).

**Week 11 (18 to 24 Jan): half-term and Easter win-back**
- Office: send the February half-term win-back to past airport customers (manual, opt-out line).
- Director: snooker (World Snooker Tour, hotels near the Crucible) and DocFest (June)
  introductions, 4 to 6 weeks ahead of their booking windows.
- Claude: Easter win-back message and March GBP post plan drafted.

**Week 12 (25 to 31 Jan): checkpoint 3**
- Director: week-12 review against section 5.2. Day-90 social review. Decide whether the
  restart rule (5.3) has been triggered.
- Claude: next 90-day plan from the real numbers (Feb to Apr), including whether to build the
  Rotherham and Barnsley chauffeur pages and Dronfield.

### 4.3 Taking over the local trade: what to do differently from competitors

1. **Be the go-to cover operator for other chauffeur firms.** Most firms outside South
   Yorkshire have no car here. Our offer (`sales/partner-offer.md`) already gives them an
   unbranded driver link, live tracking, number masking, flight tracking and one cover invoice.
   Missing pieces: a published trade rate **[CONFIRM]**, a written reply-time promise
   **[CONFIRM: e.g. accepted within 30 minutes, 07:00 to 23:00]** and a one-page partner
   agreement. Target 5 partners sending work by the end of January.
2. **Trade rates both ways.** Pass our London, Manchester and Birmingham jobs to partners at
   their trade rates so they send work back. Log every job passed in the pipeline.
3. **Corporate account perks that cost nothing**: 30-day terms or card at booking, PO /
   cost-centre / matter reference on each invoice line, one monthly VAT invoice, live tracking
   and driver details before pickup, a named director as the contact. Possible extras for the
   directors to decide **[CONFIRM]**: no contract and no minimum spend; priority booking on
   festive dates for account clients.
4. **Speed of reply.** Whoever answers first usually wins. Target: every enquiry answered
   within 5 minutes, 07:00 to 23:00, quote within 15 minutes. Log reply time where the
   Command Centre shows it.
5. **Review lead.** Count competitors' Google reviews in week 1. Target: pass the nearest local
   operator's count by the end of March, and keep adding at least 2 a week after that. No
   incentives for reviews; ask every completed job.
6. **Referrals.** Account clients: the referral email (once, personally). Private customers: a
   "Recommend us to a friend" card in each car with the booking link **[CONFIRM: no incentive
   until the directors decide]**. Hotels and venues: a concierge arrangement, any commission
   **[CONFIRM]** and only where the venue's own policy allows it.
7. **Partnerships by season.** Hotels (guest airport runs), Christmas party venues (Nov),
   wedding venues and suppliers (Jan to Mar), universities (graduations, international arrivals
   in Sept and Jan), hospitals (patient transport; enhanced DBS), football and arena event
   hospitality (Barnsley FC, Chesterfield FC, Doncaster Rovers; Sheffield United and Wednesday
   stay excluded while their ownership is in dispute), DocFest (June), snooker (Apr to May).
   Utilita Arena itself stays excluded (`PIPELINE.md`).

---

## 5. KPIs, checkpoints and the restart rule

### 5.1 What to read on the Growth dashboard (Marketing & reports → Growth dashboard)

| Dashboard line | What it means here |
|---|---|
| Bookings by channel: Social media / Website / Business accounts / Phone-WhatsApp / Other | Counted by the week the booking came in. Return legs not counted twice |
| Google Ads | Should fall to zero within a few weeks (late bookings from old clicks may still show) |
| New vs returning customers | New customers per week is the number ads used to feed. Watch it most |
| Outreach: emailed, replies, accounts opened | From the Outreach prospects and their status |
| Review requests sent | From the review-request messages sent |
| Weekly log: followers, Google reviews, rating, posts | Typed in by hand every Monday |

Two things to know: GBP post buttons carry `utm_source=gbp`, which the dashboard counts under
**Social media**. Calls from the GBP profile land under **Phone / WhatsApp**. Read GBP Insights
on Monday for the true GBP split.

### 5.2 Targets at week 4, 8 and 12

Week 4 = w/c 30 Nov, week 8 = w/c 28 Dec, week 12 = w/c 25 Jan. Booking figures are EXPECTED
per week (monthly figure ÷ 4.3); the LOW figure is the floor before the restart rule applies.

| KPI | Week 4 | Week 8 | Week 12 |
|---|---|---|---|
| Total bookings per week (expected / low floor) | 17 / 15 | 19 / 16 | 16 / 13 |
| New customers per week (expected) | 4 | 5 | 5 |
| Website channel per week | 2 | 2 | 2 |
| Business accounts channel per week (new accounts only) | 0 | 0 to 1 | 1 |
| Outreach first emails sent (cumulative from 9 Nov) | 100 | 170 | 240 |
| Outreach replies (cumulative) **[ASSUMPTION: 5% to 8% reply rate]** | 5 | 10 | 15 |
| Accounts opened + partners live (cumulative) | 1 | 3 | 5 |
| Review requests sent | Every completed private job (100%) | 100% | 100% |
| New Google reviews (cumulative from 2 Nov, social plan) | 8 | 18 | 30 |
| Instagram / Facebook / LinkedIn / TikTok followers (social plan, 30 Nov / 28 Dec / 31 Jan) | 60 / 60 / 40 / 30 | 150 / 130 / 100 / 100 | 300 / 250 / 200 / 250 |
| Gap pages live | 2 (Worksop, Doncaster chauffeur) | 4 (+ Chesterfield chauffeur, MAN reverse section) | 5 (+ Rolls-Royce wedding) |
| Citations listed with matching details | 4 | 9 | 9 |

Outreach note: only 83 of the 257 prospects have a published email. The rest need a published
address found first, a portal application or a phone call. If the list runs out before week 12,
Claude researches the next batch (published addresses only, never guessed).

### 5.3 "Restart ads only if" (an option for the directors, not a recommendation)

Ads stay off. If the directors want a safety net, this is a rule they can agree now so the
decision is not made in a panic later:

- **Trigger:** total weekly bookings are **25% or more below the baseline** written down in the
  first 7 days (adjusted for season using the index in 3.2), **for 4 weeks in a row**, AND new
  customers are under 3 a week for those same 4 weeks. Both must be true.
- **What a restart would look like, if chosen:** a 4-week test at **£10 to £15 a day**, only on
  the groups that were cheapest per conversion (Executive/chauffeur phrase keywords, Sheffield
  airport phrase keywords, Heathrow), with brand excluded, Manual CPC, and the "CET web booking"
  conversion confirmed recording first. Stop at the end of 4 weeks unless the Command Centre shows
  ad-tagged bookings (gclid) costing under about £63 each, the old ads-per-new-customer figure.
- **Do not restart** for one bad week, for January (it is always quiet), or before week 6.

---

## 6. Open decisions for the directors

1. Trade rate model and rates for partners (`sales/partner-offer.md`). **Needed in week 1.**
2. Account terms: no contract and no minimum spend? Priority festive booking for accounts?
3. Any thank-you for referrals (account clients, hotels), checked against their gift policies.
4. Written reply-time promise for partners.
5. Dates to confirm before use: Sheffield half-term, World Snooker Championship, DocFest.
6. Whether to agree the restart rule in 5.3 now, or leave ads off with no trigger.
