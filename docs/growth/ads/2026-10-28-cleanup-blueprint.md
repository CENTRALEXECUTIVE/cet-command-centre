# Google Ads cleanup blueprint — 28 Oct 2026

**Budget:** cut from £60 to £30/day (about £912/month). **Status:** this is a blueprint
built without account data. Once the exports in `exports-needed.md` arrive, the
data pass will confirm or change every number here (bids, budget split, what stays paused).

Files in this folder:
`keywords.csv` (164 exact/phrase keywords + 23 routing negatives) · `negatives.csv` /
`negatives-paste.txt` (269-term shared list) · `ads.csv` (11 RSAs) · `ads-assets.csv`
(sitelinks, callouts, snippets, call) · `ads-charcount.txt` (every line counted by
script, all within limits) · `competitors.md` · `exports-needed.md` · `CHANGELOG.md`.

---

## 1. One-page summary

| | Before | After this blueprint |
|---|---|---|
| Daily budget | £60 | £30 |
| Campaigns live | 9 | 3 |
| Bidding | Maximise clicks | Manual CPC (bids per keyword) |
| Conversion tracking | doubtful / unverified | 1 Primary (web booking, with value) + Secondaries |
| Networks | unknown (suspect partners/Display on) | Google Search only |
| Location | unknown (suspect "presence or interest") | Sheffield + 25 mi, **presence only** |
| Negatives | thin | 269-term shared list + routing negatives |

Spend, bookings, cost per booking and conversion rate by campaign: **not available
yet.** That table is the first thing the data pass will produce.

**What the money has to do.** About £912/month. At an estimated £2–£3 average CPC
that buys about 300–450 clicks. At a 5–8% booking rate that's roughly **15–35 bookings a
month, so £26–£60 per booking**. On a £100 Manchester run that only pays when a
director drives the job. On a cover-driver job the company keeps 10% (£10), so **an ad
booking that goes to a cover driver loses money.** See decision D1.

---

## 2. Critique of the earlier plan (what's right, what's wrong)

Right:
- Pausing what booked nothing, and leaving Maximise clicks. Max clicks buys the
  cheapest clicks, which are the worst ones. It was the main cause of "half the spend booked nothing".
- Manual CPC while tracking is unproven. Tracking first, then smart bidding.
- Using GTM and the `cetBooking` postMessage (with value, separate `quote_request`).

Wrong or missing:
1. **Spread too thin.** £3/day for Executive and £3/day for Heathrow is 1–2 clicks a
   day. That's about one booking a month, so you'd never learn anything, and the
   budget alone keeps those campaigns "limited by budget". Five campaigns on £30 is too many.
   **Fix:** three campaigns. Budget pools where the searches are.
2. **General got the biggest share (£13) and nobody knows what's in it.** In this vertical
   "General" usually means broad "taxi sheffield" / "taxi near me" / "taxi number". Those
   are people wanting a £8 hop across town. **Fix:** General stops. Its useful airport
   intent moves into a "Sheffield Airport Transfers" ad group with exact/phrase keywords.
   The search-terms export will show how much General wasted.
3. **Settings leaks not covered at all:** location "presence or interest", Search
   Partners, Display expansion, auto-applied recommendations (these quietly add broad
   keywords and raise budgets), automatically created assets, AI Max / final-URL
   expansion. Together these often waste more than any one campaign.
4. **No landing-page mapping.** Ads must land on the matching page. **Manchester, the
   biggest route, has no dedicated page in Google's index** (see §4). Meanwhile East
   Midlands, Heathrow, Leeds Bradford and Birmingham all have one.
