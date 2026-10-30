# Bidding strategy decision — 30 Oct 2026

**Campaign:** `RT-Search-CAMP JAN26` (Search only, £30/day, 10 ad groups).
**Inputs:** the directors' settings screenshots (30 Oct), `2026-10-29-data-review.md`,
`2026-10-29-action-plan.md`, `2026-10-29-conversion-tracking-fix.md`, `WALKTHROUGH-tablet.md`, and the
raw exports in `docs/growth/data/ads-2026-03-01_2026-10-28/`.
**Supersedes:** the default-bid table in `WALKTHROUGH-tablet.md` Step 4 and action plan §1. Four bids change (see §5).

---

## Verdict (one paragraph)

**Switch `RT-Search-CAMP JAN26` to Manual CPC today.** Enhanced CPC no longer exists for Search. Use the
ad-group default bids in §5 and the keyword bids in `keywords-upload-step1-bids-pauses.csv` (3 rows
corrected). **Don't** stay on Maximise conversions with a data exclusion. Data exclusions are meant for
short outages of a conversion action that's going to come back. Here the primary purchase action ("Booking
payment", the old ETO tag) is being **replaced** by a new action with zero history. So Smart Bidding re-learns
whatever you do. Meanwhile Maximise conversions keeps spending the whole £30 a day with **no CPC ceiling in
Search**, steering by calls only (and this week not even those). CPCs already doubled under this strategy,
from £1.12 in March to £2.20 in October, while it chased calls, including your own brand name at £2.83 a click.
Maximise clicks and Target impression share are both worse for this account, for the reasons in §3. Target CPA
is impossible without conversions. **Go back to Maximise conversions (no target) only when "CET web booking"
has recorded 15 or more bookings in 30 days, and that count matches the Command Centre's gclid bookings
within ±10%** (or ±2 at low volume). Add a Target CPA only after 30+ conversions in 30 days and 4 stable weeks,
set at the real cost per booking.

---

## 1. What Maximise conversions / Target CPA do when tracking is broken (Q1)

**Account state:** Maximise conversions, no target. Goals = Phone call leads + Purchases. The only Purchase
action, "Booking payment", is the ETO-era tag. It stopped firing when `/booking` moved to the CET widget
(about 25–28 Oct). Banner: "0 conversions this week, a 100% drop".

