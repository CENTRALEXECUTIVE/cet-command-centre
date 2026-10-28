# Google Ads change log

One row per change **actually applied in the account** (date = day the director did it).
"Planned" rows are waiting to be applied. When a planned change is done, put the real date in
the Date column. At 7 and 14 days, fill in "Result".

## Applied

| Date | Change | Where | Reason | Metric to check | Status / result |
|---|---|---|---|---|---|
| 2026-10-26 14:15 | Budget £60 → £30/day | RT-Search-CAMP JAN26 | Directors' decision | Spend ≈ £30/day; conversions/week; cost per conversion | **Applied** (confirmed in change history; the only change since 1 Aug). Apr–Sep ran at the £60 cap every month |
| ~2026-10-25/28 (site, not Ads) | `/booking` switched from the ETO iframe to the CET widget; site rebuilt mid–late Oct; homepage 500 for several hours one night | Live website | Website rebuild | Ads conversions by day vs Command Centre web bookings by day | **Likely broke web-booking tracking** (see 29 Oct review §5) |

## Withdrawn

| Date | Change | Why withdrawn |
|---|---|---|
| 2026-10-28 | "Pause General, Weddings, Leeds Bradford, Birmingham, Gatwick", "3 campaigns", "budgets £11/£11/£8", `keywords.csv` / `ads.csv` rebuild | Blueprint written without data. The account is 1 campaign / 10 ad groups and **General is the top performer**. Replaced by `2026-10-29-action-plan.md` |

## Planned (29 Oct plan, in walkthrough order)

| Date | Change | Where | Reason (evidence) | Check after 7 days | Check after 14 days | Status |
|---|---|---|---|---|---|---|
| planned (Day 1) | Read-only check: bid strategy, location option, what "Purchase" and "Phone call lead" actions are | Settings; Goals | Unknown inputs to the plan | Screenshots received | — | Planned |
| planned (Day 1) | Auto-apply recommendations OFF | Recommendations → Auto-apply | Prevention (none applied since 1 Aug) | Change history: no "auto-applied" rows | Same | Planned |
| planned (Day 1) | Search partners + Display OFF; Sheffield + 25 mi; **Presence only**; automatically created assets OFF; broad/AI Max OFF | Campaign settings | AI sitelinks to `/#prices`, `/#why`; out-of-area searches (Aylesbury £39 / 2 clicks) | Locations "where your users were": ≈100% presence; no Google AI assets added | Out-of-area search terms ≈ £0 | Planned |
| planned (Day 1) | Bid strategy → **Manual CPC**; ad-group default bids (General 1.40, MAN 1.50, Exec 1.60, EMA 1.30, LHR 1.80, LGW/LTN 1.50, LBA 1.20, Weddings 0.90) | Settings → Bidding; Ad groups | CPC £1.12 → £2.20 (Mar → Oct); smart bidding (if on) learns from calls | Avg CPC ≤ £1.60; spend ≈ £210/week | Clicks/week ≥ 140; cost/conv (all conv.) ≤ £50 | Planned |
| planned (Day 1) | **GTM `cetBooking` → "CET web booking"** (Purchase, Primary, value + ref); quote/phone/WhatsApp Secondary | GTM-WHH77L45; Goals | Web bookings probably unrecorded since the widget swap; March conv value > revenue | CET web booking "Recording"; count ≈ Command Centre web bookings (gclid) ±1 | Within ±10% of the Command Centre | Planned — **URGENT** |
| planned (Day 1) | `keywords-upload-step1-bids-pauses.csv`: 110 bid changes, 23 pauses (5 Manchester, 4 General, Birmingham's, limousines, 1 Exec) | Bulk uploads | 8-month keyword data (`keyword-actions.csv`) | 0 upload errors; paused keywords show £0 | Manchester share of spend ≤ 15% (was 19%); General non-brand cost/conv ≤ £50 | Planned |
| planned (Day 1) | Pause Birmingham ad group | Ad groups | £111, 73 clicks, 0 conv | £0 spend | — | Planned |
| planned (Day 1) | Tablet −50% | Devices | 174 clicks, 2 conv, £132/conv | Tablet spend ≈ halved | — | Planned |
| planned (Day 1) | Shared list `CET Master Negatives` (360) applied; remove 3 blocking negatives (`sheffield airport transfers`, `airport taxis doncaster`, `taxi manchester airport to sheffield`) | Shared library; Negative keywords | £630 junk visible; 3 negatives block converting/enabled keywords | Search terms: no listed junk; `airport taxis doncaster` getting impressions again | Junk share of visible spend < 5% | Planned |
| planned (Day 2) | **Brand ad group** (5 kw, £1.00) + `"central executive"` negatives in other ad groups + 5 Manchester exact negatives; pause brand phrase in General | New ad group; `negatives-upload-adgroup.csv` | Brand at £2.83 CPC, 39 conv | Brand CPC ≤ £1.00; brand top-of-page share ≥ 80% | Brand spend ≤ £20/2 weeks | Planned |
| planned (Day 2) | New RSAs (v2) added **alongside** old ones in 10 ad groups | `ads-v2-upload-week1.csv` | Remove "Central Travels"/24-7/model claims; more specifics | All new ads Eligible | New vs old ad CTR per ad group; pause the loser at day 14 | Planned |
| planned (Day 3) | Assets: call number (D7); callouts swapped; sitelinks to route pages | Assets | Claims not in BUSINESS_FACTS; call asset rings the mobile | Assets Eligible | Calls from ads (count, ≥ 60 s) vs before | Planned |
| planned (Day 7) | Calls, old Purchase, directions, engagement, page view, lead-form actions → **Secondary** (only once CET web booking has ≥ 3 real bookings) | Goals | One true booking signal | "Conversions" = CET web booking only | Conv. value ≈ booked value in the Command Centre | Planned |
| planned (Week 2) | Town split: 4 ad groups (Rotherham, Barnsley, Doncaster, Chesterfield) to town pages; `keywords-upload-step3-towns.csv` | New ad groups | Town terms land on the generic page today; Rotherham/Doncaster convert well | Town ad groups Eligible, spending | Town CTR ≥ General's; cost/conv vs before in General | Planned |
| planned (Week 4+) | Back to Maximise conversions **only if** ≥ 15 tracked web bookings in 30 days and counts match the Command Centre | Settings → Bidding | Signal must be bookings, not calls | — | — | Conditional |
| planned (Week 4+) | Budget £30 → £36 (then £45) **only if** 2 weeks of tracked cost per booking ≤ D1 target | Budget | £30 ≈ 21 conv/month at plan efficiency | — | — | Conditional |
| planned (later) | Microsoft Ads import (£3–5/day extra, director approval) | Microsoft Ads | Cheap clicks, business travellers | — | — | Conditional |

## Weekly check template (7 / 14 days after the Day-1 changes)
1. Spend ≈ £30/day? Avg CPC (target ≤ £1.60, was £2.20 in Oct)?
2. CET web booking count vs Command Centre web bookings with a gclid.
3. Share of spend by ad group vs plan §3 (Manchester ≤ 15%).
4. Search terms: new junk → `CET Master Negatives`; converting terms → exact keywords.
5. Did any paused keyword or negative block a converting search? (Search terms marked "Excluded" with conversions.)
