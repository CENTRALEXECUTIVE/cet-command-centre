# Exports needed for the data pass

Download these **before** applying the cleanup, so the "before" picture is clean. On a
tablet, turn on "Request desktop website".

**General rules:**
- **Date range:** **last 90 days** (to yesterday) unless stated. Where it says "also 30d", do a second download for the last 30 days.
- **Format:** Download icon (top right of each table) → **.csv**.
- **Where to put them:** `docs/growth/data/` (git-ignored). Name them like `2026-10-28_search-terms_90d.csv`.
- **Rows:** include paused and removed items. Above each table: filter → Status → **All**, and untick "Hide paused".
- **Columns:** the table → **Columns** icon → Modify columns → tick the ones listed → Apply.

Must-have items are marked **[MUST]**. The rest help.

| # | Report | Path in Google Ads | Columns / settings |
|---|---|---|---|
| 1 **[MUST]** | **Campaigns** (90d + also 30d) | Campaigns → Campaigns | Campaign, Campaign status, Campaign type, Budget, Bid strategy type, Impr., Clicks, CTR, Avg. CPC, Cost, Conversions, Conv. value, Cost / conv., Conv. rate, All conv., All conv. value, Search impr. share, Search lost IS (budget), Search lost IS (rank), Search top IS, Search abs. top IS |
| 2 **[MUST]** | **Campaigns by network** | same table → **Segment → Network (with search partners)** | same columns. Shows partner/Display waste |
| 3 **[MUST]** | **Campaigns by conversion action** | same table → **Segment → Conversions → Conversion action** | Conversions, Conv. value, All conv. Shows *what* has been counted as a "conversion" |
| 4 **[MUST]** | **Ad groups** | Campaigns → Ad groups | Campaign, Ad group, Status, Default max. CPC, Impr., Clicks, Avg. CPC, Cost, Conversions, Conv. value, Cost / conv., Conv. rate |
| 5 **[MUST]** | **Search keywords** | Campaigns → Audiences, keywords and content → **Search keywords** | Keyword, Match type, Campaign, Ad group, Status, Max. CPC, Final URL, Quality Score, Exp. CTR, Ad relevance, Landing page exp., Impr., Clicks, Avg. CPC, Cost, Conversions, Conv. value, Cost / conv., Search impr. share, Search lost IS (rank), **Top of page bid (low range)**, **Top of page bid (high range)** |
| 6 **[MUST]** | **Search terms** (90d **and** longest available, e.g. 12 months) | **Insights and reports → Search terms** | Search term, Match type, Added/Excluded, Campaign, Ad group, Keyword, Impr., Clicks, Avg. CPC, Cost, Conversions, Conv. value |
| 7 **[MUST]** | **Conversion actions** | Goals → Conversions → **Summary** | Download the table (or screenshot every row): Conversion action, Action optimisation (Primary/Secondary), Source, Status, Count, Click-through window, Value settings, All conv. |
| 8 **[MUST]** | **User locations** | Insights and reports → **Report editor** → Predefined reports (Dimensions) → **Geographic → User locations** | Campaign, Location type (**Location of presence / interest**), City, Region, Impr., Clicks, Cost, Conversions. Proves or disproves the interest leak |
| 9 **[MUST]** | **Campaign settings** (screenshots, one per campaign) | Campaign → Settings, with Networks, Locations (+ Location options expanded), Bidding, Ad schedule, AI Max/Broad match and Automatically created assets all opened | Screenshots are fine |
| 10 **[MUST]** | **Auto-apply recommendations** | Campaigns → Recommendations → **Auto-apply** | Screenshot of every tab |
| 11 | **Ads** | Campaigns → Ads | Campaign, Ad group, Ad (headlines/descriptions), Final URL, Ad strength, Status, Policy details, Impr., Clicks, CTR, Cost, Conversions |
| 12 | **Assets** | Campaigns → Assets → **Asset table** (show "Asset-level") | Asset, Asset type, Level, Status, Performance label, Impr., Clicks, Conversions |
| 13 | **Hour of day + Day of week** | Report editor → Predefined → **Time → Hour of day**, then **Day of week** | Campaign, Impr., Clicks, Cost, Conversions, Conv. value |
| 14 | **Devices** | Campaigns → **When and where ads showed → Devices** (or Report editor → Devices) | Campaign, Device, Impr., Clicks, Avg. CPC, Cost, Conversions, Cost / conv. |
| 15 | **Call details** | Report editor → Predefined → **Extensions/Assets → Call details** | Start time, Duration (s), Status (Received/Missed), Area code, Campaign |
| 16 | **Auction insights** | Campaigns → tick the Manchester campaign → **Insights and reports → Auction insights** (repeat per campaign) | Display URL domain, Impression share, Overlap rate, Position above rate, Top of page rate, Outranking share |
| 17 | **Change history** | Tools → **Change history** | Last 90 days, all users. Shows what auto-apply or anyone else changed |
| 18 | **Shared negatives / exclusion lists** | Tools → Shared library → Exclusion lists → Negative keyword lists | Open each list → download/screenshot |

## From outside Google Ads
| # | What | Where |
|---|---|---|
| 19 **[MUST]** | **Command Centre bookings export**, same 90 days, with source/marketing (gclid/utm) columns | Command Centre → Bookings → filter dates → **Export**. Contains PII, so data folder only, never committed |
| 20 | **GA4 landing pages** for paid traffic | GA4 → Reports → Engagement → **Landing page** → add filter Session source/medium = `google / cpc` → 90d → Share → Download CSV |
| 21 | **Billing transactions** (actual money spent) | Google Ads → Billing → **Transactions**, 90d → Download |

## What each export answers
- **1–4:** which campaigns and ad groups booked, and the true cost per booking. Whether "conversions" were real bookings or junk actions.
- **5–6:** where the waste went (search terms) and real CPCs for setting bids. Every irrelevant term goes into the negatives.
- **8–10, 17:** whether interest targeting, partners/Display or auto-apply leaked money.
- **13–15:** schedule, device and call-hour adjustments.
- **16:** who we really compete with.
- **19:** the ground truth. Ads-reported bookings vs real ones, and value per booking.
