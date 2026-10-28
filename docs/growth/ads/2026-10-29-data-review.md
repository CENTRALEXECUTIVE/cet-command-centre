# Google Ads data review — 1 Mar to 28 Oct 2026

**Written:** 29 Oct 2026. **Data:** the exports in
`docs/growth/data/ads-2026-03-01_2026-10-28/`: campaign, campaign by month, ad group,
ad group by month, keyword (470 rows), search terms (2,465), ad, asset, landing page,
device and auction insights, plus the screenshots (Command Centre revenue review and the
Google Ads Goals page).
**Replaces:** the "no data" assumptions in `2026-10-28-cleanup-blueprint.md`. The
corrected plan is `2026-10-29-action-plan.md`.

> **Correction to the 28 Oct blueprint.** It assumed nine campaigns and told you to pause
> "General". The real account is **one live Search campaign, `RT-Search-CAMP JAN26`,
> with 10 ad groups**. **"General Airport Transfers" is the backbone of the account:**
> it has 169 of the 271 conversions at £45 each. **Do NOT pause General.** The two older
> campaigns (`RT-Search-CAMP`, `RT-Search-CAMP DEC25`) are already paused and spent £0
> in the period. `RT-Search-CAMP`'s ads are disapproved because they point to
> centraltravels.co.uk. Leave both paused. Don't delete them.

---

## 1. One-page summary

