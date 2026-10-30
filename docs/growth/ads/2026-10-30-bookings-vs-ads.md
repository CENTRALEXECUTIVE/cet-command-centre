# Real bookings vs Google Ads — 30 Oct 2026

Source: ETO bookings export (868 rows, created 17 Mar 2025 – 22 Oct 2026; Completed + Confirmed only, a
return trip counted once) and the Google Ads exports for 1 Mar – 28 Oct 2026. "Web" = ETO sources
*Central Executive Transfers* (website booking form) + *Direct* (ETO direct link). "New" = the customer's
first booking in the export. Raw data stays in `docs/growth/data/` (private); this file holds totals only.

**Read this as a sanity check, not proof:** a web booking may come from Google Ads, SEO, a repeat customer
or word of mouth — old bookings carry no click ID, so they can't be tied to a keyword. From now on the
click-ID capture + offline-conversion download fixes that.

## 1. Every month, real web bookings beat what Ads reported

| Month | Web bookings | New-customer web bookings | Web revenue | Ads spend | Ads "conversions" (calls + tracked web) |
|---|---|---|---|---|---|
| Mar | 33 | 28 | £7,620 | £1,161 | 27 |
| Apr | 40 | 25 | £7,625 | £1,746 | 22 |
| May | 47 | 31 | £9,583 | £1,824 | 39 |
| Jun | 58 | 26 | £11,894 | £1,823 | 45 |
| Jul | 66 | 38 | £15,071 | £1,824 | 55 |
| Aug | 50 | 27 | £11,400 | £1,823 | 28 |
| Sep | 58 | 29 | £11,114 | £1,824 | 39 |
| Oct (to 22nd) | 32 | 15 | £6,020 | £1,598 | 16 |

- ~29 new-customer web bookings a month on ~£1,800 of ads → **at most ~£63 of ads per new web customer**
  (less, since some came from SEO), against ~£190–£230 revenue per web booking. Ads are paying their way.
- Spend was flat Apr–Sep while bookings rose and fell — the swings came from season/demand and SEO, not spend.
- 219 of 384 web bookings (57%) were from new customers; 165 were repeat customers.

## 2. By route — where the bookings really are

| Route (web) | Bookings | New customers | Revenue | Ad group spend | Ad spend per new web booking (upper bound) | What Ads reported |
|---|---|---|---|---|---|---|
| **Manchester (MAN)** | **192** | 116 | £23,807 | £2,634 | £23 | £81 per conversion |
| Local / non-airport | 65 | 45 | £10,873 | (General/Exec/Weddings) | — | — |
| **Heathrow (LHR)** | 46 | 22 | £14,340 | £362 | £16 | 7 conversions |
| East Midlands (EMA) | 31 | 15 | £4,363 | £473 | £32 | — |
| Leeds Bradford (LBA) | 21 | 10 | £3,048 | £218 | £22 | £109 per conversion |
| **Birmingham (BHX)** | 14 | 6 | £2,394 | £111 | £18 | **0 conversions** |
| Liverpool / Humberside / Luton / Stansted / Gatwick | 15 | 5 | £3,110 | £39 (LTN+LGW) | — | — |

**Manchester is half of all web bookings — but more Manchester ad spend did NOT bring more of them.**

| Month | Mar | Apr | May | Jun | Jul | Aug | Sep | Oct |
|---|---|---|---|---|---|---|---|---|
| Manchester ad group spend | £134 | £182 | £149 | £314 | £387 | £511 | £498 | £458 |
| New-customer Manchester web bookings | 16 | 19 | 14 | 11 | 17 | 15 | 16 | 8 |

Spend went up ~3.5× from spring to autumn while new Manchester bookings stayed flat at ~15 a month. The
extra ~£300 a month bought nothing extra (CPCs rose £2.29 → £2.78). → **Keep Manchester on, capped
(£1.20 default) — back towards spring spend — never paused.**

## 3. Where bookings come from by town (pickup end, web)
Sheffield 238 · Doncaster 35 · Rotherham 32 · **Chesterfield 21** · **Barnsley 11** · Dronfield 7 ·
Worksop 6 · Nottingham 5 · Mansfield 2.

## 4. What changes in the plan
1. **Birmingham: re-enable at £0.90** (not paused). Ads saw 0 conversions, but there were 14 real web
   Birmingham bookings (6 new) on just £111 of spend in 8 months.
2. **Leeds Bradford default bid: £1.20** (not £1.00) — 21 real web bookings (10 new) on £218.
3. **Chesterfield + Barnsley keywords: keep on at £0.90** instead of pausing (21 + 11 real web bookings
   from those towns). Revisit when the towns get their own ad groups.
4. **"taxi sheffield to manchester airport" / "taxi from sheffield to manchester airport" (exact): keep on
   at £1.00** instead of pausing — it's the core route; cheap clicks there are worth keeping.
5. **Still paused (data agrees):** "manchester airport transfers" (exact, £236 / 0.5 conv; the phrase
   version stays on), "taxi from manchester airport to sheffield" (exact, 0), "chauffeur sheffield to
   manchester airport" (phrase), "book airport transfer" (exact, no town, £172 / 0), "sheffield chauffeur
   service" (exact; the cheaper phrase versions stay on), "limousines sheffield" ×2 (no limos),
   "taxi to heathrow airport" (phrase, no home town, £61 / 0).
6. **Heathrow at £2.00 is right** — 46 real bookings, the best value route.
