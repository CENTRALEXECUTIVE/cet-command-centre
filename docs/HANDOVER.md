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
- Search Console indexing batch 2 (Gatwick, Stansted, Luton, Liverpool, Newcastle, the
  four town pages, /booking).
- Google Ads: STOPPED 4 Nov (directors). Confirm the campaign shows "Paused" and no new
  charges. The ~9–10 Nov review is cancelled.
- Accounts push (free): send the house-style outreach 10–15/day (83 with published
  emails first), partner operators first; ask existing account clients for referrals.
- Stripe (PVT) verification: upload the PVT Tide statement; payouts held until done.
- ETO cancellation: export everything, run the ETO audit, check no "eto2.taxi" links remain,
  then email support@easytaxioffice.co.uk.
- Driver rotation: MAN jobs went to Majid four times running — check the rotation page.
- Decide: "no contract / no minimum spend" for accounts; client names (JELD-WEN, LB Foster,
  Forged Solutions) only with permission; trade rates for partners.
- Festive surcharges: suggested Halloween lower/evening-only and a "pickup / landing time"
  line; Time surcharges page must match the published table.
- Kidderminster customer: send the £64.80 balance link (not the old £28.80 one) or
  cancel and refund; re-type the passenger name once.
- Run `php artisan cet:web-vat-audit` and refund any web customer charged VAT twice.
- Register the Stripe webhook (`/webhooks/stripe`, checkout.session.completed; signing
  secret in Settings → payments). Rotate the Google Maps key.
- Office still gets a second internal "Payment received" email for a return paid in one
  go (customer unaffected) — directors to decide if it should go.
- Better outreach contacts: widen this environment's network access, then rerun the
  contact research into contacts-upgrade.csv (published addresses only).
- Social: set up the free schedulers, film the shot list (growth plan doc now written).
- Known small gaps: admin "Customer pays (incl. VAT)" can be 1p off on VAT returns; the
  mini quick-price widget has no pickup time so shows no holiday rate.
