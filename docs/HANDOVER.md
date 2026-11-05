# Handover — the shared memory for every Claude session

Read with `CLAUDE.md` (rules) and `docs/growth/BUSINESS_FACTS.md` (facts). Branch:
`claude/cet-command-centre-guide-r1azew` (staging auto-deploys it; push = deploy;
the directors approve every deploy by saying "deploy"). Updated 4 Nov 2026.

**The directors run more than one Claude account. This file is how they stay in sync.**
- At the START of a session: `git fetch origin claude/cet-command-centre-guide-r1azew`,
  pull/rebase onto it, then read the Session log below before doing anything.
- With EVERY deploy (push): add a dated line to the Session log (what changed, what the
  directors decided, what's still open) and update "Still open". Commit it with the work.
- Decisions made only in chat (no code) still go in the log, e.g. "stopped ads".
- Only one session should push at a time; fetch right before pushing.

## Session log (newest first, one line each)
- **4 Nov 2026** — DECISION: Google Ads stopped completely (can't afford it). Pause the
  campaign (don't delete; GTM + "CET web booking" conversion stay, they're free). Focus now
  = free social media + winning corporate/partner accounts. 90-day social plan deployed
  (`docs/growth/social/90-day-growth-plan.md`); its £60 paid boost is ON HOLD (no paid
  spend). Shared-memory rule added (this section).
- **4 Nov 2026** — Favicon (done by the directors by hand on the live site): Google showed
  the old "CENTRAL" logo because the live layout's icon links point to
  `public/assets/favicons/favicon.jpg` and line 20 to `assets/images/logo.png` (not
  favicon.ico). They uploaded a circle-C `favicon.jpg` (192px) and changed line 20 of
  `resources/views/layouts/app.blade.php` to favicon.jpg. `app.blade (3).php` there is an
  unused 11 July backup copy; leave it. Google refreshes the icon in days to ~3 weeks.
  Search Console: batch 2 (~2 Nov) and batch 3 (4 Nov: airport-transfer-company, corporate
  travel, VIP, event, long distance, V-Class, minibus, prom/wedding, open-account, gallery)
  DONE. Every sitemap page has been requested; legal pages left to the sitemap.
- **4 Nov 2026** — Directors confirmed DONE: Kidderminster £64.80 balance link sent, web
  VAT double-charge audit/refunds done, Stripe PVT verification uploaded, Stripe webhook
  registered, Google Ads paused (no new charges). Google reviews are already requested
  from every completed customer (standing practice, not a to-do).
- **4 Nov 2026** — ETO is no longer used (directors). Removed the dashboard's monthly
  "export from EasyTaxiOffice / Google Ads" banner (test updated). Do not suggest ETO
  imports/audits as routine work any more.
- **5 Nov 2026** — Outreach: added B. Braun (Sheffield UK head office, Thorncliffe Park)
  from `docs/growth/sales/batch-H-direct.csv` via migration 2026_11_05_000001; email
  customercare.bbmuk@bbraun.co.uk was given to us by B. Braun. Greeting "Dear B. Braun
  Team,"; house-style business email with the enhanced-DBS line (medical).
- **5 Nov 2026** — Social pack for 9–15 Nov written (`docs/growth/social/2026-11-09-week.md`
  + `.csv`): 10 organic posts, B2B/LinkedIn-weighted plus festive build-up; Sunday 8 Nov
  14:00 shoot list. Raw video editing waits on the environment allowing WeTransfer
  (wetransfer.com, *.wetransfer.com, we.tl, *.wetransfer.net) and huggingface.co.
- **5 Nov 2026** — Week pack revised (festive wording, no directors). New **Growth
  dashboard** (Marketing & reports → Growth dashboard, `/marketing/growth`,
  `Services\Growth\GrowthDashboard`): bookings by channel per week (social = web booking
  with utm_source facebook/instagram/linkedin/tiktok/gbp; ads = gclid), new vs returning,
  outreach emailed/replies/accounts, review requests, and a hand-typed weekly social/reviews
  log stored in the `growth_social_log` setting (no migration). No-ads growth plan with
  projections: `docs/growth/GROWTH-PLAN-NO-ADS.md`.
- **5 Nov 2026** — Directors said the projections looked too extreme. Reality check added
  (section 3.0): ETO history shows ~12–18 journeys/month before ads (Oct 25–Feb 26) vs
  80–110 after ads started (Mar 26). Planning case now ~20% below the with-ads line all
  winter (Nov 65, Dec 69, Jan 54, Feb 62, Mar 69, Apr 82); accounts/partners are the lever.
- **2–3 Nov 2026** — Pricing drag/zone fix (Kidderminster), VAT/payment fixes, house email
  style (SCS/Rolls-Royce/DocFest), 256 drafts rewritten, contacts. Detail below.

## Working rules the directors set (don't relearn these the hard way)
- Explain a change, wait for "deploy", then push. Never push unapproved work.
- Never touch `public_html` (the live website). Give them files/steps to do by hand.
- Nothing auto-sends to customers. Imported ETO customers are never emailed unless confirmed.
- Customer PII stays in `docs/growth/data/` (gitignored) — never committed.
- UK time; never auto-edit Google Calendar; never put model ids in commits.
- Emails: follow `docs/growth/sales/kit/house-email-style.md` (approved tone).
- The office is the boss: anything they change on the Command Centre (names, prices,
  contacts) must stick. Never let a sync/re-import/self-heal overwrite it.
- Be efficient with usage; give short plain answers; say what's deployed vs not.
- Social posts (directors, 5 Nov 2026): no prices or surcharges; say we work through the
  festive period; never say directors drive. Say licensed drivers / professional chauffeurs.

## Built in Oct/Nov 2026 (all tested; see git log for detail)
**Bookings & money**
- Web widget: ETO-style "Taking you to secure payment" hand-off; meet & greet only on
  airport pickups (airport hotels/FBOs charged, no "name board" wording); airport pickup
  time = flight landing time.
- Payments: extras (e.g. soiling) shown correctly; imported customers can't be emailed
  without "send anyway".
- Invoices: "Name on invoice (Bill to)" override on the booking page; renaming a customer
  carries to their upcoming bookings; View PDF always opens a fresh copy (?v=).
- Two companies: VAT → Central Executive Transfers Ltd (Square, Tide); non-VAT → PVT LTD
  (Stripe, own Tide; first Stripe payouts ~20 Oct). Payroll shows "Paid into Ltd/PVT" per
  job and "Pay from Ltd / PVT: £x" per driver (`Booking::moneyAccount()`,
  `CET_PVT_LIVE_FROM` default 2026-10-25 — may need to be earlier).
- PVT email: non-VAT emails send from admin@centralexecutivetransferspvt.co.uk and office
  copies land there once "Send PVT emails…" is on; a passing PVT test switches it on;
  Settings shows a live status line.
- Dashboard "Collected today" is ex-VAT.

**Drivers & ops**
- My jobs keeps last night's unfinished job after midnight.
- Lead time/alarm ignores GPS older than 30 min.
- Queued reminders follow the booking's current pickup (moved bookings re-time).
- Blocked actions (e.g. driver with an expired document) now show a red banner.
- Bookings list: blank "To" = onwards; "Dates by: Pickup / Booked (came in)"; export has
  "Booked on" and "Source".

**Marketing**
- Google Ads: Manual CPC, ad-group bids, keyword clean-up uploaded; "CET web booking"
  conversion (ID 16892093599) live via GTM container GTM-WHH77L45 (version 7). Budget
  £30/day (limited by budget, ~41% impression share). Decision: leave changes to settle
  ~7–10 days, then review (search lost IS budget vs rank).
- SEO: sitemap.xml (32 pages) uploaded to the live site by the directors; Search Console
  sitemap submitted; indexing batch 1 requested (homepage + 9 money pages); batch 2 next.
- Favicon: circle "C" (`favicon.ico` in public_html/public, uploaded by the directors).
- Outreach: 256 prospects (docs/growth/sales/*.csv); follow-ups in `config/outreach.php`
  (first emails: see the 2–3 Nov section below).
- Skills/agents added from GitHub (`.claude/THIRD_PARTY.md`): marketing (social, video,
  copywriting, cold-email, copy-editing, ad-creative, referrals, seo-audit, schema…),
  UI design (frontend-design, ui-designer, accessibility…), `video-edit` (ffmpeg reels,
  CET-gold captions; auto-captions need huggingface.co allowed in the environment).

## Built 2–3 Nov 2026 (all deployed to staging, suite 2,038 green)
**Pricing (Kidderminster misquote fix)** — see the "drag" bullet in CLAUDE.md.
- Fixed airport fares only inside the home catchment. Sheffield = S1–S99 postcodes,
  Doncaster = DN1–DN12 only; unknown postcode = no zone; >35 mi from base = never a
  local fixed fare. Out of area = distance price + drag (£1 EVERY mile once past 15 mi,
  rounded to £5), measured to the FAR end so A→B and B→A cost the same.
  Kidderminster↔MAN = £330 both ways (+£10 meet & greet on the airport pickup).
- Google can't measure the distance → "office to confirm" (no price), never a guess.
- Holiday rates (no night rates): Halloween +60%, 24 Dec +25%, 25 Dec +50%, 26 Dec
  +50%, NYE +25% before 18:00 / +50% after, NYD +80% before 06:00 / +50% after; on the
  standard fare, every vehicle, each return leg by its own date (`config/cet.php`).
- Customer website price = admin price (TripPricer); guarded by
  CustomerAdminPriceParityTest + PricingAccuracySweepTest.
**Payments & confirmations**
- Prices are stored EX-VAT; VAT added on top for VAT invoices. Web VAT bookings were
  charged VAT twice before the fix: run `php artisan cet:web-vat-audit` (lists them;
  `--apply` fixes unpaid ones; paid ones need a refund of the extra).
- Card payments no longer tick "VAT received" (it double-counted VAT after a re-price).
  A price change clears an old VAT tick.
- Mark paid emails the OFFICE only. Customer copy only via "✉ Send confirmation to
  customer" (+ Preview) in Payment history. The 5-min reconcile sweep also never emails
  the customer for an office-recorded payment.
- One confirmation per return trip; a return paid in one go gets ONE "Paid in full" receipt.
- Account customers' web returns: both legs billed to the account (old ones repaired).
- Booking name edits stick (`meta['name_override']`).
- CET-007A2C (Kidderminster): re-priced £180 + VAT = £216, paid £151.20, £64.80 due.
**Outreach**
- House style now = the directors' ChatGPT-style SCS, Rolls-Royce and DocFest emails
  (all three in house-email-style.md). No contact name → "Dear [Company] Team,".
  Medical/care/school/council emails mention enhanced DBS-checked chauffeurs; wedding
  venues mention the Rolls-Royce Ghost. All 256 unsent drafts rewritten.
- Contacts: 83/256 have a published email (`docs/growth/sales/contacts.csv`). Rule:
  only addresses actually published, with a source URL — NEVER pattern-guessed.
  ChatGPT's DocFest contacts (joanna.hunter@, production@) could not be found anywhere;
  their partnerships director is actually Paul Steele (no email published).
- Prospects can carry a CC (mailto opens with it). `ContactUpgrader` +
  `docs/growth/sales/contacts-upgrade.csv` swaps a general inbox for a better published
  route on never-emailed prospects (file not written yet: this environment blocked
  company websites — needs network access widened to research properly).
- AESSEAL plc replied "not interested" → do not contact. Press "Do not contact" on a
  prospect for any future opt-out.
**Social media**
- Buffer isn't free → use the platforms' own free schedulers: Meta Business Suite
  (Instagram + Facebook), LinkedIn and TikTok built-in scheduling; Google Business
  Profile posted by hand. Postiz (open source) needs its own server — not worth it yet.
- Growth plan agreed in chat (to write into `docs/growth/social/` when asked): short
  vertical video 4–5×/week cross-posted to Reels/TikTok/Shorts/Facebook; weekly 30-min
  filming → `/video-edit`; 15 min/day replies + commenting on local accounts; Collab
  posts with venues/hotels from the outreach list; reviews + customer reshares (with
  permission); seasonal calendar (Christmas parties, NYE, prom, weddings, Arena, match
  days, DocFest, snooker); directors reshare LinkedIn posts; optional £20–30 boosts later.
  90-day targets: ~1,000 local followers, one 10k-view video, 5 bookings/month from social.

## Still open
- Accounts push (free): send the house-style outreach 10–15/day (83 with published
  emails first), partner operators first; ask existing account clients for referrals.
- Driver rotation: MAN jobs went to Majid four times running — check the rotation page.
- Decide: "no contract / no minimum spend" for accounts; client names (JELD-WEN, LB Foster,
  Forged Solutions) only with permission; trade rates for partners.
- Festive surcharges (Halloween now past): add a "pickup / landing time" line; Time
  surcharges page must match the published table for Christmas/NYE/NYD.
- Rotate the Google Maps key.
- Office still gets a second internal "Payment received" email for a return paid in one
  go (customer unaffected) — directors to decide if it should go.
- Better outreach contacts: widen this environment's network access, then rerun the
  contact research into contacts-upgrade.csv (published addresses only).
- Social: set up the free schedulers, film the shot list (growth plan doc now written).
- Known small gaps: admin "Customer pays (incl. VAT)" can be 1p off on VAT returns; the
  mini quick-price widget has no pickup time so shows no holiday rate.
