# Handover — what was built recently and what's still open

Read with `CLAUDE.md` (rules) and `docs/growth/BUSINESS_FACTS.md` (facts). Branch:
`claude/cet-command-centre-guide-r1azew` (staging auto-deploys it; push = deploy;
the directors approve every deploy by saying "deploy"). Updated 2 Nov 2026.

## Working rules the directors set (don't relearn these the hard way)
- Explain a change, wait for "deploy", then push. Never push unapproved work.
- Never touch `public_html` (the live website). Give them files/steps to do by hand.
- Nothing auto-sends to customers. Imported ETO customers are never emailed unless confirmed.
- Customer PII stays in `docs/growth/data/` (gitignored) — never committed.
- UK time; never auto-edit Google Calendar; never put model ids in commits.
- Emails: follow `docs/growth/sales/kit/house-email-style.md` (approved tone).

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
- Outreach: 256 prospects (docs/growth/sales/*.csv) — first emails are the two approved
  house-style emails; follow-ups in `config/outreach.php`.
- Skills/agents added from GitHub (`.claude/THIRD_PARTY.md`): marketing (social, video,
  copywriting, cold-email, copy-editing, ad-creative, referrals, seo-audit, schema…),
  UI design (frontend-design, ui-designer, accessibility…), `video-edit` (ffmpeg reels,
  CET-gold captions; auto-captions need huggingface.co allowed in the environment).

## Still open
- Search Console indexing batch 2 (Gatwick, Stansted, Luton, Liverpool, Newcastle, the
  four town pages, /booking).
- Google Ads review ~9–10 Nov; set "Booking payment" to Secondary once "CET web booking"
  records; optional negatives: connect cars, hinckley, derby to birmingham, liverpool.
- Stripe (PVT) verification: upload the PVT Tide statement; payouts held until done.
- ETO cancellation: export everything, run the ETO audit, check no "eto2.taxi" links remain,
  then email support@easytaxioffice.co.uk.
- Driver rotation: MAN jobs went to Majid four times running — check the rotation page.
- Decide: "no contract / no minimum spend" for accounts; client names (JELD-WEN, LB Foster,
  Forged Solutions) only with permission; trade rates for partners.
- Festive surcharges: suggested Halloween lower/evening-only and a "pickup / landing time"
  line; Time surcharges page must match the published table.