| | Mar–Oct 2026 |
|---|---|
| Spend | **£13,623** (£13,526 to 25 Oct in the campaign export) |
| Clicks / CTR / avg CPC | 8,235 / 12.3% / £1.65 |
| "Conversions" (Google's count) | **271**, so **£50.27 per conversion**, 3.29% conv. rate |
| Conversion value (Google) | £51,550, i.e. 3.8× spend. **Not trustworthy, see §3** |
| Impression share | 40.9%. airporttaxis-uk.co.uk has 46.6%, airportstaxitransfers.com 33.9% |
| Daily spend actually run | **£60/day, hit every month Apr–Sep** (£1,823/month = the £60 monthly cap). March £37/day. October £57/day. **Budget cut £60 → £30 on 26 Oct 14:15** (change history: the only change since 1 Aug). |
| Command Centre completed revenue, same months | £112,916 from 718 trips (799 bookings came in) |

**By month** (the trend matters more than the total):

| Month | Spend | Clicks | Avg CPC | CTR | Conv | Cost/conv | Conv. value | Completed revenue (CC) | Ads conv. value as % of revenue |
|---|---|---|---|---|---|---|---|---|---|
| Mar | £1,161 | 1,035 | £1.12 | 15.1% | 27 | £43 | £6,223 | £5,175 | **120%** |
| Apr | £1,746 | 1,073 | £1.63 | 13.8% | 22 | £79 | £3,505 | £11,272 | 31% |
| May | £1,824 | 1,124 | £1.62 | 12.8% | 39 | £47 | £7,229 | £12,098 | 60% |
| Jun | £1,823 | 1,208 | £1.51 | 12.6% | 45 | £41 | £7,805 | £18,884 | 41% |
| Jul | £1,824 | 1,122 | £1.63 | 12.0% | 55 | £33 | £9,784 | £18,309 | 53% |
| Aug | £1,823 | 1,074 | £1.70 | 12.4% | 28 | £65 | £6,121 | £17,577 | 35% |
| Sep | £1,824 | 872 | **£2.09** | **9.9%** | 39 | £47 | £7,846 | £17,428 | 45% |
| Oct (28 d) | £1,598 | 727 | **£2.20** | 10.3% | **15.9** | **£101** | £3,039 | £12,173 (£13,353 booked) | 25% |

**Per ad group** (with the brand keyword split out of General, because it behaves completely differently):

| Ad group | Spend | % spend | Clicks | CPC | Conv | % conv | Cost/conv | Conv. rate | Verdict |
|---|---|---|---|---|---|---|---|---|---|
| General Airport Transfers (non-brand) | £7,128 | 52% | 4,652 | £1.53 | 129.4 | 48% | **£55** | 2.78% | **Keep, it's the core.** Trim the expensive exact keywords; split towns out later |
| ↳ brand "central executive transfers" | £473 | 3% | 167 | **£2.83** | 39.3 | **15%** | £12 | 23.6% | Move to its own cheap Brand ad group |
| Manchester Airport Transfers | £2,631 | 19% | 1,231 | £2.14 | 32.5 | 12% | **£81** | 2.64% | Keep, but cut bids. **Worst big ad group in every month but May.** October: 1 conversion for £458 |
| Executive Chauffeur / Corporate | £1,633 | 12% | 898 | £1.82 | 40.8 | 15% | **£40** | 4.54% | Keep. Best non-brand ad group. Raise bids on the 5 cheap converters |
| Weddings & Events | £548 | 4% | 547 | £1.00 | 11.0 | 4% | £50 | 2.01% | Keep, lower bids (cheap clicks; seasonal) |
| East Midlands | £473 | 3% | 313 | £1.51 | 9.0 | 3% | £53 | 2.87% | Keep |
| Heathrow | £359 | 3% | 176 | £2.04 | 7.0 | 3% | £51 | 3.98% | Keep. £290+ jobs. October CPC jumped to £4.11, cut that |
| Leeds Bradford | £218 | 2% | 141 | £1.54 | 2.0 | 1% | £109 | 1.42% | Keep the 2 converting keywords at lower bids |
| Birmingham | £111 | 1% | 73 | £1.52 | 0 | 0 | – | 0% | **Pause** (2× the target spent, nothing) |
| Gatwick | £23 | 0% | 17 | £1.34 | 0 | 0 | – | 0% | Keep (cheap, £350 jobs; a Gatwick search converted via General) |
| Luton | £16 | 0% | 15 | £1.05 | 0 | 0 | – | 0% | Keep (cheap) |

Non-brand cost per conversion is **£56.72** (231.7 conv for £13,150). That is the honest
"cost to win" figure. The £49.91 headline is flattered by people searching your own name.

---

## 2. What really worked and what wasted money

### Worked
1. **General Airport Transfers' local airport terms.** `"airport transfers sheffield"`
   (phrase) gave 11.75 conv at £36. `[airport taxi rotherham]` gave 7 conv at £29.
   `"airport transfers doncaster"` 4 at £33. `[book an airport taxi]` 4 at £34.
   `"airport chauffeur sheffield"` 5.25 at £36. `"premium airport transfer"` 9.5 at £47.
   `"long distance airport taxi"` 5 at £45.
2. **Chauffeur phrase keywords in Executive/Corporate.** `"private chauffeur sheffield"`
   gave 9 conv at £12. `"chauffeur service sheffield"` 7 at £24. `"chauffeur services sheffield"`
   3.4 at £17. `"chauffeur service chesterfield"` 3.3 at £24. Caveat: July alone had 17.3
   conversions at £13 in this ad group, against 1–9 in every other month. That looks like a few
   existing corporate people calling repeatedly, not 17 new customers.
3. **Heathrow** at £51 per conversion on jobs worth about £290. Per pound, probably the best ad group.
4. **Ad copy:** the existing RSAs are rated Excellent. The best headlines by CTR are
   `{KeyWord:Executive Airport Transfers}` (14.5%), "Transfers South Yorkshire" (14.4%),
   "Fixed Prices" (13.9%), "Taxi to Airport? We Got You" (14.9%) and "Fixed Price £100" (14.9%).
   The best description is "Fixed prices to all major UK airports – book online now." (3,570 clicks).
   All of these are kept in `ads-v2.csv`.
5. **The search-term hygiene is already decent.** 2,465 visible terms, and almost all of them
   are real airport/chauffeur intent. **The clearly irrelevant terms (other towns, competitor
   names, shuttles, supercars, bare "airport taxi") cost only about £630 over 8 months**
   (about £80/month). Negatives are worth doing, but **they're not the big lever.** Don't
   expect them to fix cost per booking.

### Wasted (or bought badly)
1. **Manchester: £2,631 for 32.5 conversions (£81).** If it had matched General's rate (£45),
   the same conversions would have cost about £1,170 less. The core exact route terms convert
   badly. `[taxi sheffield to manchester airport]` £152 / 1 conv. `[taxi from sheffield to manchester airport]` £162 / 1.
   `[manchester airport transfers]` £236 / 0.5. `[taxi from manchester airport to sheffield]` £109 / 0.
   It's the most price-shopped route (competitors at £90–£100), CPCs are the highest (£2.14 →
   £2.63 in October), and the fare is only about £100.
2. **Paying £2.83 a click for your own name.** 167 brand clicks cost £473. A brand click
   should cost £0.20–£1.00. Those 39 "conversions" are mostly people who already know you
   (189 repeat customers made 550 of your 799 bookings) ringing or WhatsApping the number
   in the ad.