What Google's own documentation says:
- **Maximise conversions needs conversion tracking, and it spends the budget.** Google: *"We'll aim to spend
  your budget to maximise conversions for your campaigns… You must set up conversion tracking to use this
  strategy."* ([About Maximise conversions bidding](https://support.google.com/google-ads/answer/7381968)).
  With no target, it *"will aim to achieve the lowest possible CPA given the budget"*
  ([Google Ads API, MaximizeConversions](https://developers.google.com/google-ads/api/reference/rpc/v26/MaximizeConversions)).
  So it doesn't stop spending when conversions vanish. It keeps spending the full £30, guided by stale or
  absent signals.
- **There is no CPC cap on Maximise conversions in Search.** The current API reference says that for Search
  campaigns *"bid limits are not supported and are disregarded by the bidding system"*
  ([API v26](https://developers.google.com/google-ads/api/reference/rpc/v26/MaximizeConversions)). Nothing
  stops £4–£9 clicks. That's already happening: Heathrow's October CPC was £4.11, and `"taxi sheffield to
  heathrow airport"` cost £8.80 a click.
- **Google treats broken tracking as something that damages Smart Bidding.** That's why it built data
  exclusions: *"We recommend data exclusions for conversion data outages, including conversion tracking issues
  or conversion upload issues"* ([Use data exclusions for conversion data outages](https://support.google.com/google-ads/answer/10276486)).
  If under-reported days aren't excluded, the strategy learns that the traffic in those days "doesn't convert".
  Even after an exclusion, Google warns that *"bids and spend can drop briefly"* and that exclusions *"may change
  how bid strategies perform"* ([About data exclusions](https://support.google.com/google-ads/answer/10370710)).
- **Learning period.** Changing the goal's primary actions or the strategy puts Smart Bidding back into
  "Learning". Google's long-standing guidance was that calibration *"can take up to about 50 conversion events or
  three conversion cycles"* ([Duration of the learning period](https://support.google.com/google-ads/answer/13020501)).
  In October 2026 Google rewrote that page as "About Smart Bidding learning". Trade coverage reports that the
  **Learning label is now capped at 7 days**, but that the model keeps calibrating after the label ends
  ([Search Engine Roundtable, Oct 2026](https://seroundtable.com/google-ads-updates-smart-bidding-learning-doc-42345.html);
  [TechWyse](https://www.techwyse.com/news/platform-updates/google-ads-smart-bidding-learning-period-50-conversions)).
  `[UNCERTAIN: exact current wording not verified on Google's page; see "Verification limits" below.]`
  At about 1 conversion a day, 50 events is about 7 weeks, so in practice this account would be "calibrating"
  for most of the winter.
- **Target CPA** is a constraint on top of the same model: *"as many conversions as possible at or below the
  target"* ([API](https://developers.google.com/google-ads/api/reference/rpc/v26/MaximizeConversions);
  [About Target CPA](https://support.google.com/google-ads/answer/6268632)). With 0 recorded conversions, any
  target is meaningless. Set it low and the campaign stops spending. Set it high and it behaves like the
  uncapped Maximise conversions it already is.

**Is it harmful here? Yes, in four specific ways:**
1. **It's now optimising to calls only**, and calls include brand callers. The brand keyword ran at 23.6%
   "conversion rate" and got bid up to **£2.83 a click** for your own name (data review §2). The algorithm
   learned "anything that rings".
2. **No ceiling at a time of rising CPCs.** CPC by month under this strategy: £1.12, £1.63, £1.62, £1.51, £1.63,
   £1.70, **£2.09, £2.20** (campaign_by_month export). The change history (1 Aug–28 Oct) shows **no** bid or
   strategy edits, so either the market or the algorithm moved CPCs. Manual CPC caps both.
3. **The tracking fix itself resets it.** Adding "CET web booking" as the Purchase primary and demoting calls
   changes the goal's composition, which triggers re-learning, starting from a brand-new action with no history.
4. **It ignores your keyword bids**, so the 110-row bid file built on 8 months of data does nothing while it's on.

## 2. Is a data exclusion the right move if they keep Maximise conversions? (Q3)

**If** they kept Maximise conversions, an exclusion would be **mandatory**, and yes, it can be applied
**retroactively**. Google's API docs describe exclusions as *"backward looking… for events that have a
start_date_time in the past and an end_date_time either in the past or future"*
([Create data exclusions, Google Ads API](https://developers.google.com/google-ads/api/docs/campaigns/bidding/data-exclusions)).
Google's rules for setting it up ([outage article](https://support.google.com/google-ads/answer/10276486)):
- Exclusions work on **clicks**, so start the window early enough to cover the conversion delay. *"Exclude at least
  90% of clicks associated with impacted conversion data."*
- Apply it as soon as possible. *"Don't remove an exclusion once it's applied"*: removing it causes swings.
- It doesn't change reporting, only what Smart Bidding learns from.
- Google warns against frequent or long-running exclusions.
- It's available for Search; you can scope it to one campaign.

How it would be set for CET: **Tools (spanner) → Shared library → Bid strategies → Advanced controls → Data
exclusions → +** (some accounts show it as Tools → Budgets and bidding → Advanced controls). Name `ETO tag
dead – widget swap`. Scope: campaign `RT-Search-CAMP JAN26`, all devices. Start **24 Oct 2026 00:00** (a day
before the earliest possible break, to cover click-to-booking delay). End: the day "CET web booking" shows
"Recording conversions", 23:59.

**Why it's still not the right move here:**
- An exclusion only tells Smart Bidding to ignore the outage window. It doesn't give it a signal. During and
  after the window, the strategy is still bidding live with **no booking signal and no CPC cap**.
- The window isn't a blip in a working action. The old action is dead for good and a **new** primary takes over,
  so learning restarts anyway. Google's own framing is outages of an action that resumes.
- The open-ended end date (it depends on the GTM build) is exactly the "long-running" use Google warns against.

**Under Manual CPC an exclusion isn't needed.** Exclusions only affect Smart Bidding. When you switch back later,
the new primary ("CET web booking") has no broken window to exclude. Calls and the old Purchase action will be
Secondary, so they don't feed bidding. **One exception:** if calls are kept Primary at switch-back (see §6, fallback),
create the exclusion above for the outage window at that point. Past dates are allowed.

## 3. The options compared for this account (Q2, Q5)

Account facts that drive the choice: £30/day (about £912/month), local service, 271 "conversions" in 8 months
(mostly calls, about 34/month at £60/day), likely about 15–20/month at £30/day, of which web bookings are a handful a week.
Current conversion signal: broken. Impression share 40.9%. airporttaxis-uk.co.uk sits above you in 59% of shared auctions.

| Option | Needs conversion data? | CPC control | Fit for CET now | Main risk at £30/day |
|---|---|---|---|---|
| **Manual CPC** (Enhanced CPC retired) | No. No learning period | **Per keyword**, hard ceiling | **Best.** Uses the 8-month keyword data; Primary/Secondary changes are reporting-only | Under-delivery / lost impression share if bids are too low; weekly upkeep; no auction-time signals (device, time, user) |
| Maximise clicks + max CPC limit | No | One cap for the whole campaign; **ignores keyword bids** | Poor | Budget flows to the **cheapest** clicks, not the best ones (below) |
| Maximise conversions (current) | Yes | **None in Search** | Harmful now; right later | Spends 100% of budget on a broken/call-only signal; brand overbidding; re-learning |
| Target CPA | Yes, more | Indirect (via target) | Not possible | Target too low means no spend; too high means uncapped |
| Target impression share | No | Campaign-wide cap | Wrong goal | Buys position, not bookings. Against a 46.6%-IS competitor it pushes CPC up |

**Enhanced CPC: confirmed gone for Search.** Google stopped offering it for new Search/Display campaigns in
October 2024. Campaigns still on it after **15 March 2025** were moved to Manual CPC. Its definition page says
ECPC is *"no longer available for Search and Display campaigns effective the week of March 31, 2025"*
([About Enhanced CPC](https://support.google.com/google-ads/answer/2464964);
[Search Engine Land](https://searchengineland.com/google-ads-deprecate-enhanced-cpc-search-display-446350)).
You won't see the tickbox. Plain Manual CPC is what's left.

**Why not Maximise clicks with a cap?** Google says it *"sets bids to get as many clicks as possible within your
budget"*, and warns that *"if your CPC bid limits are too low, your bid strategy might not be able to help you achieve
your goal"* ([About Maximise clicks](https://support.google.com/google-ads/answer/6268626)). In a single shared-budget
campaign it would shift money into the cheapest auctions: Weddings & Events (£1.00 CPC, 2.0% conv. rate, about
£50/conv) and cheap town or out-of-area variants. It would starve Executive/Corporate (£1.82 CPC but 4.5% conv. rate,
£40/conv) and Heathrow (£290+ jobs). Clicks would go up and bookings down. Practitioners make the same point: Max
Clicks optimises for volume, not intent ([Seer Interactive](https://seerinteractive.com/insights/break-free-from-max-clicks)).
It also throws away per-keyword bids, which are the whole Manchester squeeze.

**Why not Target impression share?** It's built for visibility goals such as brand defence
([About Target impression share](https://support.google.com/google-ads/answer/9121108)). It's a campaign-level
strategy, so you'd need a separate Brand campaign to use it sensibly. New campaigns start with Google's defaults
(Display/partners on, presence-or-interest), which is the leak the action plan avoids. Not worth it for £15 a month of brand.

**Minimum conversion volumes (what Google says vs what is quoted):**
- Google's **current** help pages give **no hard minimum** for Maximise conversions or Target CPA. They say more data
  calibrates faster ([learning article](https://support.google.com/google-ads/answer/13020501);
  [Smart Bidding best practices, Google PDF](https://services.google.com/fh/files/misc/smartbiddingbestpractices1s.pdf)).
- The often-quoted **"15 conversions in 30 days for Target CPA"** is Google's **older** eligibility guidance. **"30 in 30
  days"** is the common practitioner standard for a stable Target CPA, and 50 is quoted for tROAS
  ([Store Growers summary](https://www.storegrowers.com/target-cpa/);
  [GrowMyAds](https://growmyads.com/switch-from-maximize-conversions-to-target-cpa/)). `[UNCERTAIN: these are not on
  Google's current pages as hard rules; treat them as working thresholds, not policy.]`
- The usual path agreed across sources: start on Maximise conversions, and add a target once you have about 30 a month
  and a stable CPA. Set the first target at your **actual** recent CPA, not an aspiration
  ([Search Engine Land, Target CPA guide](https://searchengineland.com/target-cpa-bidding-451823)).
  Google: *"changing a target won't trigger a 'learning' status"*, and evaluate over **28 days excluding the last 2**
  ([Setting smarter Search bids](https://support.google.com/google-ads/answer/10970825)).

**Risks of the chosen option (Manual CPC) and their controls:**

| Risk | How we'll see it | Control |
|---|---|---|
| Bids too low, so impression share falls further and the budget is under-spent | Spend < £27/day; "Search lost IS (rank)" > 50% | If spend < £27/day for 3 days in a row: +10% on General's converting phrase keywords (plan §3) |
| Bids too high on a few keywords, so one click eats 10%+ of the day | Keyword Avg CPC > £3 | Hard cap of £3.00 on any single keyword for now (§5) |
| Market keeps rising (Sep–Oct trend) | Avg CPC creeping toward max CPC; top-of-page rate falling | Weekly review; move bids ±15% max per week, data-led |
| No auction-time signals (device, time, user) | Can't see this directly | Accept for 4–8 weeks. That's what the switch-back is for |
| Director time | — | One 15-minute weekly check (§4, Day 7) |

## 4. Click-by-click on the tablet (Chrome, "Desktop site" ticked, landscape)

**Step 0 — Check calls are still being counted (2 min, read-only).** Goals → Conversions → Summary → tap **Calls
from ads** (under Phone call lead). Note its **Status** and the last conversion date. 0 conversions this week is
unlikely from calls alone: about 2–3 calls a week would be expected even at £30/day. If it says "No recent
conversions" or "Inactive", screenshot it. The call asset may be broken.

**Step 1 — Switch to Manual CPC (3 min).**
1. Left menu **Campaigns** → tap **RT-Search-CAMP JAN26** → **Settings** (left sub-menu or the top tabs).
2. Expand **Bidding** → tap **Change bid strategy**.
3. Google shows "What do you want to focus on?" Ignore the dropdown and tap the small link **"Or, select a bid
   strategy directly (not recommended)"**.
4. Choose **Manual CPC**. (There's no Enhanced CPC tickbox any more. If one appears, leave it **unticked**.)
5. **Save**. Ignore the "Your campaign may get fewer conversions" warning.

*You should see:* Bidding: "Manual CPC". No "Learning" label.

**Step 2 — Ad-group default bids (5 min). Do this right after Step 1.**
Left menu **Campaigns → Ad groups**. For each row, tap the **Default max. CPC** cell (the pencil), type the value
from §5 → **Save**. Don't change Birmingham; it gets paused in walkthrough Step 7.

*You should see:* the §5 values in the Default max. CPC column.

**Step 3 — Keyword bids (10 min).** Walkthrough **Step 6** with the corrected `keywords-upload-step1-bids-pauses.csv`
(Tools → Bulk actions → Uploads → **+** → Upload file → **Preview** → **Apply**).

*You should see:* "133 changes, 0 errors". Keywords sorted by Max. CPC: nothing above **£3.00**.

**Step 4 — No data exclusion.** Not needed on Manual CPC (§2). Don't create one.

**Step 5 — Columns for the weekly check (2 min, once).** Ad groups → **Columns** icon → **Modify columns** →
**Competitive metrics** → tick **Search impr. share**, **Search lost IS (rank)**, **Search lost IS (budget)**,
**Search top IS** → also tick **Top of page bid (low range)** and **(high range)** under Keywords attributes,
if offered → **Save**.

**Day 3 and Day 7 checks (10 min each), date range "Last 7 days":**
- Spend about £30/day? If under £27 for 3 days, raise General's converting phrase keywords by 10%.
- Avg CPC ≤ £1.60 (was £2.20)?
- Manchester ≤ 15% of cost? If over, cut its keyword bids by 15%.
- High "Search lost IS (rank)" with spend on target is **fine**. It means you're being outbid on auctions
  priced above your value. High "lost IS (budget)" means the budget is the limit, not the bids.
- **Send the Keywords export with the top-of-page bid estimate columns.** The 28 Oct export had none, so the bids
  below are derived from actual CPCs and conversion rates. With the estimates they get refined in week 2.

## 5. Bid caps (Q4), derived from the exports and sanity-checked

**Method.** For each ad group:
- **(a) Value ceiling** = target cost per conversion (D1 targets used in `keyword-actions.csv`: £40 General/EMA,
  £35 MAN/LBA/Weddings, £45 Exec, £70 London airports) × the 8-month conversion rate. That's the most a click can
  cost and still hit the target.
- **(b)** Historical average CPC (Mar–Sep vs October).
- **(c)** How much of the spend sits on keywords with **no** keyword-level bid in the upload file. Those are the
  only ones the ad-group default controls.

Under Manual CPC you usually pay below your max (often about 75–90% of it), so a max near the historical average
gives an actual CPC somewhat below it.

| Ad group | Value ceiling | Avg CPC 8 mo / Oct | Spend on default-bid keywords | Walkthrough bid | **Decision** | Why |
|---|---|---|---|---|---|---|
| General Airport Transfers | £1.11 (non-brand 2.78%) | £1.58 / £2.06 | **33%** (£2,361; 43.5 conv at £54; CPC £1.26) | 1.40 | **1.40 confirm** | Just above what those keywords actually cost (£1.26). Protects the backbone's volume. Keyword bids handle the expensive exacts |
| Manchester Airport Transfers | **£0.92** | £2.14 / £2.63 | 3% (£88, £59/conv) | 1.50 | **1.20 correct** | The default should start new/unbid MAN keywords conservatively. Its keyword bids (1.46–1.83) are already above the value ceiling, so recheck at day 14 |
| Executive Chauffeur / Corporate | £2.04 (4.54%) | £1.82 / £2.58 | 3% | 1.60 | **1.60 confirm** | Below the value ceiling. The 5 cheap converters carry their own raised bids |
| East Midlands | £1.15 | £1.51 / £1.88 | 8% (0 conv) | 1.30 | **1.30 confirm** | About the value ceiling; small exposure |
| Heathrow | **£2.77** (3.95%, £290+ jobs) | £2.04 / £4.11 | **39%** (incl. `taxi heathrow to sheffield` 2 conv at £2.35, `heathrow airport to doncaster taxi` 1 conv at £2.01) | 1.80 | **2.00 correct** | 1.80 would undercut the arrival-direction keywords that won 4 of Heathrow's 7 conversions. 2.00 still caps the £4–£9 clicks |
| Gatwick | n/a (0 conv; £320–350 jobs) | £1.34 / £0.58 | 100% | 1.50 | **1.50 confirm** | Above its normal CPC; blocks the odd £7 click |
| Luton | n/a (0 conv) | £1.05 / £1.14 | 100% | 1.50 | **1.50 confirm** | Same logic; tiny volume |
| Leeds Bradford | **£0.50** (1.42%) | £1.54 / £2.55 | 19% (0 conv) | 1.20 | **1.00 correct** | Weakest converter (£109/conv). The 2 converting keywords keep their own bids (1.25, 1.60) |
| Weddings & Events | **£0.70** (2.0%) | £1.00 / £0.90 | 10% (0.5 conv) | 0.90 | **0.80 correct** | Above the value ceiling and it's winter (D8). Cheap clicks, low intent |
| Brand (new) | n/a (23.6% conv) | £2.83 (smart-bid) | 100% | 1.00 | **1.00 confirm** | Brand clicks with high Quality Score should cost well under £1. Raise to £1.30 only if Search top IS on brand < 80% |

**Keyword-level corrections to `keywords-upload-step1-bids-pauses.csv`** (3 rows; file updated):

| Keyword | Was | Now | Why |
|---|---|---|---|
| `"taxi to heathrow airport"` (Heathrow, phrase) | 3.31 | **2.00** | 13 clicks, 0 conv, £4.73 CPC, no origin town. 3.31 would still allow 11% of the day on one click |
| `"executive airport transfer sheffield"` (General, phrase) | 3.82 | **3.00** | 19 clicks / 3.8 conv is a small sample (possibly repeat callers). Keep it strong but capped |
| `"chauffeur services sheffield"` (Exec, phrase) | 3.27 | **3.00** | 20 clicks / 3.4 conv, same reasoning |

Everything else in the upload file is consistent with the method: cuts of about 30% on keywords above target, and
historical CPC kept on keywords near target.

**Expected result at £30/day:** avg CPC about £1.40–£1.60, so about 570–650 clicks a month, and at about 3.3% about
19–21 conversions (calls + bookings) a month. That's below Apr–Sep volume because the budget halved, not because of
the strategy.

## 6. When and how to go back to Smart Bidding (switch-back criteria)

**Stage 1: Manual CPC (now → at least 4 weeks after "CET web booking" starts recording).**

**Stage 2: Maximise conversions, no target.** Switch only when **all** of these are true:
1. "CET web booking" has shown **"Recording conversions"** for **14+ days**. It's the **only** Primary action;
   calls, the old "Booking payment" and the rest are Secondary (walkthrough Step 12).
2. **15 or more CET web bookings in the last 30 days** in the Conversions column.
3. That count matches Command Centre web bookings with a gclid within **±10% (or ±2 when under 20)**.
4. No other structural change in the same week (town split, new RSAs, budget).

**Timing caveat:** if the thresholds are met during the festive period (about 15 Dec–4 Jan), wait and switch on
**5 Jan 2027**. Holiday demand is not a normal pattern to learn from.

How: Settings → Bidding → Change bid strategy → **Maximise conversions** → **leave "Set a target cost per action"
unticked** → Save. Then **no edits to bids, goals or budget for 14 days**. Judge over 28 days, excluding the last 2
(Google's guidance). Expect a 1–2 week wobble. **Roll back to Manual CPC** if, over days 15–28, cost per CET web
booking is more than 30% worse than the Manual-CPC baseline from the 4 weeks before.

**Fallback if web bookings stay under 15 in 30 days** (possible at £30/day if most ad customers phone):
- **Option 1:** stay on Manual CPC. That's fine indefinitely at this spend.
- **Option 2:** make **Calls from ads** Primary again, but only after raising its **call length to 120 s** (Goals →
  Calls from ads → Edit settings → Call length), so "are you open?" calls don't count. If you do this, add the §2
  data exclusion for the outage window.
- Don't add soft actions (page views, taps) as Primary just to reach volume.

**Stage 3: Target CPA.** Only when **30+ primary conversions in 30 days** for **4 stable weeks** (cost per booking
within ±20% week to week). Set the target **equal to the actual 28-day cost per conversion**, then lower it by ≤15%
every 2 weeks toward the D1 target (£35–£45 local, £70 London). Changing a target doesn't restart learning, but big
jumps choke spend. At about 20 a month, Stage 3 may not come until the budget rises. **That's fine.** Maximise
conversions on clean data is the realistic end state for this budget.

**Later:** once the offline import of completed bookings works (tracking-fix §6), consider **Maximise conversion value**.
It knows a £290 Heathrow job from a £100 Manchester one. Value bidding needs more data (commonly 50+ a month), so
it's not this winter.

---

## Verification limits (read this)

`support.google.com`, `searchengineland.com` and other article pages **could not be fetched directly** in this
session (network allow-list). The Google quotes above come from Google's own pages as returned by web search (page
title, URL and extract), plus the Google Ads API reference. The points most worth a 2-minute manual check on Google's
pages before acting:
1. The current wording of the "About Smart Bidding learning" page: 7-day label cap, any conversion-count figure.
2. Whether a max CPC limit is now offered for Maximise conversions in Search. The API v26 says it's disregarded,
   which is what this plan assumes.
3. The exact menu path for Data exclusions in your account version. Only needed for the fallback.

None of these changes the verdict. They only refine the switch-back stage.

---

## Sources

Google (official):
- About Maximise conversions bidding: https://support.google.com/google-ads/answer/7381968
- Google Ads API, MaximizeConversions (no bid limits in Search; no-target behaviour): https://developers.google.com/google-ads/api/reference/rpc/v26/MaximizeConversions
- About Target CPA bidding: https://support.google.com/google-ads/answer/6268632
- Use data exclusions for conversion data outages: https://support.google.com/google-ads/answer/10276486
- About data exclusions: https://support.google.com/google-ads/answer/10370710
- Google Ads API, Create data exclusions (backward-looking/past dates): https://developers.google.com/google-ads/api/docs/campaigns/bidding/data-exclusions
- Learning period / About Smart Bidding learning: https://support.google.com/google-ads/answer/13020501
- Setting smarter Search bids (target changes, 28-day evaluation): https://support.google.com/google-ads/answer/10970825
- Smart Bidding best practices (PDF): https://services.google.com/fh/files/misc/smartbiddingbestpractices1s.pdf
- About Enhanced CPC (retired for Search, March 2025): https://support.google.com/google-ads/answer/2464964
- About Maximise clicks bidding: https://support.google.com/google-ads/answer/6268626
- About Target impression share bidding: https://support.google.com/google-ads/answer/9121108

Industry:
- Search Engine Land, Google Ads to deprecate enhanced CPC: https://searchengineland.com/google-ads-deprecate-enhanced-cpc-search-display-446350
- Search Engine Land, Target CPA bidding guide: https://searchengineland.com/target-cpa-bidding-451823
- Search Engine Land, Maximise conversions guide: https://searchengineland.com/maximize-conversions-bidding-google-ads-guide-450273
- Search Engine Roundtable, Smart Bidding learning doc update (Oct 2026): https://seroundtable.com/google-ads-updates-smart-bidding-learning-doc-42345.html
- TechWyse, 50-conversion calibration benchmark: https://www.techwyse.com/news/platform-updates/google-ads-smart-bidding-learning-period-50-conversions
- Seer Interactive, Break free from Max Clicks: https://seerinteractive.com/insights/break-free-from-max-clicks
- Nils Rooijmans, data exclusions in practice: https://nilsrooijmans.com/daily/saving-roas-with-data-exclusions
- GrowMyAds, when to switch Maximise conversions to Target CPA: https://growmyads.com/switch-from-maximize-conversions-to-target-cpa/
- Store Growers, Target CPA thresholds: https://www.storegrowers.com/target-cpa/

Account data: `docs/growth/data/ads-2026-03-01_2026-10-28/` (campaign_by_month, ad_group_by_month,
keyword_report, change_history_aug-oct) and `keyword-actions.csv`.

---

**Do these 3 first:**
1. **Bidding → Manual CPC** (§4 Steps 1–2) with the corrected default bids: General 1.40 · Manchester **1.20** ·
   Exec 1.60 · EMA 1.30 · Heathrow **2.00** · Gatwick 1.50 · Luton 1.50 · LBA **1.00** · Weddings **0.80**.
2. **Upload the corrected `keywords-upload-step1-bids-pauses.csv`** (walkthrough Step 6). No keyword above £3.00.
3. **Build "CET web booking" in GTM** (tracking-fix §5) and check "Calls from ads" status (§4 Step 0). The
   switch-back clock only starts when bookings are recording.