5. **"Maximise conversions 2 weeks after tracking is clean" is too early.** Switch on
   volume, not on time: **15+ tracked bookings in the last 30 days** (and the numbers
   matching the Command Centre's own count).
6. **The conversion fires on booking *request*, not on payment.** Fine for now, but some
   card bookings never pay. Bidding will learn from requests. Month 2: offline-import
   *paid* bookings (the widget already stores the gclid on every booking).
7. **Pausing Leeds Bradford / Birmingham / Gatwick outright throws away £150–£350 jobs.**
   Better: pause those *campaigns* (agreed) but keep a few **exact-match** Gatwick,
   Stansted, Luton, Liverpool and Birmingham keywords inside the generic airport ad
   group. Those are high-value, low-volume and cheap to test. Leeds Bradford keywords are
   loaded as **Paused** until the data says otherwise.
8. **No target cost per booking.** Without one, "pause losers" has no threshold.
   Proposed until D1 is answered: **£30 per one-way booking, £60 for London airports**.
   Pause rule: an ad group that has spent **£60 (2 × £30)** with no booking gets reviewed.

---

## 3. Target account structure

Three Search campaigns. Reuse existing campaigns where possible so their history stays
in one place (see §6 step 6).

| Campaign (budget/day) | Ad group | Landing page (Final URL) |
|---|---|---|
| **CET \| Manchester Airport** (£11) | Sheffield to Manchester Airport (incl. Rotherham/Barnsley/Chesterfield/Doncaster → MAN) | `/sheffield-to-manchester-airport-transfers` (the page EXISTS — uploaded in the Oct SEO batch, just not indexed yet; request indexing first) |
| **CET \| Airports & Towns** (£11) | Sheffield to East Midlands Airport | `/sheffield-to-east-midlands-airport-transfers` |
| | Sheffield to Heathrow | `/sheffield-to-heathrow-airport-transfers` |
| | Sheffield Airport Transfers (generic + exact Gatwick/Stansted/Luton/Liverpool/Birmingham; LBA paused) | `/airport-transfers-sheffield` (Birmingham kw → `/sheffield-to-birmingham-airport-transfers`, LBA kw → `/sheffield-to-leeds-bradford-airport-transfers`) |
| | Rotherham Airport Transfers | `/rotherham-airport-transfers` |
| | Barnsley Airport Transfers | `/barnsley-airport-transfers` |
| | Chesterfield Airport Transfers | `/chesterfield-airport-transfers` |
| | Doncaster Airport Transfers | `/doncaster-airport-transfers` |
| **CET \| Chauffeur & Corporate** (£8) | Executive Chauffeur | `/executive-chauffeur-sheffield` |
| | Corporate & Business Travel | `/corporate-chauffeur-sheffield` (travel kw → `/corporate-travel-sheffield`) |
| | V-Class & Minibus | `/mercedes-vclass-hire-sheffield` (minibus kw → `/minibus-hire-sheffield`) |

All URLs are on `https://centralexecutivetransfers.co.uk`. Paused (not removed):
General, Weddings, Leeds Bradford, Birmingham, Gatwick, plus whichever old campaigns
are not reused.

Routing negatives (in `keywords.csv`) stop the campaigns competing with each other:
- "manchester airport" is negative in Airports & Towns, so Manchester searches go to the Manchester campaign.
- Town and airport names are negative in the generic ad group, so they go to their own ad group.
- "airport" is negative in Executive Chauffeur.
- wedding/prom are negative everywhere while Weddings is paused.
- "car hire" is negative in the airport campaigns only (it's a valid chauffeur phrase).

---

## 4. Landing pages (found via Google's index; the live site itself was NOT accessed)

The live site couldn't be fetched from this session (network block), and per CLAUDE.md
it isn't touched anyway. These pages are confirmed in Google's index:

`/` · `/booking` · `/airport-transfers-sheffield` · `/sheffield-to-east-midlands-airport-transfers` ·
`/sheffield-to-heathrow-airport-transfers` · `/sheffield-to-leeds-bradford-airport-transfers` ·
`/sheffield-to-birmingham-airport-transfers` · `/rotherham-airport-transfers` ·
`/barnsley-airport-transfers` · `/chesterfield-airport-transfers` · `/doncaster-airport-transfers` ·
`/executive-chauffeur-sheffield` · `/corporate-chauffeur-sheffield` · `/corporate-travel-sheffield` ·
`/event-chauffeur-sheffield` · `/minibus-hire-sheffield` · `/mercedes-vclass-hire-sheffield` ·
`/prom-wedding-chauffeur-sheffield` · `/terms-and-conditions` (19 of the ~25).

**Mismatches to fix:**
- **No Sheffield → Manchester Airport page indexed.** Manchester is the biggest route.
  Director: open `centralexecutivetransfers.co.uk/sheffield-to-manchester-airport-transfers`
  on the tablet. If it loads, change the Manchester ad's Final URL to it. If not, ask the
  seo-local-search agent for that page as an upload file (top SEO priority). Until then
  the ad goes to `/airport-transfers-sheffield`.
- Gatwick, Stansted, Luton and Liverpool have no indexed route pages. They use the
  generic airport page for now.
- **Facts on the site that BUSINESS_FACTS marks `[CONFIRM]`.** The live pages claim:
  "Sheffield City Council licensed", "DBS-checked", "5.0 from 100+ Google reviews", "24/7".
  If true, these are strong ad angles. Confirm them and update BUSINESS_FACTS (decision D3).
  Until then the ads don't use them.
- **Site prices vs BUSINESS_FACTS.** The site shows Sheffield → MAN £100 / 8-seater £140,
  EMA £100 / £140 / V-Class £165, Heathrow £290 / £340 / £450, LBA £100, Birmingham £150,
  Liverpool £150, Luton £250, Stansted £280, Gatwick £350. BUSINESS_FACTS only lists
  Barnsley-origin examples and says "quote 'from' prices only after directors confirm"
  (D2). So **no prices are in the ads yet**. Ready-to-use price lines are in §9.

---

## 5. Settings checklist (every campaign, exact UI path)

Google Ads web UI. Left menu: **Campaigns** icon → pick campaign → **Settings**.

| Setting | Value | Where |
|---|---|---|
| Campaign type | Search | (fixed) |
| Networks | **Untick "Include Google search partners"** and **untick "Include Google Display Network"** | Settings → Networks |
| Locations | **Sheffield + 25 mile radius** (pin on Sheffield city centre; covers Rotherham, Barnsley, Doncaster, Chesterfield, Worksop) | Settings → Locations → Advanced search → Radius → "Sheffield, UK" 25 mi → Save |
| Location options | **"Presence: People in or regularly in your included locations"** (NOT "Presence or interest") | Settings → Locations → **Location options** (expand) → Target |
| Location exclude option | "Presence: people in your excluded locations" | same panel → Exclude |
| Languages | English | Settings → Languages |
| Bidding | **Manual CPC**. No enhanced CPC: Google retired it for Search, so it won't be offered | Settings → Bidding → Change bid strategy → "Manual CPC" |
| Budget | £11 / £11 / £8 (see §7) | Settings → Budget |
| Ad schedule | **Every day 05:00–24:00**, no bid adjustments yet | Settings → Ad schedule → Add → All days 05:00 to 00:00 |
| Ad rotation | Optimise | Settings → More settings → Ad rotation |
| Broad match keywords setting | **Off** | Settings → "Broad match keywords" / AI Max → Off |
| AI Max for Search / final URL expansion | **Off** | Settings → AI Max (if shown) → Off |
| Automatically created assets | **Off** (text and final URL) | Settings → More settings → Automatically created assets → untick both |
| Goals | "Use account-level goals" (Primary = CET web booking only) | Settings → Goals |
| Devices | All, **no adjustments** until 14 days of data | Campaign → "When and where ads showed" → Devices |
| Audiences | Observation only, none required | — |
| Start/end dates | None | — |

Account-level (do once):
- **Auto-apply recommendations OFF:** left menu **Campaigns → Recommendations →
  Auto-apply** (top right) → untick **every** box under every tab → Save. This is
  the hidden one. It adds broad keywords and raises budgets without asking.
- **Shared negative list:** Tools → Shared library → **Exclusion lists → Negative keyword
  lists** → + → name `CET Master Negatives` → paste `negatives-paste.txt` → Save → tick the
  list → **Apply to campaigns** → all three.
- **Assets** (account level): Campaigns → Assets → + → Sitelink / Callout / Structured
  snippet / Call, from `ads-assets.csv`. **Call asset:** 0114 399 8242, call reporting ON,
  conversion action "Calls from ads" (Secondary, counts calls ≥ 60 s), scheduled to
  office hours only (D4). **Location asset:** link the Google Business Profile
  (Assets → + → Location → link GBP account).
- **Why 05:00–24:00:** airport searches run in the evenings and on Sundays, and people
  book early-morning flights the night before. 00:00–05:00 is mostly stray/late-night
  local taxi searches. The booking form works any time, but the phone doesn't
  (24/7 is `[CONFIRM]`). Revisit with the hour-of-day export.
- **Presence only means people abroad searching "manchester airport to sheffield taxi"
  before flying home won't see the ad.** That's deliberate for now: interest targeting is the
  classic leak. Once tracking is clean, test a small inbound-only exact-match ad group
  with "presence or interest" (week 4+).

---

## 6. Order to do things (each step is safe on its own)

**Before anything — 15 minutes:** download the exports in `exports-needed.md`
(90 days) and drop them in `docs/growth/data/`. Changing things first muddies the "before".

**Day 0 — stop the leaks (30 min, tablet OK):**
1. Auto-apply recommendations → all off.
2. Every campaign: Search partners off, Display off, location option → Presence only, radius 25 mi.
3. Pause **General, Weddings, Leeds Bradford, Birmingham, Gatwick**.
4. Remaining campaigns: bid strategy → Manual CPC. Budgets per §7 (old names map:
   Manchester £11, East Midlands £11, Executive £8; Heathrow paused, since it moves into Airports & Towns).

**Day 0–1 — conversion tracking (§8).** Nothing below depends on smart bidding, so
the cleanup can carry on while tracking proves itself.

**Day 1–2 — negatives:** create `CET Master Negatives`, apply it to all campaigns.

**Day 2–3 — rebuild:**
5. Rename **Manchester → `CET | Manchester Airport`**, **East Midlands → `CET | Airports & Towns`**,
   **Executive → `CET | Chauffeur & Corporate`** (Settings → campaign name). The CSVs then
   import straight into them.
6. In those three campaigns **pause every existing keyword and ad** (Keywords → tick all →
   Edit → Pause; Ads → same). Don't delete them, the history stays.
7. Import `keywords.csv` then `ads.csv` (Ads Editor on a laptop: Account → Import →
   From file → review → Keep → Post). **Tablet only:** Tools → Bulk actions →
   **Uploads** → + → upload the CSV → Preview → Apply. If either one rejects a column,
   delete that column and retry. Note: Ads Editor and Uploads create a campaign if the
   name doesn't match exactly, using default settings (partners and Display ON). **The
   names must match character for character.**
8. Heathrow campaign → pause (its keywords now live in Airports & Towns).
9. Add account assets from `ads-assets.csv`.

**Day 3:** check every ad is "Eligible", not "Disapproved" / "Destination not working".

---

## 7. Budget split for £30/day — and why

| Campaign | £/day | Why |
|---|---|---|
| CET \| Manchester Airport | **£11** | Biggest-volume route from South Yorkshire. Competitors price it at £90–£100, so it's a fight. Own budget so it can't starve or be starved. |
| CET \| Airports & Towns | **£11** | EMA (second route), Heathrow (£290+ jobs, one booking pays for days), generic airport searches that pick up Gatwick/Stansted/Luton (£250–£350), and the four town pages. Pooled so low-volume ad groups don't each need their own budget. |
| CET \| Chauffeur & Corporate | **£8** | Low search volume but the highest lifetime value (an account client books again and again). If it can't spend £8, the leftover is not "saved". Move it weekly to whichever campaign shows "Limited by budget" with the best cost per booking. |

Starting max CPCs are in `keywords.csv`:
- Exact: MAN £2.50, EMA £2.00, Heathrow £3.00, generic £2.00, towns £1.60, exec £2.00, corporate £2.50, groups £1.80.
- Phrase bids are 15% lower.
- These are guesses until the keyword export shows real CPCs and top-of-page estimates. **Never raise a bid
  on a keyword with no booking until it has had 30+ clicks.**

---

## 8. Conversion tracking in GTM — tablet, screen by screen

What you're building: the booking form on the website (an embedded Command Centre
widget) sends a message `cetBooking` when a booking is placed. A small listener in GTM
picks it up, and Google Ads records a conversion with the job value and booking reference.

**Tablet tips:** use Safari/Chrome with **"Request desktop website"** on. Allow pop-ups
for tagmanager.google.com and tagassistant.google.com (iPad: Settings → Safari → Block
Pop-ups OFF while you do this).

### Part A — make the conversion actions in Google Ads (tab 1)
1. ads.google.com → left menu **Goals** → **Conversions** → **Summary**.
2. **Look at the existing list first.** Write down every action and whether it says
   Primary. You'll fix them in Part D.
3. **+ Create conversion action** → **Website** → type `centralexecutivetransfers.co.uk` → **Scan**.
4. Scroll to **"Create conversion actions manually using code"** → **+ Add a conversion action manually**.
5. Fill in:
   - Goal and action optimisation: **Purchase**, **Primary**
   - Conversion name: `CET web booking`
   - Value: **Use different values for each conversion**, default £100
   - Count: **Every** (each booking is revenue; the booking ref stops duplicates)
   - Click-through window: **30 days**. Engaged-view: 3 days. View-through: 1 day
   - Attribution: **Data-driven**
   - → **Done** → **Save and continue**.
6. Choose **"Use Google Tag Manager"**. Write down the **Conversion ID** (digits only)
   and the **Conversion label**.
7. Repeat steps 3–6 three more times, each set to **Secondary**, with Count = **One**:
   - `CET quote request` (category Request quote, value: don't use a value)
   - `CET website phone tap` (category Contact)
   - `CET WhatsApp tap` (category Contact)
   - Note each label.

### Part B — build it in GTM (tab 2)
1. tagmanager.google.com → your account → container **GTM-WHH77L45** → Default Workspace.
2. **Variables** → Built-In Variables → **Configure** → tick **Click URL** → close.
3. **Variables** → User-Defined → **New** → Variable Configuration → **Data Layer Variable**
   → Data Layer Variable Name `cet_value` → name it `DLV - cet_value` → **Save**.
   Repeat for `cet_ref` (`DLV - cet_ref`) and `cet_currency` (`DLV - cet_currency`).
4. **Tags** → **New** → name `CET - booking listener` → Tag Configuration → **Custom HTML** →
   paste exactly this:

   ```html
   <script>
   (function () {
     if (window.__cetListen) return; window.__cetListen = true;
     window.addEventListener('message', function (e) {
       var host = ''; try { host = new URL(e.origin).hostname; } catch (x) { return; }
       if (!/(^|\.)centralexecutivetransfers\.co\.uk$/.test(host)) return;
       var b = e.data && e.data.cetBooking; if (!b) return;
       window.dataLayer = window.dataLayer || [];
       window.dataLayer.push({
         event: b.event === 'quote_request' ? 'cet_quote_request' : 'cet_booking',
         cet_value: Number(b.value) || 0,
         cet_currency: b.currency || 'GBP',
         cet_ref: b.ref || ''
       });
     });
   })();
   </script>
   ```
   Triggering → **All Pages** → **Save**.
5. **Triggers** → **New** → name `CE - cet_booking` → **Custom Event** → Event name `cet_booking`
   → All Custom Events → **Save**. Repeat as `CE - cet_quote_request` with event name `cet_quote_request`.
6. **Triggers** → **New** → `Click - phone` → **Click - Just Links** → Some Link Clicks →
   `Click URL` **starts with** `tel:` → Save. Again: `Click - WhatsApp` → Just Links → Some
   Link Clicks → `Click URL` **matches RegEx** `wa\.me|whatsapp\.com` → Save.
7. **Tags** → check whether a **Conversion Linker** tag exists. If not: New → Conversion
   Linker → trigger All Pages → Save. (It keeps the ad-click ID so bookings get credited.)
8. **Tags** → **New** → `Google tag - Ads` → **Google Tag** → Tag ID `AW-` + your Conversion
   ID → trigger **Initialization - All Pages** → Save. (Skip this if a Google tag with the AW- ID already exists.)
9. **Tags** → **New** → `Ads - CET web booking` → **Google Ads Conversion Tracking** →
   Conversion ID, Conversion Label (booking), **Conversion Value** `{{DLV - cet_value}}`,
   **Transaction ID** `{{DLV - cet_ref}}`, **Currency Code** `{{DLV - cet_currency}}` →
   trigger `CE - cet_booking` → Save.
10. Same again three times:
    - `Ads - CET quote request` (quote label, trigger `CE - cet_quote_request`, no value)
    - `Ads - phone tap` (phone label, trigger `Click - phone`)
    - `Ads - WhatsApp tap` (WhatsApp label, trigger `Click - WhatsApp`)
11. **Preview** (top right) → enter `https://centralexecutivetransfers.co.uk/booking` →
    **Connect** → the site opens in a new tab → go back to the Tag Assistant tab. Under
    "Tags Fired" you should see **CET - booking listener**, **Conversion Linker** and
    **Google tag - Ads**. Tap the site's phone/WhatsApp link and check the matching tag fires.
    **Also look for any other `AW-` tag on the page that isn't from GTM.** An old pasted
    snippet would double-count bookings. Tell the agent if you see one.
12. **Submit** → Version name `Ads conversions - cetBooking 28 Oct` → **Publish**.

### Part C — proving the booking conversion (no test bookings on the live site)
CLAUDE.md forbids test bookings on the live site. So:
- Wait for the first real web booking. Within 24–48 h, Goals → Conversions should show
  **CET web booking: "Recording conversions"**. Compare the count each week with web
  bookings in the Command Centre (the ones with a gclid).
- If nothing records after 3 real web bookings, the likely cause is the widget domain.
  The listener only accepts messages from `*.centralexecutivetransfers.co.uk`. Tell the
  agent which domain the booking form loads from.
- Optional (D5): a director takes a **real** phone booking and enters it through the
  website form themselves while in Preview mode. That's real data, not test data.

### Part D — Primary vs Secondary
Goals → Conversions → Summary → open each action → **Edit settings** → Action optimisation:
- **Primary:** `CET web booking` only.
- **Secondary:** quote request, phone tap, WhatsApp tap, **Calls from ads**, and **every
  old action** (page views, GA4 imports like `session_start`/`page_view`, old "booking"
  tags). If an old action duplicates the new one, set it Secondary first and remove it 30 days later.

**Known gaps (for the ops/product agents, not the director):**
1. The mini price checker's "Book this journey" opens WhatsApp **inside the iframe**, so GTM can't see that tap.
2. Card bookings redirect to payment after 0.7 s. The tag normally fires in time, but check the
   Ads count against the Command Centre.
3. If the site has a cookie banner, it needs Consent Mode v2, or UK conversions will be under-counted.

---

## 9. Pending price lines (use only after D2 confirms them)

Prices are the strongest angle competitors use (£90–£100 to MAN). Once confirmed, add
these as headlines and as a **price asset** (Assets → + → Price → Type "Services",
qualifier "From"):

| Headline | Chars |
|---|---|
| Manchester Airport from £100 | 28 |
| East Midlands Airport £100 | 26 |
| Heathrow from £290 | 18 |
| Fixed £100 to Manchester Apt | 28 |

The price must match the landing page exactly. If the page says £100 and the ad says
"from £100", that's fine. Never show a price the page doesn't.

---

## 10. What to check after 7 days

1. **Tracking:** CET web booking shows "Recording". Ads count ≈ Command Centre web
   bookings with a gclid (within ±1).
2. **Search terms** (Insights and reports → Search terms): every irrelevant term goes into
   `CET Master Negatives`. Any converting term not yet a keyword becomes an exact keyword.
3. **Spend pacing:** ~£210 for the week. If a campaign hasn't spent 60% of budget, raise
   its top bids 10–15%. If "Limited by budget" and searches are relevant, move budget from
   the underspender.
4. **Avg CPC vs max CPC** and **Search lost IS (rank)** per ad group. If lost to rank > 50% on
   MAN/EMA exact, raise those bids 15%.
5. **Disapprovals / "destination not working"** = 0.
6. **Location report** (user locations): 100% "Location of presence". Any "Location of
   interest" means the setting didn't save.
7. Calls from ads: count and length.

## 11. What to check after 14 days

1. **Cost per booking by campaign and ad group** vs £30 (£60 London).
   - Any ad group with £60+ spent and 0 bookings: pause it, or cut its bids 30% if the search terms look right.
2. **Bidding switch:** if 15+ tracked bookings in 30 days and the counts match the Command Centre, move
   **CET | Manchester Airport** (or the whole account if it's pooled) to **Maximise conversions**,
   no target, for 2 weeks. Then **Target CPA** at the actual average. Not before.
3. **Hour/day and device** reports (14 days is the minimum). Only adjust where a
   segment has 20+ clicks and clearly better or worse cost per booking. Expect evening/Sunday +15–20%.
4. **RSA asset report:** replace any headline rated "Low" with a fresh variant (keep char limits).
5. **Budget shift:** move Chauffeur & Corporate underspend to the campaign with the best cost per booking.
6. **Decide on paused campaigns** with the export data (Leeds Bradford keywords, Weddings).
7. If Google is clean (tracking verified, CPA stable): **Microsoft Ads**. Import from
   Google (Microsoft Ads → Import → Google Ads), at about 30% of Google bids. Business
   travellers, older desktop users, cheaper clicks. Same presence-only check, since
   Microsoft defaults to "people in or interested in". Start at £5/day *on top* only if
   the directors approve the extra spend.

---

## Decisions needed from the directors

- **D1 Target cost per booking.** What's the most you'll pay in ads for a £100 Manchester
  booking? Also: should ad-won jobs go only to directors, not cover drivers? At 10%
  margin a cover job can't carry a £30 ad cost.
- **D2 Prices in ads.** Confirm Sheffield → MAN £100, EMA £100, Heathrow £290 (site
  prices) so they go in headlines and price assets.
- **D3 Site claims.** Confirm "Sheffield City Council licensed", "DBS-checked", "5.0 / 100+
  Google reviews", "24/7", free waiting time and cancellation terms. Each one confirmed
  is a better ad. Update BUSINESS_FACTS.
- **D4 Office phone hours** for the call asset.
- **D5 Real booking through the site in GTM Preview** to verify tracking (instead of a test booking).
- **D6 Competitor-brand negatives** (uber, bolt, veezu, city taxis …). Included in the list.
  Remove that block if you'd rather show on them.
