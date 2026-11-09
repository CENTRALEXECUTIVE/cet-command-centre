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
- **9 Nov 2026** — Multi-vehicle bookings: new-booking form has **Vehicles** (1–5, next to Passengers). Price =
  per-vehicle live quote × vehicles (cards show "£330.00 (2 cars)"), seats/luggage checks × vehicles, saved as
  meta `vehicle_count`; `Booking::vehicleCount()/vehicleLabel()` show "2 × V Class" on the booking page, calendar
  title/details, confirmation, office and payment-link emails; the booking page says "1 more car to add under
  🚗 Extra cars" until the second driver is added. Editing keeps 10 pax valid. MultiVehicleBookingTest + browser
  check (10 pax, 2 × V Class, VAT: £330 + £66 = £396). For Forged Solutions Fri 13 Nov (Nicola Wright).
- **9 Nov 2026** — Outreach batch can now send SPACED: the approve bar has "One every 5 minutes" (default) /
  10 / "All now". Approving queues the ticked emails (setting `outreach_queue`, no migration) and
  `cet:outreach-send-queued` (every minute) sends each at its time, re-checking it's still due, not opted out,
  a weekday and within today's limit (a prospect marked Replied meanwhile is dropped). Needs the schedule:run cron.
- **9 Nov 2026** — Batch proof-read (QA agent + named-contact research, published sources only). Fixes in
  migration 2026_11_09_000004 + config: NCM UK head office is Todwick (not Kiveton Park); AMRC greets
  "Dear AMRC Team,"; Castle Square -> kevan.shaw@ (Managing Partner, published); IFA mcarlisle@ = Michael
  Carlisle; corporate day-4 follow-up says "visiting customers… site visits and events" in the first person;
  partner day-4 geography fixed (South Yorkshire and North Derbyshire). Scheduled Outlook sends FAILED: the
  Microsoft 365 connector is read-only (no Mail.Send / Mail.ReadWrite), so outreach goes from the batch page.
  Inbox 9 Nov: Forged Solutions (Nicola Wright) booking request, 10-seater minibus Fri 13 Nov 18:15 Browns
  Brasserie S1 2JL -> Magna S60 1FD (asked about a return); CMS Julie Pepper (Senior Facilities Manager) passed
  us to their Senior Travel & Operations Manager, use local taxis for now, keeping us on file.

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
- **5 Nov 2026** — TBR Global Chauffeuring replied (Barrie Bradley, Head of Supplier
  Management EMEA) pointing to their partner form; directors SUBMITTED the TBR partner
  application the same day (1–3 years trading). Prospect should be set to "Replied" so no
  follow-up goes; wait for TBR's vetting contact. Their reply opened "Hi Matthew": check the
  display name on the sending mailbox.
- **5 Nov 2026** — Boeing Sheffield replied: no executive transport needed (staff use hire/
  pool cars; VIP visits arranged by their Westminster office). Mark "Not interested" (not
  DNC). Second reply in a day addressed "Hi Matthew"; not from our templates (checked), so
  it's the mail app/account name or a signature: directors to check.
- **5 Nov 2026** — Solved: the directors sign outreach as **Matthew Green** (mail app
  signature + CENTRAL logo), hence "Hi Matthew". Drafts now end at "Kind regards," with no
  signature block or opt-out line (`config/outreach.php` signature/opt_out = ''); house
  style doc and CLAUDE.md updated.
- **6 Nov 2026** — "Export for review" button (Growth dashboard + Review page,
  `marketing.growth.export`, `Services\Growth\BookingReviewExport`): detailed bookings CSV
  by date came in (default last 6 months) with channel/UTM/ad click, dates, weekday, lead
  time, vehicle, airport, postcode DISTRICT only, fare ex-VAT, VAT, payment, account, new vs
  returning + booking number. No names/phones/emails/full addresses, so the directors can
  send it to Claude for analysis. ETO-imported rows carry the IMPORT date as booked date.
- **6 Nov 2026** — First review export analysed (1 Oct to 6 Nov): ~12 trips booked/week in Oct
  with ads (~£9–10k/month), 57% new customers; week of 2 Nov (after ads stopped) = 2 trips.
  Plan section 3.00 rebuilt: no-ads realistic ~29 trips Nov, 33 Dec, 26 Jan (~£5–6k/month).
  Lean £10/day ads restart (brand, Sheffield to MAN exact, Heathrow) offered as a directors'
  option, decide after the week of 9 Nov.
- **6 Nov 2026** — Outreach follow-ups never land on a weekend: `Prospect::workingDay()` moves
  Sat/Sun to Monday when "Mark emailed" sets the next date; migration 2026_11_06_000001 moved
  existing weekend dates. Contact-email research for the 166 prospects with no email running
  (published addresses only, with source URL).
