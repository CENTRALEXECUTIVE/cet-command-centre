# Conversion tracking fix — 29 Oct 2026 (URGENT)

**Why urgent:** Google Ads' "conversions" are phone calls from the ad plus one "Purchase" action of
unknown origin. Since about **25–28 Oct the `/booking` page runs the new CET widget**, and its booking
message (`cetBooking`) is **not connected to Google Ads**. If "Purchase" was the old ETO confirmation
(likely), **web bookings are currently invisible to Google Ads.** This replaces §8 of
`2026-10-28-cleanup-blueprint.md` (same build, corrected to the widget's real message and the real goals).

---

## 1. What's there now (from the Goals screenshot)

| Goal | Account default? | Campaigns | Primary actions | Status |
|---|---|---|---|---|
| Purchase | Yes | 1 of 3 | 1 (unknown, probably ETO) | Active |
| Phone call lead | Yes | 3 of 3 | 1 (almost certainly "Calls from ads", the call asset on +44 7405 172435) | Active |
| Submit lead form | No | 0 of 3 | 0 | **Misconfigured** |
| Contact | No | 0 of 3 | 2 | Active |
| Get directions | No | 0 of 3 | 1 | Active |
| Engagement | No | 0 of 3 | 1 | Active |
| Page view | No | 0 of 3 | 2 | Active |

Only account-default goals feed the campaign's "Conversions" column, so today that's **Purchase + Phone call lead**.

## 2. Target state

| Conversion action | Goal (category) | Primary / Secondary | Count | Value | Notes |
|---|---|---|---|---|---|
| **CET web booking** (NEW, GTM) | Purchase | **PRIMARY** (the only one) | Every | From `cetBooking.value` (fare incl. return leg) | Transaction ID = booking ref (stops duplicates) |
| Existing "Purchase" action | Purchase | **Secondary** once CET web booking records; **remove** after 30 days if it's the ETO tag | — | — | Never two Primary purchase actions, or you double count |
| Calls from ads (Phone call lead) | Phone call lead | **Secondary** (after CET web booking has recorded 3+ real bookings, about day 7) | One | none | Keep call length 60 s. Stays visible in "All conv." |
| CET quote request (NEW, GTM, `quote_request` event) | **Request quote** | Secondary | One | £0 | Rolls-Royce / price-on-request enquiries |
| CET website phone tap (NEW, GTM `tel:` clicks) | Contact | Secondary | One | none | |
| CET WhatsApp tap (NEW, GTM `wa.me` clicks) | Contact | Secondary | One | none | |
| The 2 existing Contact actions | Contact | Secondary | — | — | Check they aren't duplicates of the new taps |
| Get directions, Engagement, the 2 Page view actions | — | Secondary now, **remove after 30 days** | — | — | They're noise; page views are not leads |
| Whatever sits in "Submit lead form" | Submit lead form | Secondary or remove (§3) | — | — | |
| Later: **CET completed booking (offline import)** | Purchase | Secondary until proven, then swap with CET web booking | Every | Real paid fare | §6 |

**Order matters:** switch the bid strategy to Manual CPC first (plan §4). Changing Primary/Secondary
under smart bidding resets its learning. Under Manual CPC it only changes the reporting.

## 3. Fixing "Submit lead form — Misconfigured"

"Misconfigured" here means the goal holds conversion action(s) but none is usable as a primary (0
primary actions), or the action's tag is inactive or unverified. No campaign uses this goal (0 of 3),
so **it costs nothing and affects nothing.** Fix it so it stops confusing the picture:
1. Goals → Summary → on the **Submit lead form** row tap the **▾ chevron**. It lists the action(s) and each one's status.
2. For each action listed:
   - "Lead form submit" / "Google hosted" / anything from an old site or form you don't use → tap it →
     **Edit settings → Action optimisation: Secondary** → Save. After 30 days with nothing recorded:
     tick it → **Remove**.
   - If it's a **GA4 `form_submit`** or "generate_lead" import from the new site's contact form → keep
     it **Secondary** (category Submit lead form).
3. Don't put the CET quote request in this goal. Use the **Request quote** category, so quotes and
   contact-form leads stay separate.
4. Screenshot the expanded row for the agent if you're not sure what an action is.

## 4. Find out what "Purchase" really is (2 minutes, do this FIRST)

Goals → Conversions → **Summary** → tap the action under Purchase. Note down:
- **Source / Tracking:** Website (tag) · Google Analytics 4 · Import · Calls.
- **Status:** "Recording conversions" / "No recent conversions" / "Inactive" / "Unverified".
- **Last conversion** date (or in the table: Conversions column with the date range set to Oct 1–28, segmented by day).
- **Webpages** tab or **Tag setup**: which URL it fired on (an ETO domain or an ETO confirmation page means it's the ETO tag).
- **Count:** One or Every. **Value:** fixed or dynamic.

Then compare **Ads conversions by day, by action (Oct 1–28)** with **Command Centre web bookings by
created day** (Bookings → Source "web") **and ETO web bookings by day**. If bookings carry on while
Purchase stops, that's the date tracking broke.

## 5. Build CET web booking in GTM (tablet, Chrome "Desktop site" ON)

**The widget's real message** (from `resources/views/widget/book.blade.php`), posted to the parent
page when a booking is placed:

```
{ cetBooking: { event: "booking_request" | "quote_request", ref: "<booking ref>",
                value: <fare incl. return leg; 0 for quotes>, currency: "GBP",
                quoteRequest: true|false, canPay: true|false } }
```
It fires on booking **request** (card bookings then redirect to payment after 0.7 s), not on payment.

### Part A — Google Ads conversion actions
1. ads.google.com → **Goals → Conversions → Summary → + Create conversion action → Website** → enter
   `centralexecutivetransfers.co.uk` → **Scan** → scroll to **"Add a conversion action manually"**.
2. **CET web booking:** category **Purchase**, optimisation **Primary**, value **"Use different values for
   each conversion"** (default £144), count **Every**, click-through window **30 days**, engaged-view 3,
   view-through 1, attribution **Data-driven** → Done → Save and continue → **"Use Google Tag Manager"**
   → write down the **Conversion ID** (digits) and **Conversion label**.
3. Repeat, all **Secondary**, count **One**: **CET quote request** (category **Request quote**, no value),
   **CET website phone tap** (Contact), **CET WhatsApp tap** (Contact). Write down each label.

### Part B — GTM (tagmanager.google.com → container **GTM-WHH77L45** → Default Workspace)
1. **Variables → Configure** (built-in) → tick **Click URL**.
2. **Variables → New → Data Layer Variable:** `cet_value` (name it `DLV - cet_value`), `cet_ref`
   (`DLV - cet_ref`), `cet_currency` (`DLV - cet_currency`).
3. **Tags → New → Custom HTML**, name `CET - booking listener`, trigger **All Pages**:
   ```html
   <script>
   (function () {
     if (window.__cetListen) return; window.__cetListen = true;
     window.addEventListener('message', function (e) {
       var b = e.data && e.data.cetBooking; if (!b) return;
       var host = ''; try { host = new URL(e.origin).hostname; } catch (x) { return; }
       // Accept the CET widget only (any centralexecutivetransfers.co.uk subdomain).
       if (!/(^|\.)centralexecutivetransfers\.co\.uk$/.test(host)) { window.__cetBadOrigin = host; return; }
       window.dataLayer = window.dataLayer || [];
       window.dataLayer.push({
         event: (b.event === 'quote_request' || b.quoteRequest) ? 'cet_quote_request' : 'cet_booking',
         cet_value: Number(b.value) || 0,
         cet_currency: b.currency || 'GBP',
         cet_ref: b.ref || ''
       });
     });
   })();
   </script>
   ```
   (If the widget loads from a domain that is *not* a centralexecutivetransfers.co.uk subdomain, the
   tag records it in `window.__cetBadOrigin`. Tell the agent the domain and the regex gets widened.)
4. **Triggers → New → Custom Event:** `CE - cet_booking` (event name `cet_booking`) and
   `CE - cet_quote_request` (event name `cet_quote_request`).
5. **Triggers → New → Click – Just Links → Some Link Clicks:** `Click - phone` (Click URL starts with
   `tel:`) and `Click - WhatsApp` (Click URL matches RegEx `wa\.me|whatsapp\.com`).
6. **Conversion Linker** tag on All Pages (create it if missing). It keeps the gclid cookie that credits the booking.
7. **Google tag** with Tag ID `AW-<Conversion ID>` on **Initialization – All Pages** (skip if one already exists).
8. **Tags → New → Google Ads Conversion Tracking**, `Ads - CET web booking`: Conversion ID, booking label,
   **Conversion Value** `{{DLV - cet_value}}`, **Transaction ID** `{{DLV - cet_ref}}`, **Currency Code**
   `{{DLV - cet_currency}}`, trigger `CE - cet_booking`.
9. Same for `Ads - CET quote request` (trigger `CE - cet_quote_request`, no value), `Ads - phone tap`
   (`Click - phone`) and `Ads - WhatsApp tap` (`Click - WhatsApp`).
10. **Double-count check:** the Command Centre's "Web widgets" page also offers a pasted gtag snippet that
    does the same job. In Preview, look for any `AW-` tag **not** fired by GTM, and any old ETO conversion
    tag. Use GTM **or** the snippet, never both.
11. **Submit → Publish**, version name `Ads conversions - cetBooking 29 Oct`.

### Part C — prove it fires (no test bookings on the live site)
1. GTM **Preview** → `https://centralexecutivetransfers.co.uk/booking` → Connect. Under Tags Fired you
   should see **CET - booking listener**, **Conversion Linker** and **Google tag**.
2. Tap the phone link on the page and the **Ads - phone tap** tag should fire. Same for WhatsApp.
3. **The booking itself:** take the next **real** phone booking and enter it through the website form
   yourself while Preview is open (real data, not test data, decision D5). You should see a `message` event
   then `cet_booking` in the left column, and **Ads - CET web booking** fired, with the right value and ref
   in Variables.
4. Within 24–48 h: Goals → Conversions → **CET web booking: "Recording conversions"**.
5. Every week: Ads "CET web booking" count vs Command Centre web bookings with a gclid. Aim for within ±10%.

Known gaps (for the product/SEO agents, not the director):
- The **mini price checker's** "Book this journey" opens WhatsApp inside the iframe. GTM can't see that tap.
- The widget gets the gclid only if it's in **that page's URL**. A visitor who lands on
  `/airport-transfers-sheffield` and then clicks through to `/booking` loses it from the URL. The GTM
  conversion still credits it via the Conversion Linker cookie, but the **booking record** has no gclid,
  which hurts §6. Fix: the embed snippet should also read the `_gcl_aw` cookie (or sessionStorage) and pass
  it to the iframe. Deliver it as a site file for the directors to upload; nobody edits the live site directly.
- If the new site has a cookie banner, Consent Mode v2 is needed or UK conversions will be under-counted.

## 6. Later (month 2): import real completed bookings by gclid
1. Google Ads → Goals → Conversions → **+ → Import → "CRMs, files or other data sources" → "Track
   conversions from clicks"** → name **CET completed booking (import)**, category Purchase, **Secondary**,
   value per conversion, count Every.
2. Each week, export from the Command Centre all **completed (paid) bookings** that carry
   `marketing.gclid`, as a CSV with the columns `Google Click ID, Conversion Name, Conversion Time,
   Conversion Value, Conversion Currency` (time in `yyyy-mm-dd HH:mm:ss Europe/London`). The product agent
   can add this export button. Upload: **Goals → Uploads → +**.
3. After 4 weeks, if the import is complete and reliable, make **CET completed booking (import)** the Primary
   and **CET web booking** Secondary. Bidding then learns from money actually taken, net of cancellations and no-shows.
4. **Phone bookings:** they can't be matched by gclid. Options: (a) **Enhanced conversions for leads**
   (hashed phone/email captured on the site, then upload completed bookings with the hashed phone/email), or
   (b) keep calls Secondary and judge phone-heavy ad groups by the call details report (calls ≥ 60 s) matched
   by time against Command Centre bookings.