3. **Expensive exact generics in General:** `[airport transfers sheffield]` £779 for 10 (£78).
   `[airport taxi sheffield]` £738 for 12 (£62). `[book airport transfer]` £172 for 0.
   `[airport transfers barnsley]` £172 for 1. Chesterfield exact+phrase £322 for 2.
   The phrase twins of the first two convert at about half the cost. Keep both, but lower
   the exact bids.
4. **Birmingham** £111 / 0, **Leeds Bradford** £218 / 2.
5. **Bought far below "fixed price" specificity:** 6 of the 15 headlines in most ads are
   generic ("Punctual and Reliable Service" 6.3% CTR, "Trusted Executive Service" 7.2%,
   "24/7 Professional Drivers" 6.2%, "Reliable Pro Drivers" 4.9%). The LBA ad still says
   **"Central Travels"** (the old disapproved brand). The Weddings ad says "Central Transfers".
6. **Google AI auto-created sitelinks** sent clicks to homepage anchors (`/#prices` 1,443
   interactions, `/#why`, `/gallery`, `/#fleet`) and to a `/long-distance…` page that isn't in
   your page list. Automatically created assets are ON. Turn them off.
7. **Negatives that block good traffic.** These search terms show as "Excluded" (there's a negative on them):
   `sheffield airport transfers` (it **converted**: 1 conv / £71), `airport taxis doncaster`
   (it is ALSO an enabled keyword with 3 conv, so this is a conflict) and
   `taxi manchester airport to sheffield` (also a keyword, another conflict). Remove those 3 negatives.
   Others are excluded too: airport taxis sheffield, sheffield airport taxi, chauffeur sheffield,
   executive cars/taxi sheffield. They had no conversions on small spend. Leave them excluded.

### Search terms (only 54% of spend is visible)
- Visible: £7,389 and 142.6 conv. Hidden by Google (low-volume terms): **£6,234 and 128 conv**.
  The hidden half converts at the same rate (£49), so it isn't a junk bucket.
- Exact match did best: £3,788 for 86 conv (£44). Exact close variants £2,614 for 39 (£67).
  Phrase close variants £931 for 16 (£58).
- Bare generics with no town, all 0 conv: "airport taxi" £117, "airport taxis" £64,
  "airport transfer" £54, "airport transport" £16, "sheffield taxi" £24. These become
  **exact** negatives. Exact only, so longer airport searches still show.
- Out-of-area origins: Aylesbury (£39 for 2 clicks!), Derby, Huddersfield, Harrogate,
  Hinckley, Rugby, Leicester, York, Macclesfield, Wilmslow and others. That plus the Aylesbury
  click points to **location targeting possibly being "presence or interest"** or a wide
  radius. Check the setting (plan §5).
- Full list with evidence: `negatives-data.csv`.

### Devices
| Device | Clicks | CPC | Conv. rate | Cost/conv |
|---|---|---|---|---|
| Mobile | 6,263 | £1.42 | 2.78% | £51.26 |
| Computers | 1,798 | £2.47 | 5.28% | £46.73 |
| Tablets | 174 | £1.52 | 1.15% | £132.01 |

Computers convert at twice the rate, but cost 74% more per click, so cost per conversion is
nearly equal. **No mobile/desktop adjustment is justified.** Tablet: 174 clicks, 2 conv against
about 5.7 expected. **−50%** (small money, about £15/month).

### Landing pages
- The ads now point at the matching pages (General → `/airport-transfers-sheffield`,
  Manchester → `/sheffield-to-manchester-airport-transfers`, etc.). That's good.
- **But the homepage took 4,000 clicks / £5,880 (43% of spend).** The DEC25 copies of every ad
  point to the homepage, so the live ads very likely pointed there for much of the year before
  being changed. On top of that, homepage-anchor sitelinks ("Airport Prices", auto "Pricing Plans
  /#prices", "Why Choose Us /#why") add more.
- The landing-page export has **no conversion columns**, so we can't say whether the homepage
  or the route pages converted better. Verdict anyway: **every non-brand ad lands on its
  route/town page.** The homepage is for brand only. Pages carry the matching price and the
  booking widget. (Re-export with conversions to confirm.)
- `/booking` shows 0.30% CTR: that's the "Book Online" sitelink (76 clicks). It's fine.

### Assets
- **Call asset = +44 7405 172435 (the WhatsApp/office mobile), not 0114 399 8242.**
  "Phone call lead" is the conversion goal every campaign uses, so the ad's calls to that mobile
  are a big part of "conversions" (decision D7 in the plan).
- WhatsApp message asset: 14,468 impressions, 1,641 interactions. Its clicks don't count as
  conversions (the Contact goal isn't used).
- Callouts making claims not in BUSINESS_FACTS: "24/7 Availability" `[CONFIRM]`, "Trusted by
  Professionals" (unprovable), "Executive Mercedes Fleet" (BUSINESS_FACTS: never promise a model).
  The sitelink "Contact Us – Available 24 Hours, 7 Days" makes the same 24/7 claim.
- The asset report has no conversion data. Its CTRs are position-biased, so treat them as directional only.

### Competition
You show on 40.9% of eligible searches. airporttaxis-uk.co.uk overlaps 53% of your auctions
and sits above you 59% of the time. airportstaxitransfers.com and veezu.co.uk are the other
regulars. uber.com, bark.com and smccoachhire.com appear occasionally. On £30/day your share will
roughly halve. That's acceptable **if** the bids pull out of the expensive, low-converting auctions
(Manchester exact, bare generics) and stay in the cheap converting ones. See `competitors.md`
(28 Oct) for their ad angles. The new ads compete on specifics: fixed £100 to MAN (if confirmed),
licence OP037, flight tracking, live tracking link, VAT invoices.

---

## 3. Are the "conversions" real bookings? Almost certainly not, mostly.

**What the Goals screenshot shows:**

| Goal | Used by campaigns | Primary actions | Status | Counts in "Conversions"? |
|---|---|---|---|---|
| **Purchase** (account default) | 1 of 3 | 1 | Active | **Yes** |
| **Phone call lead** (account default) | **3 of 3** | 1 | Active | **Yes** |
| Submit lead form | 0 of 3 | 0 | **Misconfigured** | No |
| Contact | 0 of 3 | 2 | Active | No |
| Get directions | 0 of 3 | 1 | Active | No |
| Engagement | 0 of 3 | 1 | Active | No |
| Page view | 0 of 3 | 2 | Active | No |

So the 271 "conversions" are **phone calls from the ad (to the WhatsApp/office mobile)** plus
**one "Purchase" action of unknown origin**. It might be the CET widget snippet, an old ETO tag
or a GA4 import; we can't tell from this. Contact, page views, directions and engagement are
marked "primary" but don't count, because no campaign uses those goals.

**Evidence that "conversion" does not equal "booking":**
1. **March: Google's conversion value (£6,223) is higher than all completed revenue that month
   (£5,175).** That's impossible if each conversion were a real booking at its real value.
   Average value per conversion is £159–£230 a month, against an average fare of £144–£157.
2. **Brand:** 39 conversions from 167 clicks (23.6%). Those are existing customers ringing the
   number in the ad, not new business won by advertising.
3. **Repeat customers:** 189 repeat customers made 550 of the 799 bookings (69%). That leaves at
   most about 438 first-time bookings in the whole period, from every channel (SEO, Google Business
   Profile, referrals, corporate, trade cover). For 271 ad conversions to all be bookings, ads would
   need to have produced 62% of every first-time booking, while also paying for the brand searches of the 69% who rebook.
4. A call conversion counts a call that lasts about 60 s (the default). Enquiries, price checks,
   "where's my driver" and cover-driver calls all count.
5. **October:** Google conversions fell 59% (39 → 16) while Command Centre trips fell 26%
   (110 → 81, Oct not finished). A drop that much bigger than the business drop points to
   **tracking, not just demand**. October was the site rebuild, and on about 25–28 Oct `/booking`
   moved from the ETO iframe to the CET widget, which isn't wired to Google Ads yet (§5).

**What it means for every number in this report:** every "cost per conversion" above is really
**cost per call-or-purchase-event**. Treat it as a *relative* ranking between ad groups and keywords
(Manchester is still worse than General, and Executive still better), **not** as cost per booking.
A reasonable working assumption is that 40–70% of conversions were real bookings. That makes the
real cost per booking **£70–£125**, against an average fare of £144. At the 10% margin on a
cover-driver job (£14), an ad-won job only pays when a director drives it (decision D1).
**This is why fixing tracking is the #1 change.** Until web bookings are tracked by value and
reference, nobody, including Google's bidding, can tell a £290 Heathrow booking from a 61-second
"are you open?" call.

---

## 4. Did the ads drive the April–September revenue rise? Honest answer: only partly, and the data can't prove it.

What the data shows:
- **Spend was flat at £60/day from April to September** (£1,746–£1,824 a month). Completed revenue
  rose **£11.3k (Apr) → £18.9k (Jun) → about £17.5k (Jul–Sep)**, i.e. +55–67% **with no extra ad money.**
  Spend can't explain growth it didn't change.
- Ad conversions don't track revenue. April had the **fewest** conversions (22) as revenue doubled
  from March. August conversions halved (55 → 28) while revenue was flat.
- Ads "value" as a share of revenue swings from 25% to 60% (120% in March), with no trend.
- The plausible drivers of the step-up are seasonality (summer airport peak), 189 repeat customers
  rebooking, corporate accounts, trade/cover work, the SEO pages, and **March being a partial month
  of Command Centre data** (43 trips). That's also why the review page shows "+691% vs previous
  period". That figure is a data-start artefact, not growth.
- **What we can say:** ads were a steady contributor. At an assumed 40–70% real-booking rate that's
  about 11–38 bookings a month, or roughly £1.6k–£5.5k of fares a month for £1.8k of spend. That's
  break-even to modest profit on director-driven work, and a loss on cover-driver work.
- Caveat: revenue is by trip date and conversions by click date. Airport jobs are booked days to
  weeks ahead, so a month-to-month comparison is crude.

**The exports that would prove it (in priority order):**
1. **Command Centre bookings export** (Created date, Source, value, `marketing.gclid`/utm, status).
   The widget already stores the gclid on web bookings. Web bookings with a gclid = ad-won, fact.
   Also bookings by ETO "Source" per month.
2. **Google Ads: Campaign by month, segmented by Conversion action** (Campaigns → Segment →
   Conversions → Conversion action). This splits calls from Purchase, month by month.
3. **Call details report** (Reports → Predefined reports → Call details: start time, duration,
   caller area code). Match against the bookings log by time and number to estimate the
   call-to-booking rate.
4. **Change history Mar–Jul** (Aug–Oct received: only the 26 Oct budget cut). It would show
   whether the ads' final URLs moved from the homepage to route pages, and any earlier bid-strategy change.
5. A deliberate test later: once tracking is clean, compare 4 weeks of gclid-matched bookings at
   £30/day against 4 weeks at £45/day. That's the only clean proof of what extra spend buys.

---

## 5. Why October collapsed — diagnosis

October in numbers: CPC **£2.20** (Mar £1.12; Jun £1.51; Aug £1.70; Sep £2.09), CTR **10.3%**
(15.1% in March), conv. rate **2.19%** (Sep 4.49%), **£101 per conversion**. General had 8 conv
at £80 (it normally gets 20–33). Manchester had 1 conv for £458. Heathrow's CPC was £4.11.
Spend was still about £57/day.

**Ruled out: changes inside the account.** The change history for 1 Aug–28 Oct
(`change_history_aug-oct.csv`) contains **one change**: 26 Oct 14:15, budget £60 → £30, by
admin@. No bid-strategy, keyword, ad, setting, conversion-action or auto-applied changes. The
budget cut is too late to explain October, so the cause is outside the account. Two things,
which are separate problems:

**(A) Clicks got dearer (Sep–Oct): the market, probably.** CPC rose in every ad group at once
(Exec £1.47 → £2.58, Manchester £1.75 → £2.63, LBA £1.09 → £2.55, Heathrow £1.82 → £4.11). CTR
has slid steadily since March (15% → 10%). With nobody touching the account, the likely
explanations are competitors raising bids or new entrants (airporttaxis-uk.co.uk already sits
above you 59% of the time), the post-summer squeeze, and in October a lower **landing-page
experience** score while the site was being rebuilt (see B). One caveat: if the bid strategy is
a smart one (Maximise clicks/conversions), it changes CPCs by itself without leaving a change
history entry. That's why the bid-strategy screenshot matters. **Unproven. Check:** auction insights
**by month** (Aug, Sep, Oct: rising "overlap" or "position above", new domains), and Search lost IS
(rank) by month.

**(B) Recorded conversions fell much more than real business: the website and tracking, likely.**
The conversion rate halved (4.49% → 2.19%), but Command Centre trips fell only 26% and October
isn't finished. In October the live site was rebuilt: new homepage and SEO pages mid-to-late
October, a homepage **500 error for several hours one night**, readability problems, and around
**25–28 Oct the `/booking` page switched from the old ETO booking iframe to the new CET widget.**
Ads land mostly on the homepage (4,000 clicks) and `/airport-transfers-sheffield`. So:
- If the primary **"Purchase"** action was fired by the **old ETO booking confirmation** (likely: it
  is account-default, "1 of 3 campaigns", and the CET widget's `cetBooking` message is **not yet wired
  to any Ads tag** in GTM), then any page that no longer loads the ETO flow stops recording bookings.
  **Recorded conversions fall even if real bookings don't.** From about 25–28 Oct, *every* web booking
  goes through the CET widget, so Google Ads is now probably recording **no web bookings at all** —
  only calls.
- The rebuild and the 500 outage would also cut real conversions for those days, and dent landing-page
  quality, which raises CPC (feeding A).
- **What's likely:** a mix of a dearer market (A) and conversions lost to the rebuild and tracking (B).
  **What's unproven:** how big each part is, and whether "Purchase" really was the ETO tag.
  October was already weak before 25 Oct, so the widget swap alone can't explain the whole month.

**The checks that prove it (do them in this order):**
1. **Goals → Conversions → Summary → click the Purchase action.** Look at its **Source** ("Website",
   "Google Analytics (GA4)", "Import"), its **tag status**, "last conversion" date, and **"Tag setup /
   Webpages"** (which page it fires on). If the last conversion is around 25–28 Oct, or it fires on an
   ETO domain or confirmation page, it was the ETO tag.
2. **Ads conversions by day, by conversion action, 1–28 Oct** (Campaigns → Segment → Time → Day, then
   Segment → Conversions → Conversion action). Lay it next to **Command Centre web bookings by day**
   (Bookings → filter Source = web, by created date) **and ETO web bookings by day**. If Command Centre
   or ETO bookings carry on while Purchase drops to zero from a given day, that's the tracking break
   and it gives the date.
3. **Test that the new booking conversion fires** once GTM is built (`2026-10-29-conversion-tracking-fix.md`
   Part C). Use GTM Preview on `/booking` and confirm the listener catches the widget's message, with a
   real booking only (no test bookings on the live site). Until that passes, assume web bookings are invisible to Google Ads.

**Consequence for the plan:** the tracking fix moves from "important" to **urgent, before anything
else that relies on conversions.** No keyword or ad group should be judged on October conversions.
The bid decisions in `keyword-actions.csv` use the 8-month totals, deliberately, for this reason.

---

## 6. Is £30/day enough?

- £30 × 30.4 = **£912/month**, half of what ran Apr–Sep.
- At **October's efficiency** (CPC £2.20, CR 2.2%): about 415 clicks and **9 conversions a month at £100.** Bad.
- At the **plan's efficiency** (CPC about £1.45 after bid cuts, pauses and cheap brand; CR about 3.3%):
  about 630 clicks and **about 21 conversions a month at about £44**, of which about 4 are brand.
- At **June–July efficiency** (CPC about £1.55, CR about 4%): about 590 clicks and about 24 conversions.
- The account was budget-capped every month even at £60, so £30 will be fully spent. The question is only *on what*.

**Verdict:** £30/day is enough for a 4-week clean-up while tracking is fixed. Spending more before you
can see real bookings just buys more uncertainty. It isn't enough to cover every route at full share,
so the plan concentrates it on General (local airport terms), Executive/Corporate and Heathrow/EMA, and
squeezes Manchester. **Raise it in 20% steps (to £36, then £45) only when tracked web bookings show a real
cost per booking under the D1 target for 2 weeks running.** See plan §4.

---

## 7. What else is needed (send these with next week's exports)
1. Conversions **by conversion action**, by month (Segment → Conversions → Conversion action), and
   Goals → Conversions → Summary list (each action's name, source, status, count setting).
2. **Change history** 1 Mar–31 Jul (Aug–Oct received), and **conversions by day by action for October** next to Command Centre/ETO web bookings by day.
3. **Call details** report.
4. **Command Centre web bookings with gclid** (count per month) and bookings by Source.
5. Campaign **Settings** screenshots: Bidding, Networks, Locations (+ Location options), Automatically created assets, AI Max/broad match.
6. **Locations → "Where your users were"** (user locations) and **Hour of day / Day of week**.
7. Landing page report **with conversion columns**, by month.
8. **Negative keywords** (campaign + ad group + shared lists) export.
9. Auction insights **by month**.