- **6 Nov 2026** — Contact research pass 3 done (4 agents, search-snippet sources; company
  sites themselves are blocked by this environment's network): +65 published emails and 103
  phone numbers written into `docs/growth/sales/contacts.csv` (new `phone` column), loaded
  fill-only by migration 2026_11_06_000002. Now 148 of 256 prospects have an email (+ B. Braun,
  TBR in the app). 10 found addresses deliberately NOT loaded (investor relations, press,
  consumer care, affiliate inboxes; GetTransfer and Jayride have supplier-payment warnings);
  reasons are in each row's note. 54 more have a phone number only. Several "general" inboxes
  come from third-party pages; the note column says which to double-check.
- **6 Nov 2026** — Cash crunch review: `docs/growth/BUSINESS-REVIEW-2026-11.md` (business-analyst
  agent from wshobson/agents, MIT, installed with finance skills; see .claude/THIRD_PARTY.md).
  Cash £890 + £797 due vs ~£3.4k owed/committed (mostly to Director A). Ads were 12.7% of
  card income vs a 10% company cut. Pay order: external drivers first, directors last;
  ring-fence prepaid driver share in Tide Budgets. Suggested (directors decide): 15% company
  share on external-driver jobs from 1 Dec, a company-kept booking fee, ads off unless weeks of
  9 and 16 Nov are both <=5 trips. OPEN: no VAT payments seen from Ltd Jun-Oct, ask the
  accountant. New **PA** (`pa` agent, `/pa`, `docs/pa/todo.md` money board + to-dos).
  Auto-sending outreach: proposed a one-tap daily batch via Workspace (weekdays, capped),
  ideally from a separate sending address; awaiting directors' answer.
- **6 Nov 2026** — Full future-jobs check (all bookings since Jan): 23 future legs, £3,797 fares,
  £3,552 already card-paid; driver share ~£3,200 (unallocated ~£2,095, Director A ~£1,020,
  Director M ~£300). Cash + due in = £1,687 → gap ~£3,400 (prepaid money spent, mainly ads).
  Plan: pay external drivers per job from new prepayments, rebuild a ~£2,100 float before any
  director back pay. Export now has "by pickup date" + "All future jobs" button.
- **6 Nov 2026** — PVT Stripe payouts 20 Oct to 3 Nov = £1,983.72 (+£177.10 on 10 Nov); PVT
  paid Director M £927 + ~£463 other, balance £593.72. Directors said "deploy everything".
- **6 Nov 2026** — Default environment network set to UNRESTRICTED (directors approved) so
  company websites and WeTransfer load. Website scan (`scratchpad sitescan`, own-site pages
  only, source saved): +16 emails for existing prospects (contacts.csv) and +8 for the new
  **batch I** (30 owner-managed professional/manufacturing firms whose directors travel,
  after a Hawsons director met in a cab said he flies from Heathrow 7-8x a year). Loaded by
  migration 2026_11_06_000003. 11 scanned addresses rejected (press, IR, product safety,
  safeguarding, random staff). Hawsons was ALREADY a prospect (batch B, Sheffield@hawsons.co.uk):
  treat as a warm lead, personal follow-up to the director, not the cold template.
  Agent Reach skill (panniantong/agent-reach) NOT installed: needs the directors' own
  LinkedIn/X session cookies (account-ban risk, LinkedIn forbids scraping) and a mirror was
  flagged suspicious; revisit only if the directors accept that.
- **6 Nov 2026** — **Approved daily outreach batch** (directors asked): Outreach → "📨 Today's
  batch" (`/outreach/batch`, `Services\Outreach\OutreachBatch`, `Mail\OutreachMail`). Lines up
  to 10 a weekday (due follow-ups first, then best-fit new prospects with an email); a director
  ticks "I have checked these emails" and taps Approve; sent from admin@ (directors' choice,
  warned about sharing reputation with customer mail) as "Matthew Green | Central Executive
  Transfers" with the signature + CENTRAL logo (`public/images/cet-email-signature.png`). Each
  send advances the sequence like "Mark emailed" (weekday follow-ups). Never on a timer, never at
  weekends, never DNC/closed, daily cap counted across the day. Replies land in admin@; mark them
  "Replied" so follow-ups stop. Hawsons warm lead: Matt Bruce (MD, Hawsons Data Protection &
  Cyber Security); personal follow-up drafted in chat, his direct email isn't in the page source.
- **6 Nov 2026** — **My assistant** page in the Command Centre (`/assistant`, top of the menu,
  `Services\Assistant\AssistantBoard`): today/tomorrow jobs, no-driver jobs (7 days), prepaid
  future jobs with external vs directors' driver share (live) against typed-in cash, outreach today,
  money board (Ltd/PVT/incoming, owed to us, we owe; settings `pa_money`) and a shared to-do list
  (`pa_todos`). Pre-filled once by migration 2026_11_06_000004 only if empty. The Claude `/pa`
  agent still works for drafting and planning.
- **7 Nov 2026** — Booking form: meet & greet now auto-ticks when the RETURN leg picks up at an
  airport (S10 <-> MAN = £105 + £115; test in AdminPricingStripTest). Form redesign v2
  (`bookings/partials/form-v2.blade.php`, presentation only, all field ids/names/JS hooks
  unchanged): segmented journey type, compact inputs/vehicle rows, passengers/bags in one row,
  hints hidden, VAT company fields really hidden until ticked ([hidden] fix), slim fixed total
  bar on phones (tap for breakdown/preview/cancel). Global fix: the mobile header "Sign out"
  made every page ~25-35px wider than the phone (app.css, + overflow-x clip backstop).
- **7 Nov 2026** — Vehicle cards had vanished after the [hidden] fix (an old `strip.hidden = true`
  with no un-hide); now always visible + guard test. Phones/tablets (<1100px) get a STEP-BY-STEP
  booking: Customer → Journey → Return (own card, only for returns) → Vehicle & extras →
  Payment & confirm; Next checks the step's required fields; a blocked submit jumps to the step
  with the problem; Confirm shows on the last step. Desktop stays one page with the sticky panel.
- **7 Nov 2026** — Return step now has its own **vehicle cards** (starts on "Same as outbound",
  greys out vehicles too small for the return party) and its own **passengers / suitcases / cabin
  bags** (`return_passengers|suitcases|hand_luggage`, validated in StoreBookingRequest, saved by
  `BookingService::buildLeg`, shown in the email preview). They follow the outbound until changed;
  "Match the outbound again" resets. Tested end to end in a phone browser (one way → return,
  return party 4/5 + V Class: saved OUT Exec 2 pax £105 / RET V Class 4 pax 5 cases £175 with
  meet & greet) + AdminReturnPartyAndVehicleTest.
- **7 Nov 2026** — Phone steps reordered for quoting a caller: Journey → Return → Vehicle & price →
  Customer → Payment & confirm; tap the progress bar to jump to any step (Confirm still checks all
  required fields and jumps to the step that's missing something).
- **7 Nov 2026** — Steps now: **Outbound** (journey + vehicle cards + passengers/luggage + extras on
  ONE page) → **Return** (same again: return journey, vehicles, passengers/luggage) → Customer →
  Payment & confirm. Guard test against a fixed step index (it broke the page once in testing).
- **7 Nov 2026** — Breakdown now labels the leg that carries meet & greet ("Return leg (Standard
  Minibus) · incl. meet & greet £150"); the £10 was already charged, just not shown. Overflow
  backstop changed to `body{overflow-x:hidden}` (+`clip` where supported) because older iPhones
  ignore `clip`; mobile bar buttons wrap and the bar is capped at the screen width.
- **8 Nov 2026** — Price strip returns `leg_extras` (out/ret) so a return's breakdown says what each
  leg includes ("Return leg · incl. meet & greet, child seat"); one-way already itemises. Checked:
  EveryPageLoadsTest (all GET pages, no 5xx) green, and a phone-browser crawl of all 59 menu pages +
  booking/edit/assistant/growth/batch with every fold opened: no JS errors. Christmas Eve check:
  minibus MAN→Sheffield 24 Dec = £140 × 1.25 + £10 meet & greet = £185 (correct per cet.php).
- **8 Nov 2026** — DECISION: any fare with a holiday/time rate (festive table, night rate, office
  dated uplift) is rounded UP to the next £5 (`TimeSurcharges::forStandardFare`), e.g. Exec MAN
  24 Dec £131.25 → £135, Christmas Day £157.50 → £160, NYD early £189 → £190. Flat extras (meet &
  greet, seats, ribbons) added after, unrounded. Shared by the website and the admin form (parity
  tests updated).
- **8 Nov 2026** — Office booking email (`OfficeBookingMail`) now shows the WHOLE trip for a return:
  total for both journeys, each leg's details + fare + reference, paid/remaining, and "Take payment"
  for the whole trip (it showed only the outbound's £105 on a £290 return). One-way unchanged.
- **8 Nov 2026** — Payroll (`/payroll`) now opens on THIS week, Monday–Sunday (director's choice;
  dates still editable, `?month=` still works). New "⬇ Export" button downloads a CSV of the
  chosen dates (`payroll.export`): one row per job, a TOTAL row per driver, ALL DRIVERS TOTAL;
  same figures as the page. Admin only. No migration.
- **8 Nov 2026** — Payment link email (`PaymentLinkMail`) says what the money is: a part payment is
  a "£10 deposit" with "the remaining £95 is paid in cash to your driver on the day" (cash job) or "is
  due before your journey, we'll send a link" (card job); clearing the fare after a deposit = "the
  balance"; otherwise "pay for it". Adds a Your journey block (pick-up, from, to, vehicle; both legs
  on a return).
- **8 Nov 2026** — Card deposit on a CASH job is now automatic: when a card payment (Stripe/Square
  webhook or a card line marked paid) covers PART of the fare, it's recorded as the paid deposit
  (`Booking::syncCardDepositOnCashJob`, meta deposit `auto`), so the driver collects fare − deposit
  (£105 − £10 = £95) with no office edit. An office-typed deposit is never changed (only ticked paid).
  BUG FIXED: any online payment on a cash job used to make the driver collect £0
  (`businessCollectedCash`); now only when the card covers the fare. CardDepositOnCashJobTest.
- **8 Nov 2026** — Payment history: a card line reads "Link created" until the link actually goes to
  the customer ("Link sent (email / WhatsApp / SMS)", meta sent_via); status value unchanged. Method
  shows "Card / Stripe" on PVT bookings. Row Actions → "👁 Preview the email" shows the exact card-link
  email (deposit wording, journey, recipient) without creating a link or sending anything.
- **8 Nov 2026** — Emails after a card deposit on a cash job: office copy pill says "£95.00 cash to
  driver" (`cashDueToDriver`, was a bare "Cash to driver" under a £105 total); customer confirmation
  says "Pay the driver £95.00 in cash on the day" (was "Pay the driver on the day (cash)").
- **9 Nov 2026** — DECISION: ITM Power removed from outreach (financial difficulty, won't move supplier):
  migration 2026_11_09_000001 marks it Not interested. First batch review: untick TBR (already applied
  via their partner form 5 Nov; mark Replied), AMRC greets "Dear Ben" on a generic inbox (clear the
  contact name), DocFest and SCS were house-style picks on 3 Nov so may have been sent by hand already.
- **9 Nov 2026** — Outreach follow-ups: managed ground transport / transfer platforms (CMAC, First Travel
  Solutions, Suntransfers, Jayride, Holiday Taxis, Transferz, Kiwitaxi, NXTS, GroundSpan) were getting the
  CORPORATE follow-up ("corporate chauffeur account… monthly VAT invoice"); partner_keywords now include
  ground transport / managed transport / airport transfer / transfer platform so they get the partner
  follow-ups. Council/NHS transport prospects still get the corporate follow-up (open: own wording?).
  Batch cap: follow-ups due come first and count inside the 10/day, never on top.
- **9 Nov 2026** — Outreach greetings: a name is used only when the address reaches that person
  (`Prospect::greetingName`): shared inboxes (enquiries@, info@, sales@…) or someone else's address
  (charles@ for contact Garry at SCS; rob.burton@ for Kevan) greet "Dear {Company} Team," (13 prospects).
  DECISION: batch mainly NEW: at most 3 follow-ups a day inside the 10 (`outreach.batch.max_follow_ups`),
  the rest wait oldest-first. TBR marked Replied (migration 2026_11_09_000002).
- **9 Nov 2026** — Microsoft 365 connector live (Claude can read admin@ Outlook). Sent Items showed 16+ first
  emails sent BY HAND on 3/5/6 Nov (Flint Bishop, First Travel, CMAC, Birchwood, SCS, Rolls-Royce, Uni
  Corporate Events, Addison Lee, Bekaert, DocFest, SMP, Clarity, Cooper & Turner, Corporé, Cutlers' Hall,
  Danieli, plus Sheffield Forgemasters and Carey International found in a second sweep) that the app didn't know about: today's batch would have re-sent first emails to 6 of them.
  Migration 2026_11_09_000003 records them (status Emailed, day-4 due on a weekday, subject = the one
  actually used so "Re:" matches) and replies: Blacklane Replied (Sheffield not on their list; apply via
  partner portal), CMS Replied (Tanya Holt passed us to Julie Pepper: reply to Julie), Boeing Not interested.
  Rule: send outreach from the batch page, or tap "Mark emailed" after sending by hand, or duplicates go out.
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
- DKIM is NOT set up for centralexecutivetransfers.co.uk (Microsoft 365; SPF + DMARC p=none are fine).
  Directors to turn it on (Defender → Email authentication → DKIM, add 2 CNAMEs at the DNS host) before
  raising outreach volume. Offered: 10 new + follow-ups capped at 20/day (not built; batch is 10 total).
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
