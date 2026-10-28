# Google Ads corrected action plan — 29 Oct 2026

**Supersedes:** §1, §3, §6 and §7 of `2026-10-28-cleanup-blueprint.md` (written without data).
Its settings checklist (§5) and GTM build (§8) still stand, with the corrections in
`2026-10-29-conversion-tracking-fix.md`.
**Evidence:** `2026-10-29-data-review.md`.
**Director's click-by-click:** `WALKTHROUGH-tablet.md`.

## Do NOT pause General

The 28 Oct blueprint said "Pause General". **That was wrong.** It assumed General meant cheap
"taxi sheffield" traffic. In reality **General Airport Transfers is the ad group that wins most
of the business**: 169 of 271 conversions, £45 each, and 129 of them non-brand at £55. It stays on.
It gets trimmed (lower bids on 3 expensive exact keywords, brand moved out, towns split out in week 2), not paused.

---

## 1. Real structure, and what happens to each ad group

One live campaign: **`RT-Search-CAMP JAN26`** (Search, £30/day). Keep it, and keep the name
exactly as is: the upload files match on it. Leave `RT-Search-CAMP` and `RT-Search-CAMP DEC25`
paused (don't delete them; `RT-Search-CAMP` points at centraltravels.co.uk).

| Ad group | Decision | Final URL | Default max CPC* |
|---|---|---|---|
| General Airport Transfers | **KEEP**. Lower 3 big exact bids, pause 4 losers, move brand out (week 1) and the 4 towns out (week 2) | `/airport-transfers-sheffield` | £1.40 |
| **Brand** (NEW) | **CREATE**: exact + phrase "central executive transfers" + variants, £1.00 max CPC | `/` (homepage) | £1.00 |
| Manchester Airport Transfers | **KEEP, SQUEEZE**: pause 5 keywords, lower 10 others by up to 30%, ad-group exact negatives on "manchester airport taxi (service)". **No separate campaign or budget** (see §3) | `/sheffield-to-manchester-airport-transfers` | £1.50 |
| Executive Chauffeur / Corporate | **KEEP, FEED**: raise the 5 cheap converters +15% | `/executive-chauffeur-sheffield` | £1.60 |
| East Midlands Airport Transfers | KEEP | `/sheffield-to-east-midlands-airport-transfers` | £1.30 |
| Heathrow Airport Transfers | KEEP. Cut the no-origin "taxi to heathrow airport" (CPC £4.73) | `/sheffield-to-heathrow-airport-transfers` | £1.80 |
| Gatwick Airport Transfers | KEEP (cheap, £350 jobs) | `/sheffield-to-gatwick-airport-transfers` | £1.50 |
| Luton Airport Transfers | KEEP (cheap) | `/sheffield-to-luton-airport-transfers` | £1.50 |
| Leeds Bradford Airport Transfers | KEEP small: 2 converting keywords, others lower | `/sheffield-to-leeds-bradford-airport-transfers` | £1.20 |
| Weddings & Events Chauffeur | KEEP, lower bids; pause `limousines sheffield` (no limos) | `/prom-wedding-chauffeur-sheffield` | £0.90 |
| Birmingham Airport Transfers | **PAUSE** (£111, 73 clicks, 0 conv) | — | — |
| Rotherham / Barnsley / Doncaster / Chesterfield Airport Transfers (NEW, **week 2**) | Split out of General so each town lands on its own page | `/rotherham-airport-transfers` etc. | £1.00 (Barnsley £0.90) |

\* *Ad group → Default max CPC (only used when the bid strategy is Manual CPC; per-keyword bids in
the upload files override it).*

Not built now: a separate Corporate ad group to `/corporate-chauffeur-sheffield` (too little search
volume to split yet; the Executive page covers it), and a Minibus/V-Class ad group to
`/mercedes-vclass-hire-sheffield` / `/minibus-hire-sheffield` (minibus keywords spent about £100 in
8 months; revisit after the town split).

### Why Brand gets its own ad group (not its own campaign)
- 39 of 271 conversions came from your own name, at **£2.83 a click**. Separating it (a) caps the
  price at £1.00, (b) stops brand flattering General's numbers, and (c) makes the real non-brand
  cost visible.
- An ad group inside the existing campaign, rather than a new campaign, because a new campaign
  starts with Google's defaults (Display and partners ON, presence-or-interest). That's the
  #1 way a tablet edit leaks money. It also has no budget to manage.
- After it's live, add `"central executive"` as an ad-group negative in every other ad group
  (`negatives-data-paste.txt` Part B), so brand searches can only go to Brand.

### Why Manchester does NOT get its own campaign/budget
Manchester has been the worst big ad group in 7 of 8 months (£67, £91, £37, £79, £55, £76, £87, then
**£458** per conversion in October), on a fare of about £100. A protected budget would protect the
worst performer. It stays in the shared campaign, on lower bids, and has to earn its share. Revisit
only if tracked web bookings show Manchester converting into real bookings better than the call
data suggests.

---

## 2. Change list for this week (ranked by money impact)

| # | What | Where | Why (evidence) | Expected effect |
|---|---|---|---|---|
| 1 | **URGENT: fix conversion tracking.** Since about 25–28 Oct, `/booking` runs the CET widget, which isn't wired to Ads, so web bookings are probably not recorded at all. Build `CET web booking` (Purchase, value, booking ref) from the widget's `cetBooking` message in GTM. Find out what the existing "Purchase" action is. After 7 days and 3+ real web bookings recorded, set "Phone call lead"/calls to **Secondary** | GTM-WHH77L45 + Goals → Conversions (`2026-10-29-conversion-tracking-fix.md`) | 271 "conversions" are calls + an unknown Purchase (probably the old ETO confirmation). March conv value exceeded all revenue. October recorded conversions fell 59% vs trips −26%, and the change history shows no account change | First true cost per booking. Lets bidding/judging use money, not calls |
| 2 | **Bid strategy → Manual CPC** (if not already), ad-group default bids as in §1, then upload `keywords-upload-step1-bids-pauses.csv` (110 bid changes, 23 pauses) | Campaign → Settings → Bidding; Tools → Bulk actions → Uploads | CPC doubled Mar→Oct (£1.12 → £2.20) at a flat budget, with no account changes (market-driven). Manual caps stop paying the inflation on low converters. Smart bidding (if on) is learning from calls and a broken Purchase signal | CPC back to about £1.45–£1.55 means about 600+ clicks a month on £912 instead of about 415 |
| 3 | **Squeeze Manchester**: pause 5 exact/phrase losers, lower the rest about 30%, add 5 ad-group exact negatives | In the upload file + negatives Part B | £2,631 for 32.5 conv (£81); Oct £458/conv | Manchester share of spend 19% → about 12%. Frees about £3/day for General/Exec |
| 4 | **Brand ad group** at £1.00 + `"central executive"` negatives elsewhere | New ad group; `keywords-upload-step2-brand.csv`; ads-v2 Brand row | £473 at £2.83 CPC for your own name | Brand cost about £15/month, not about £60. Honest non-brand numbers |
| 5 | **Settings leaks**: Auto-apply recommendations OFF. Automatically created assets OFF. Search partners + Display OFF. Location = Sheffield + 25 mi, **Presence only**. Broad match/AI Max OFF (ignore the "Turn on AI Max" banner) | Account → Recommendations → Auto-apply; Campaign → Settings | AI sitelinks to `/#prices`, `/#why`, `/gallery`. Out-of-area searchers (Aylesbury £19.51 a click). Change history shows no auto-applied changes since 1 Aug, so auto-apply OFF is prevention, not a cure | Stops silent changes. Stops paying for people outside the area |
| 6 | **Negatives**: shared list `CET Master Negatives` (360 terms, Part A); **remove 3 blocking negatives** (`sheffield airport transfers`, `airport taxis doncaster`, `taxi manchester airport to sheffield`) | Tools → Shared library; Keywords → Negative keywords | £630 of clear junk visible. 3 negatives block converting/enabled keywords | About £80/month less junk. Recovers a converting core term |
| 7 | **Pause Birmingham ad group**; pause `limousines sheffield` | Ad groups | £111/0; no limousines in the fleet | −£14/month waste; no misleading ad |
| 8 | **New RSAs** (add alongside the current ones; don't delete) | `ads-v2.csv` | Remove "Central Travels", "Central Transfers", 24/7 and model claims; more specifics | Higher CTR/QS, so lower CPC. Compliant copy |
| 9 | **Assets**: call asset number (D7). Callouts: replace "24/7 Availability" (unless D3 confirms), "Trusted by Professionals" and "Executive Mercedes Fleet" with "VAT Invoices", "Live Driver Tracking" and "Licensed Operator OP037". Sitelinks: add Manchester Airport, East Midlands Airport, Corporate Accounts, Groups & V-Class (from `ads-assets.csv`), and point "Airport Prices" at `/airport-transfers-sheffield` | Campaign → Assets | Claims not in BUSINESS_FACTS; sitelinks to anchors | Compliance; sitelink clicks land on matching pages |
| 10 | **Tablet −50%** | Campaign → Devices | 174 clicks, 2 conv, £132/conv | About −£15/month |

**Week 2 (after the 7-day check):** town split (`keywords-upload-step3-towns.csv` + the 4 town
RSAs in `ads-v2.csv` + routing negatives), demote calls to Secondary (if CET web booking is
recording), and review search terms again.

---

## 3. Budget logic at £30/day

One campaign means one budget. You can't give ad groups a budget; **bids decide who gets the
money.** The bids in the upload files aim for roughly this share. Check weekly in Ad groups,
sorted by Cost:

| Ad group | Target share | ≈ £/day | Was (Mar–Oct) |
|---|---|---|---|
| General (non-brand, incl. towns until split) | 50% | £15 | 52% |
| Executive Chauffeur / Corporate | 17% | £5 | 12% |
| Manchester | **12%** | £3.50 | **19%** |
| EMA + Heathrow + Gatwick + Luton | 11% | £3.30 | 6% |
| Weddings & Events | 4% | £1.20 | 4% |
| Leeds Bradford | 2% | £0.60 | 2% |
| Brand | 2–3% | £0.50–0.80 | 3% (at 3× the price) |

Rules:
- If Manchester's share goes over 15%, cut its bids another 15%.
- If the campaign isn't spending £27+/day after 7 days (unlikely; it was capped all year),
  raise the bids on General's converting phrase keywords by 10%.
- **Is £30 enough?** At October's efficiency it buys about 9 conversions a month (£100 each).
  At the efficiency this plan targets (CPC about £1.45, CR about 3.3%) it buys about 21 (£44),
  about 4 of them brand. That's enough to keep the core converting terms on while tracking is
  fixed, but not enough for full coverage. **Raise to £36, then £45, only after 2 straight weeks
  of tracked web bookings at or under the D1 target cost per booking.** Raise in 20% steps, each
  held for 2 weeks.

## 4. Bid strategy — honest reasoning

- **Current strategy unknown.** Check Campaign → Settings → Bidding and screenshot it before
  changing anything.
- **Recommendation: Manual CPC now.**
  - If it's *Maximise clicks*: that buys the cheapest clicks, but with no CPC cap it lets CPC drift.
  - If it's *Maximise conversions / Target CPA*: it's optimising to **calls** (including brand calls
    from existing customers) and an unverified Purchase action. It has learned to win brand and
    "anything that rings". Since about 25–28 Oct it probably can't see web bookings at all (the
    `/booking` widget swap), so it would be optimising to calls only.
  - The change history (1 Aug–28 Oct) shows no bid or strategy edits, so the September–October CPC rise
    is the market (or a smart strategy moving CPCs by itself). Manual CPC caps that either way.
  - Smart bidding is only as good as its signal, and the signal is wrong. Manual CPC with the bids
    in the upload files puts a hard ceiling on every keyword.
- Cost of switching: a few days of wobble, and you lose some auction-time signals (device, time, user).
  It's acceptable at £30/day with a broken signal.
- **Back to Maximise conversions** when **CET web booking** (Primary) has **15+ bookings in 30 days**
  and the count matches the Command Centre's gclid web bookings within ±10%. **Target CPA** only after
  30 more days, set at the actual cost per booking.
- **Device:** tablet −50%. Mobile and computers 0 (cost per conversion is within 10% of each other).
- **Ad schedule:** no change yet (no hour/day export). If the call-asset phone isn't answered at night,
  schedule **the call asset** (not the campaign) to answered hours. Web bookings work around the clock.

## 5. Settings (exact values)

| Setting | Value | Path |
|---|---|---|
| Networks | Google Search only. Untick Search partners, untick Display | Campaign → Settings → Networks |
| Locations | Sheffield + 25 mi radius (Rotherham, Barnsley, Doncaster, Chesterfield, Worksop inside) | Settings → Locations |
| Location options | **Presence: people in or regularly in** | Settings → Locations → Location options |
| Languages | English | Settings → Languages |
| Bidding | Manual CPC | Settings → Bidding |
| Budget | £30.00 | Settings → Budget (already set) |
| Automatically created assets | **OFF** (text assets + final-URL/dynamic sitelinks + dynamic images) | Settings → More settings → Automatically created assets |
| Broad match / AI Max | OFF | Settings |
| Auto-apply recommendations | **all OFF** | Campaigns → Recommendations → Auto-apply |
| Ad rotation | Optimise | Settings → More settings |
| Goals | Account-default; after week 1, Primary = CET web booking only | Settings → Goals |

## 6. Microsoft Ads (later)
Once Google is clean (tracking verified, 2 stable weeks), import to Microsoft Ads (Import → Google Ads)
at about 30% lower bids, £3–£5/day **on top** only if the directors approve. Set the location option to
"people in" (Microsoft defaults to "in or interested in"). Its audience skews older and desktop business
travellers, which suits Executive/Corporate and Heathrow.

## 7. Decisions the directors must make
- **D1 Target cost per booking.** Proposed: £35 (local airports/MAN), £45 (chauffeur/corporate), £70
  (London airports). Also: do ad-won jobs go to directors first? A cover job keeps £14 of a £144 fare,
  so it can't carry a £40 ad cost.
- **D2 Prices in ads.** The live Manchester ad already says **"Fixed Price £100"** (kept in v2). Confirm
  that matches `/sheffield-to-manchester-airport-transfers` for a standard car, or remove it. Also say
  whether you want EMA £100 / Heathrow £290 headlines and price assets.
- **D3 Claims.** Is "24/7" true for phone answering? If not, remove the "24/7 Availability" callout and
  the 24/7 sitelink text. Also confirm the licensing authority for "Licensed Operator OP037".
- **D7 Call asset number.** It currently rings **+44 7405 172435**. BUSINESS_FACTS gives **0114 399 8242**
  for calls. Which phone is reliably answered? Call reporting works with either.
- **D8 Weddings in winter.** Keep at reduced bids (about £1.20/day), or pause Nov–Feb?
- **D6 Competitor-name negatives.** The data list blocks other firms' names (Filcan, Lynx, MMA,
  Streetcars, A2B…). Keep that, or deliberately bid on any of them?

---

**Do these 3 first:**
1. **Tracking:** build `CET web booking` in GTM and find out what the existing "Purchase" action is
   (walkthrough Steps 9–10). Without it every number here is calls, not bookings.
2. **Stop overpaying:** bid strategy → Manual CPC with the default bids, then upload
   `keywords-upload-step1-bids-pauses.csv` (Manchester squeeze, 23 pauses incl. Birmingham, 110 bid changes).
3. **Close the leaks:** auto-apply OFF, automatically created assets OFF, partners/Display OFF,
   **Presence only**, then the shared negative list (Part A) and removing the 3 blocking negatives.
