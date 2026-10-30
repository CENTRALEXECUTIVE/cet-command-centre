# Google Ads walkthrough — Android tablet, Chrome (29 Oct 2026)

For a director doing the corrected plan (`2026-10-29-action-plan.md`). This replaces the
"Day 0–3" steps in the 28 Oct blueprint, **which wrongly said to pause General. Don't.**

**Rules for every step:**
- One step at a time. Each step is safe on its own, so you can stop after any of them.
- Do the "What you should see" check before moving on. If you see something else, stop and
  send a screenshot.
- Never delete anything. Pause instead.
- Never press **Apply** on a Google suggestion ("Turn on AI Max", "Raise budget", "Use broad match").

**Set-up (once):** in Chrome open `ads.google.com` → tap **⋮** (top right) → tick **Desktop site**.
Turn the tablet to landscape. Zoom with two fingers if the text is small. For the upload files: get
them onto the tablet (sent by the agent, or downloaded from the repo) so they're in **Downloads**.

---

## DAY 1 (about 60–90 min in total, can be split)

### Step 1 — Look before you touch (5 min, read-only)
1. Left menu **Campaigns** → tap **RT-Search-CAMP JAN26** → **Settings**.
2. Screenshot the **Bidding**, **Networks** and **Locations** sections (expand **Location options**).
3. Left menu **Goals** → **Summary** → tap the action listed under **Purchase**. Screenshot its details
   (Source, Status, Last conversion, Webpages/Tag setup).
4. Same for the action under **Phone call lead**.

✅ *You should see:* the bid strategy name (e.g. "Maximise clicks", "Maximise conversions" or "Manual
CPC"), the location option wording, and what the Purchase action is. **Send the screenshots to the
agent.** They decide two things later in the plan.

### Step 2 — Turn off auto-apply (2 min)
1. Left menu **Campaigns** → **Recommendations**.
2. Top right, tap **Auto-apply**.
3. On every tab, **untick every box** → **Save**.

✅ *You should see:* "Auto-apply: Off" or no ticked boxes on any tab. (The change history shows Google
hasn't applied anything since August. This keeps it that way.)

### Step 3 — Close the settings leaks (10 min)
**Campaigns → RT-Search-CAMP JAN26 → Settings:**
1. **Networks** → untick **Include Google search partners** → untick **Include Google Display Network** → **Save**.
2. **Locations** → if it isn't already "Sheffield + 25 mi": **Edit** → **Advanced search** → **Radius** →
   type `Sheffield, UK` → **25 mi** → **Target** → remove any other targeted location (e.g. "United Kingdom")
   → **Save**.
3. **Locations → Location options** → Target: **"Presence: People in or regularly in your included
   locations"** → **Save**.
4. **More settings → Automatically created assets** → turn **off** text assets, final-URL/dynamic sitelinks
   and dynamic images → **Save**.
5. If you see **"Broad match keywords"** or **"AI Max for Search"** → **Off** → **Save**.

✅ *You should see:* Networks = "Google Search Network". Location = Sheffield (+25 mi). Option = "Presence".
Automatically created assets: Off.

### Step 4 — Bid strategy to Manual CPC and default bids (10 min)
Confirmed 30 Oct: the campaign is on Maximise conversions, so do this step. Reasoning and sources:
`2026-10-30-bidding-decision.md`. **The bids below were corrected on 30 Oct.**
1. **Settings → Bidding → Change bid strategy** → tap **"Or, select a bid strategy directly (not recommended)"**
   → **Manual CPC** → **Save**. (Enhanced CPC was retired for Search in 2025; if a tickbox appears, leave it off.)
2. Left menu **Campaigns → Ad groups**. For each row, tap the **Default max. CPC** cell (the pencil) and
   type the value → **Save**:

| Ad group | Default max CPC |
|---|---|
| General Airport Transfers | 1.40 |
| Manchester Airport Transfers | **1.20** |
| Executive Chauffeur / Corporate | 1.60 |
| East Midlands Airport Transfers | 1.30 |
| Heathrow Airport Transfers | **2.00** |
| Gatwick Airport Transfers | 1.50 |
| Luton Airport Transfers | 1.50 |
| Leeds Bradford Airport Transfers | **1.00** |
| Weddings & Events Chauffeur | **0.80** |

✅ *You should see:* Bid strategy "Manual CPC" in Settings, and the numbers above in the Default max. CPC column.

### Step 5 — Booking conversion in GTM (45 min) — URGENT
Follow **`2026-10-29-conversion-tracking-fix.md` §4 then §5 Parts A, B and C**, exactly as written.
Leave Primary/Secondary on the old actions as they are for now (that's Step 12).

✅ *You should see:* in GTM Preview on `/booking`, **CET - booking listener**, **Conversion Linker** and
**Google tag** under "Tags Fired". The version is published. In Google Ads Goals, **CET web booking**
listed (it says "Unverified" or "No recent conversions" until the first real booking).

### Step 6 — Upload the keyword bids and pauses (10 min)
File: **`keywords-upload-step1-bids-pauses.csv`** (110 bid changes, 23 pauses: Birmingham's keywords,
5 Manchester losers, 4 General losers, the "limousines" keywords and one Executive keyword).
1. Left menu **Tools** → **Bulk actions** → **Uploads** → blue **+**.
2. **Upload file** → pick the file from Downloads → **Preview**.
3. Read the preview summary. If it complains about a column name, rename the column in Google Sheets
   (`Criterion Type` → `Match type`, or `Max CPC` → `Max. CPC`) and upload again.
4. **Apply**.

✅ *You should see:* "133 changes, 0 errors" (warnings are fine). Then **Keywords**, sorted by **Max. CPC**:
`airport transfers sheffield` (exact) shows **£1.33**, and `manchester airport transfers` (exact) shows **Paused**.

### Step 7 — Pause Birmingham, tablet −50% (3 min)
1. **Campaigns → Ad groups** → tick **Birmingham Airport Transfers** → **Edit → Pause**.
2. **Campaigns → RT-Search-CAMP JAN26** → left sub-menu **Devices** (may be under "Insights and reports"
   or "When and where ads showed") → **Tablets** row → **Bid adj.** pencil → **Decrease by 50%** → **Save**.

✅ *You should see:* Birmingham "Paused". Tablets bid adj. "−50%". Mobile and Computers untouched.

### Step 8 — Negative keywords (15 min)
**8a. The shared list:**
1. **Tools → Shared library → Exclusion lists** (or "Negative keyword lists").
2. If **CET Master Negatives** exists, open it. If not, **+** and name it exactly that.
3. Open **`negatives-data-paste.txt`**, copy everything between `START PART A` and `END PART A` (360 lines),
   paste into the box → **Save**.
4. Tick the list → **Apply to campaigns** → **RT-Search-CAMP JAN26** → **Save**.

✅ *You should see:* the list showing 360 keywords and "1 campaign".

**8b. Remove the 3 negatives that block good searches:**
1. **Campaigns → Keywords → Negative keywords**. Use the search/filter box for each of the three in turn:
   `sheffield airport transfers`, `airport taxis doncaster`, `taxi manchester airport to sheffield`.
2. Tick each one → **Remove**. Only those three. If one is in a shared list rather than the campaign, open
   that list and remove it there.

✅ *You should see:* none of the three left in the negative keyword list. (The positive keywords with the same
words stay **Enabled** in the Keywords tab.)

---

## DAY 2 (about 30 min)

### Step 9 — Create the Brand ad group (15 min)
1. **Campaigns → Ad groups → +** → campaign **RT-Search-CAMP JAN26**.
2. Ad group name: `Brand` (exactly). Default bid **1.00**.
3. Keywords box, type these 5 lines:
   ```
   [central executive transfers]
   "central executive transfers"
   [central executive travel]
   [central executive cars]
   [centralexecutivetransfers]
   ```
4. Ad: **Responsive search ad**. Final URL `https://centralexecutivetransfers.co.uk/`. Copy the 15 headlines
   and 4 descriptions from the **Brand** row of `ads-v2.csv` (open it in Google Sheets). **Pin** headline
   "Central Executive Transfers" to **position 1** (the pin icon). Path: `Book-Online` / `Fixed-Prices`.
5. **Save**.
6. **Keywords** → search `central executive transfers` → tick the **phrase** one in **General Airport
   Transfers** → **Edit → Pause**.
7. **Tools → Bulk actions → Uploads → +** → **`negatives-upload-adgroup.csv`** → Preview → Apply. This adds
   `"central executive"` to every other ad group, plus 5 exact negatives in Manchester.

✅ *You should see:* the Brand ad group Enabled with 5 keywords and 1 ad "Eligible"/"Under review". The old
brand keyword in General Paused. Preview showing "15 changes".

### Step 10 — New ads alongside the old ones (15 min)
1. **Tools → Bulk actions → Uploads → +** → **`ads-v2-upload-week1.csv`** → **Preview** → **Apply**.
   (If the Brand row says the ad already exists, that's fine.)
2. If the upload refuses RSAs, add them by hand instead: each ad group → **Ads → + → Responsive search ad** →
   copy that ad group's row from `ads-v2.csv`.
3. **Do not pause the old ads.** Both run, and the weaker one gets paused in 14 days.

✅ *You should see:* each ad group with 2 ads, the new one "Eligible" or "Under review" within a day. Any
"Disapproved" → screenshot it to the agent.

---

## DAY 3 (10 min)

### Step 11 — Assets
Campaign → **Assets**:
1. **Call:** decide D7 (which number). To change it: tap the call asset → edit the number to `0114 399 8242`
   (or keep the mobile) → **Call reporting ON** → Save.
2. **Callouts:** remove "24/7 Availability" (unless D3 confirms it), "Trusted by Professionals" and "Executive
   Mercedes Fleet". Add "VAT Invoices", "Live Driver Tracking" and "Licensed Operator OP037".
3. **Sitelinks:** edit "Airport Prices" so its Final URL is
   `https://centralexecutivetransfers.co.uk/airport-transfers-sheffield`. Add the Manchester, East Midlands,
   Corporate Accounts and Groups & V-Class sitelinks (texts and URLs in `ads-assets.csv`. Manchester isn't in that file, so use
   text `Manchester Airport`, lines `Fixed price from Sheffield` / `Flight tracking on pickups`, URL
   `https://centralexecutivetransfers.co.uk/sheffield-to-manchester-airport-transfers`). If the "Contact Us" sitelink says "Available 24 Hours, 7
   Days a Week", change that line unless D3 confirms it.

✅ *You should see:* all assets "Eligible", and no Google AI sitelinks being added any more (Step 3 turned them off).

---

## DAY 7 — check (15 min)

### Step 12 — Did it work? Then demote calls
1. **Goals → Conversions:** is **CET web booking** "Recording conversions"? Count against the Command Centre's web
   bookings for the week.
2. **Only if CET web booking has recorded 3 or more real bookings:** open the **Phone call lead** action
   (Calls from ads) → **Edit settings → Action optimisation: Secondary** → Save. Do the same for the old
   **Purchase** action if it isn't CET web booking. Then open **Get directions**, **Engagement**, **Page view**
   and the **Submit lead form** action → each **Secondary** (tracking-fix §3).
3. **Campaigns** (date range: last 7 days): spend ≈ £210? Avg CPC under £1.60? Ad groups sorted by **Cost**:
   is Manchester under 15% of spend?
4. **Insights and reports → Search terms** (last 7 days): send the export to the agent.

✅ *You should see:* CET web booking Recording, spend about £30/day, avg CPC down from £2.20.

---

## WEEK 2 — towns split (about 30 min, after Step 12)

### Step 13 — Rotherham, Barnsley, Doncaster, Chesterfield ad groups
For each town, in turn:
1. **Ad groups → +** → campaign RT-Search-CAMP JAN26 → name exactly `Rotherham Airport Transfers` (then
   `Barnsley Airport Transfers`, `Doncaster Airport Transfers`, `Chesterfield Airport Transfers`). Default bid
   **1.00** (Barnsley 0.90).
2. Keywords: type just one, e.g. `[airport taxi rotherham]`. The upload adds the rest.
3. Ad: copy that town's row from `ads-v2.csv` (Final URL is the town page, e.g. `/rotherham-airport-transfers`)
   → Save.

Then:
4. **Uploads → + → `keywords-upload-step3-towns.csv`** → Preview → Apply. It adds the town keywords to their new
   ad groups **and pauses the same keywords in General**. "Already exists" warnings for the one you typed are fine.

✅ *You should see:* 4 new ad groups, each with keywords and 1 ad. In General, those town keywords are Paused.
General itself stays **Enabled**.

---

If anything looks wrong at any step: **stop, screenshot, send it.** Nothing here needs to be fixed in a hurry.
Pausing is always reversible.
