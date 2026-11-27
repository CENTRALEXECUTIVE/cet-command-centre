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
- 27 Nov 2026 (kk): Pricing safety for building names: fixed fares read the postcode (a '23 Brearley Hall, ' prefix gives the identical fare — test); DistanceService now retries Google with just each end's postcode when the full address can't be placed, before falling back to an estimate.
- 27 Nov 2026 (jj): Address boxes (website + office, shared public/js/cet-forms.js, v=39): choosing a Google suggestion now KEEPS a house number / building name typed before the street ('23 Brearley Hall, ' + 'Woodmere Drive, Old Whittington, Chesterfield S41 9TA'), and when the full typed text finds nothing it searches the street part (after the first comma / without the first words). Free typing always worked — the pick used to wipe it. Browser-tested.
- 27 Nov 2026 (ii): Booking-form review (docs/BOOKING_FORM_REVIEW.md) — directors chose 1,3,5,6 (2,7,8 no; 4 maybe). Built: (1) optional 'PO number / cost code' on the website when VAT = Yes → booking cost_code on both legs (on invoices; booking page shows 'PO / cost code' for any booking); (3) VAT company details pre-fill from the customer's last VAT booking — website (company emails only, widget.vat-details) and office New booking (customers.vat-details); (5) short-notice message offers WhatsApp/call and email; (6) Return page has its own stops, pre-filled with the outbound's reversed (the directors' Nov rule stays the default), editable/removable, each leg charged £10 per its own stop (TripPricer return_stopovers). Stops are a flat £10 each (CET_SURCHARGE_STOPOVER); the detour distance isn't priced.
- 27 Nov 2026 (hh): Emails on a VAT return (CET-14053A/B): office 'Payment received' now shows each journey EX-VAT ('Fare (excl. VAT)' £115/£105) and the payment block Subtotal £220 + VAT £44 = Total £264 (was £138 inc. VAT per leg); customer paid confirmation says 'Payment received: £264.00' — the other leg's share is recorded as '<id>-L<leg>' so the template now groups on the base payment id (it showed £138).
- 27 Nov 2026 (gg): Claire Green booked CET-14053A/B (MAN→JELD-WEN 7 Dec + return), paid £264 by Square 16:53; she was emailed the on-hold/pay-link email (15:50) and the paid confirmation with VAT invoice (16:53). Fixes: ONE office 'Payment received' per trip payment (the cascade now stamps the other leg's office flag; it used to send one per leg); booking page email history covers BOTH legs of a trip (tagged by leg) and shows '✓ <type> emailed to <customer> on <date>' or 'No confirmation has been emailed yet' — the confirmation is sent from the paid leg, so the other leg looked unsent; office notices to the PVT inbox are logged as office, not customer.
- 27 Nov 2026 (ff): Widget: ONE date box on an airport pickup — 'Pickup date & time' becomes 'Flight landing date & time' (Return page: 'Return flight landing date & time') and the separate landing box is hidden but kept in step; server takes the landing time from the pickup time if it's missing. Calendar time starts blank (--:--) and Done stays disabled until day + hour + minutes are chosen; the pickup box is no longer pre-filled with the earliest allowed time (it caused 00:00/01:00 bookings to slip through). Browser-tested.
- 27 Nov 2026 (ee): Booking widget calendar: stays open after a day tap (the redraw made the outside-click handler close it), moves to the time, and closes only on a new gold 'Done ✓' button (or a tap outside). Open date box no longer turns white with faint text in dark mode. Browser-tested.
- 27 Nov 2026 (dd): Directors: the FLIGHT goes on whichever leg COLLECTS at an airport. Widget: outbound airport pickup → flight number + landing time on the Journey page (b-out-flight); return airport pickup → return_flight_number + return_flight_landing_at on the Return page; both → both; optional departure flight stays on the details page. Each landing time is paired with its leg's pickup time (pairTimes). Server requires per leg (error keys flight_* / return_flight_*), stores each leg's own flight + landing; an old page sending one flight for a home→airport return is mapped onto the return leg. Fixed a load-order crash found in browser testing (airportIn ran before AIR_NAME existed). Health check sends the return flight the new way.
- 27 Nov 2026 (cc): Booking widget: a RETURN now has its own page (Journey → Return → Vehicle → Details; one-way stays 3 steps). Return page = return date/time + return pickup + return drop-off + return passengers/suitcases/cabin bags (copied from step 1, editable; vehicle must fit the bigger leg; saved on the return leg), pre-filled as the outbound reversed and editable. Prices (widget.prices return_pickup/return_destination) and the saved return leg use those addresses (TripPricer already supported them); meet & greet + the met flight follow the return collection point; summary shows return date/time and addresses. Browser-tested locally (no JS errors).
- 27 Nov 2026 (bb): AI phone agent brought up to the 27 Nov booking standard (still NOT live — waiting on the directors' ElevenLabs agent + Twilio number, steps at /phone-calls/setup): phone booking requests, messages and 'needs you' calls alert CRITICAL; every request/message is emailed to the office with every detail FIRST (VAT → admin@, else PVT), so a failed save can't lose it; booking tool now takes flight landing time, return pickup address/flight, child seats, VAT invoice + company/VAT no/address (re-add the new parameters in ElevenLabs if the agent was already set up); call page shows every detail and 'Review and confirm booking' carries return/VAT/landing/seats into Paste a booking notes (PhoneCall::intakeFields) — intake is single-leg, so the return leg is added after confirming.
- 27 Nov 2026 (aa): Customer page shows 🏢 business account (Active/Pending/none) + 🔑 online login set/not set; business account contacts list shows Online login. (Claude can't see the live DB — this lets the directors check Claire/everyone themselves.)
- 27 Nov 2026 (z): Business accounts page now has ✅ Approve account / Suspend (accounts.approve / accounts.suspend). Self-registered company accounts start 'Pending approval' and previously could not be switched on in the app. Approve emails the main contact once (how to book on account, monthly VAT invoice, terms). Our VAT number per the 16 Nov Sixt reply: GB527220417 (invoices read CET_VAT_NUMBER from .env — confirm it's set).
- 27 Nov 2026 (y): Gap review closed (every form field incl. via stops now kept in the log; Make booking from this carries stops and seats): (a) the BROWSER phone check also no longer blocks (it was still stopping presses before they reached the server); (b) 'Page expired' (419 token) on /widget/book or /book is captured in bootstrap/app.php: logged with every detail, critical alert + office email, customer sent back with details kept; (c) a red message that stops a press in the browser is logged ('blocked') via POST /widget/book/beacon; (d) a form filled in and left without booking is logged ('abandoned') and cet:abandoned-bookings (every 5 min) alerts the office after 15 min if no booking arrived from that email/phone. Open: customer confirmation emails for NORMAL web bookings still depend on Settings → widget customer emails (off by default) — confirm it's on.
- 27 Nov 2026 (x): NEW RULES (directors, after JELD-WEN failed to book twice): (1) A LOST BOOKING IS WORSE THAN A DUPLICATE — the website never blocks a 'repeat press'; every press saves, a likely double click is labelled 'Possible duplicate of CET-…' (booking page + alert + office email). (2) A PHONE NUMBER NEVER BLOCKS A BOOKING (any customer): a wrong-looking number is saved and flagged in meta['passed_through'] (booking page, alert, office email 'Check before confirming'); every other check unchanged — directors rejected relaxing notice/flight/VAT/cash-on-return rules. (Claire's +33 number was always valid, so it wasn't her blocker.) (3) cet:booking-health-check: a test customer books MAN→JELD-WEN (VAT) and a Sheffield⇄MAN return through the real form every 15 min and after every auto-deploy, inside a rolled-back transaction with mail/alerts off; critical alert to both directors if it fails. (4) Booking form shows 'Having trouble booking? Email/WhatsApp us'. Claire Green emailed by the office for her details 27 Nov 11:58.
- 27 Nov 2026 (w): Backups completed (App\Support\UnsavedBookingBackup): a website press that saves no booking OR crashes, on the widget AND the /book page, sends a critical 'Website booking NOT saved' alert + an office email with every detail typed (VAT → admin@, else PVT). Spam-bot honeypot presses are logged as 'spam' and never alert; an invalid voucher on /book is logged as refused. VAT number display on the booking page was built then dropped at the directors' request (it's on the VAT invoice).
- 27 Nov 2026 (v): Directors: Rolls-Royce 'On request' is only notified when the customer actually SUBMITS it (quote request, critical); price look-ups showing Rolls-only On request are never alerted or listed (hidden in every Booking log view). Quote requests now ALWAYS email the customer a 'Quote request received' copy (same layout as the confirmation: chosen vehicle, full journey, 'not a confirmed booking', price to be confirmed) even with automatic customer emails off; once the office prices it the normal confirmation wording returns. Rule exception added to CLAUDE.md.
- 27 Nov 2026 (u): Claire Green's 27 Nov rebook (MAN T2 → Bessemer Park, 7 Dec) logged as a press with NO booking: most likely her phone reopened an old copy of the page whose one-off code pointed at an earlier booking, so it was treated as a repeat. Fix: a repeat now only counts if it's the SAME journey (pickup + time) made in the last 30 min, otherwise a new booking is made; a press that saves nothing sends a critical alert AND emails the office every detail typed (VAT → admin@, else PVT). Booking log: every entry has Full details (everything typed, never passwords) and "Make booking from this" (prefills New booking, nothing saved until the office saves). Rolls-Royce-only On request rows hidden. Claire's trip to be entered by hand.
- 27 Nov 2026 (t): New website bookings + quote requests alert as CRITICAL (never muted by 'critical only'; repeats too). Booking log: phone cards with Open booking button, always records chosen vehicle, Rolls-Royce-only 'On request' not logged, repeat presses linked by email. Matt Bruce + Rebecca Fielding warm emails sent 09:20 as Abdi (OutreachMail fromName/signature). Claire Green (JELD-WEN) rebooked 27 Nov — booked without card step (account or non-VAT); her log row was a repeat press.
- 27 Nov 2026 (s): Business leads page (Admin & data → Business leads, /business-leads): past customers with company email domains not on an account, grouped by domain, most trips first — warm leads for personal open-account emails. Batch J (UHY Hacker Young Sheffield, Royston Parkin) loaded into Outreach via migration. Open-account link auto-added to outreach FOLLOW-UPS/replies only. Account sign-up alerts verified live (office email + welcome email). Warm leads: Matt Bruce (Hawsons), Rebecca Fielding (Gradconsult) — personal emails, Abdi signs. DKIM not enabled for the domain (SPF ok, DMARC p=none).
- 27 Nov 2026 (r): Live push to both directors when a website customer sees 'On request' for a normally-priced car (price_on_request). Source tracking: website bookings now save fbclid/ttclid/msclkid and the CET website page the form was opened from (ref_page, from the iframe referrer; never another site); Booking::heardAboutKey adds meta / microsoft_ads / website ('Website (Google search or direct)'); booking page shows 'Source: … · booked from …'; the existing sources report picks these up. No migrations.
- 26 Nov 2026 (q): Silent booking log (`booking_attempts` table, MIGRATION): every widget + /book submit recorded as booked / quote_request / refused (with errors) / error / submitted, plus every price lookup that showed 'On request' (once per route per 10 min). Admin & data → Booking log (/booking-log, admins). 90-day retention via cet:prune-gps.
- 26 Nov 2026 (p): 24/7 price watch `cet:price-watch` every 15 min (standard MAN/LBA/EMA ↔ Sheffield/Rotherham/Barnsley/JELD-WEN/Courtyard journeys, both ways; critical push to both directors if 'On request', wrong fare or Google Maps not answering; alert type price_watch). Directors' rule restated: VAT ticked = admin@ (Ltd), no VAT = PVT inbox, whoever the customer is (company-based VAT reverted).
- 26 Nov 2026 (o): JELD-WEN (Claire Green) MAN→Sheffield return became a website QUOTE REQUEST. Cause confirmed live: choosing the plain 'Sheffield, UK' (no street/postcode) when Google can't measure it → 'On request'. Fix: an address naming Sheffield/Rotherham/Barnsley/Chesterfield as its town keeps the fixed airport fare (QuoteService::namesHomeTown; HomeTownFixedFareWithoutGoogleTest). Quote requests now a sticky dashboard warning, pushes say 'price needed', email subject 'QUOTE REQUEST: price needed'. Inboxes: admin@ = VAT bookings, PVT inbox = non-VAT (directors). Staging shows full error traces publicly (APP_DEBUG) and Imunify360 bot protection serves a 'One moment please' page — both worth fixing on the host. No migrations.
- 26 Nov 2026 (n): STEP / UK Fusion Energy (was UKIFS; Katy Jarvis-Morgan) replied to outreach, sent their (UKAEA-template) new supplier form; filled + CET letterhead, signed by Abdirazak Hassan, sent 26 Nov. They don't pay proforma: PO number on every job, VAT invoice after, 30 days. Business cards: print-ready PDF in docs/growth/print/business-card/ (plain navy/gold, QR to website, solid gold lines). Rules: email REPLIES short (house-email-style.md REPLIES section; Hi/Kind regards Matthew); no question/poll social posts; ChatGPT image prompt per graphic. Hourly inbox check scheduled task created but needs the Microsoft 365 connector added in claude.ai Routines; Outlook connector is read-only (no drafts/attachments until Mail.ReadWrite granted). GMH drivers who drive two cars = one directory entry per car (name includes car type).
- 25 Nov 2026 (m): Independent review of (k)+(l) before deploy, fixes: web booking keeps the phone the customer TYPED on the booking (meta contact_override, `Customer::typedPhoneIfDifferent`) when the email matches an existing record; phone matching accepts any stored format (Phone::variants); a CANCELLED return is not collected on the outbound; a price/method edit keeps a deposit that only lives in the payment text (`Booking::paidTowardsFare`); driver notes: old number pattern restored + catch-all hides the note if any number remains; REVERTED (locked rules / not asked for): return-leg cash collection, rotation auto-allocating office bookings (stays Pending), clock-change time handling (times save exactly as before — directors: never refuse or flag a booking for its time); office "Send" message and compliance alerts to the office deliver again (`deliver_now`). NEW: 💳 'Payment received' alert (dashboard + push, `payment_received`) for every fresh card payment on any booking, once per payment, only payments < 2h old. Alert pop-ups capped at 3 + '+N more'. Faster profit/payroll/bookings list (eager loading). No migrations. Open: driver error messages shown twice; widget has no wheelchair option; /rotation has a 'set next' form though documented read-only; 'Collected today (paid)' counts only bookings MADE today (ask directors).
- 25 Nov 2026 (l): Full-system QA, office/dispatch/back-end tracks — fixed: MONEY office cash returns (non-airport) now collect both legs on the outbound; a cash trip starting with an airport pickup collects the return on the return leg; profit counts driver pay once; account jobs billed by the Ltd; payment line refreshed after an edit (payment_edited_at) · PRIVACY driver notes/car link blank every phone format (driverSafeText) · SECURITY confirm() prompts JS-escaped (JsText::q, 16 views); Settings keys/payments super-admin only; password change needs current password + signs out other sessions; no email ever attached to an existing customer from a web booking · RULE customer WhatsApp never auto-delivered (status manual) · RELIABILITY per-route rate limits (ThrottlePerRoute — a shared counter 429'd real bookings); refused-booking alert needs a real person + per-IP cap; ETO import comma/BOM/£ + never invents a pickup; 3 admin 500s · DISPATCH drivers can only decline offers (office alerted); stuck open jobs don't silence alerts + "Job still open" alert; rotation allocations are real allocations, skip expired docs, no crash without a pool airport; Pending clears the driver; reassign re-offers + pushes; drivers always get a profile; unpaid alerts spaced; ack repeat marks seen; return leg takes its own airport; hourly edits/titles; company clients book on their own account; Paste a booking reads party/luggage + sets driver pay; phone country returns to UK. Open: return-vehicle price in the office price strip; N+1 queries on reports/bookings/dashboard; GPS-lost alert does not exist (CLAUDE.md updated).
- 25 Nov 2026 (k): Full-system QA, website forms (63 browser runs). Fixed: (1) SECURITY register-at-checkout with an existing phone set a password on THAT customer + signed the stranger in -> only for records created by this booking; web customer matching = email first, phone only if record has no email (`Customer::matchForWebBooking`, used by widget + /book); (2) hourly hire could never submit (hidden drop-off still required); (3) 8-hour notice used the browser clock -> UK time (ukParts/ukMs); (4) after a refusal ALL choices are restored (pax/luggage/VAT/vehicle/stops/seats/payment/terms) + plain-English messages; client blocks return-before-outbound; (5) airport pickup/landing kept in step both ways + server keeps airport-collection legs on the landing time; (6) account bookings show the VAT-inclusive price (priceVatOn); (7) doubled postcode collapsed (JS + server); (8) /book never says "payment successful" without a payment; invalid discount code shown to the customer. Test WebsiteFormQaFixesTest. Left: same phone in different formats can still make separate customers; a non-existent clock-change time (01:30 on the spring-forward night) saves as 02:30.
- 24 Nov 2026 (j): Office New/Edit booking: the Return journey (date, addresses, return vehicle/price/pax/flight) is now its own step AFTER Passengers & Vehicle (#return-section; form-v2 no longer builds its own card before the vehicle). Airport box auto-fills from the address (MAN/LHR/LGW/STN/LTN/EMA/LBA/BHX/LPL) unless set by hand. Website date boxes now always show the value that will be sent (picker re-syncs on change).
- 24 Nov 2026 (i): Office New/Edit booking: when the PICKUP is an airport, the flight number + landing time move up to sit straight under the pickup date & time, amber-flagged "needed" with a note; never required (office can save and add it later). Moves back if the pickup stops being an airport. Test AdminAirportFlightPromptTest.
- 24 Nov 2026 (h): Directors: "notify us immediately on a red-box rejection". `App\Support\RejectedBookingAlert`: any server refusal on /widget/book or /book -> CRITICAL push to both directors (alert type web_rejected, passes critical-only) + email to cet.ops_email with name/phone/email/journey/reason (reply-to = customer). One per person per minute. The airport-return fix (g) was deployed first, straight away.
- 24 Nov 2026 (g): WEBSITE BOOKING BUG (customer Graham Bonner, US, MAN arrival 23 Dec + return 28 Dec, £220, emailed admin@ "TRYING TO BOOK"): on a RETURN starting at an airport the widget copied the flight landing time into the RETURN pickup -> server rule return after pickup failed -> widget had NO error display and reloaded empty, so the customer could never book. Fixed: landing time fills the outbound when pickup is the airport (return only when the airport is the drop-off); widget now shows server errors in a red box and keeps the typed details (old()). Test WidgetBookingErrorsShownTest. Any other airport-arrival returns since the landing-time copy was added would have failed silently too. Open: book Graham in by hand + reply.
- 24 Nov 2026 (f): Full display sweep (every page x admin/driver/corporate/public x light/dark x 390/360 px, automated split-word / off-screen / clipped / contrast checks + CSS review agent + driver flow walk). Fixed: night-mode search box & pickers dark-on-navy, pale status chips, red/green badges, driver-screen faint text in light mode, invisible password Show, page titles + refs splitting mid-word (search is an icon on phones; refs nowrap), sidebar shadow strip, Completed button contrast, darker gold links (#8a6100). Left: long emails/URLs may wrap where they must; minor 3-4.4:1 greys on Settings/health pages. CSS v39. Sweep script: scratchpad sweep.js (not in repo).
- 24 Nov 2026 (e): Fix: driver job details labels were splitting mid-word ("PICKU P") because the readability pass set overflow-wrap:anywhere on every card. Now break-word (wraps at spaces only); only .mono emails/refs may break anywhere. CSS v35.
- 24 Nov 2026 (d): Security: drivers could open /invoices (all company invoices + PDFs) and /jobs/day (every job of the day with customers) by typing the address. Both now admin (invoices: admin or that corporate client) only. Sidebar was already role-filtered (drivers see Dashboard, My jobs, My documents). Test DriverCannotSeeOfficePagesTest.
- 24 Nov 2026 (c): Driver job screen fix: header Home icon restored on phones (job screen has no bottom bar), and menu/Home/title are light on the always-dark driver header in light mode (they were invisible). CSS v34.
- 24 Nov 2026 (b): Small design polish (directors said keep current layout, no big redesign; Chronograph concept dropped): phone header tidier (menu icon spaced, no duplicate Home icon, search shrinks so Sign out fits), dashboard tiles compact on phones (mobile CSS was being overridden), bookings filters fold behind one "Filters" button on phones (open on desktop / when a filter is set), booking refs no longer split. CSS v33. Open: settings to super-admin only + audit log, director break-glass CLI, logout other devices, uptime monitor, off-site backups, 2FA.

- **24 Nov 2026 — Full UI audit + readability pass.** Playwright audit of 75 staff pages × light/dark at phone width with an automatic contrast scan. Dark mode failures 1000+ → 83 (token overrides in app.css: --muted/--gold-dark/--line for dark, dispatch cards, calendar cells, inline greys/greens/reds, pale inline panels, date pickers). Fixed sideways scroll (dashboard, bookings, settings), one-line phone header title, deeper WhatsApp green, cookie banner removed from the staff app (kept on public/login pages). asset_version 32. Gap audit findings (backups off-site, 2FA, settings audit trail, etc.) reported to directors; not built yet.

- **24 Nov 2026 — Forgot password for staff.** Abdi got logged out and Maj was unreachable. Login page "Forgot password?" → one-hour single-use reset link emailed to the account email AND (admins) to `cet.recovery_email` (CET_RECOVERY_EMAIL, default admin@) because abdi@/maj@ aren't real mailboxes. Never reveals whether an account exists. `Auth\StaffPasswordResetController`, routes password.request/email/reset/reset.update. Also: awaiting-payment box restyled (was dark-on-dark).

- **24 Nov 2026 — New-booking repeat alerts + never lose unpaid bookings.** Every new WEB booking pushes now (title says PAID / CASH / awaiting payment), then again at +2 and +5 min until a director opens it (booking page sets meta seen_at; acknowledging the alert does too). Unpaid card/quote web bookings: dashboard box "💳 Awaiting payment" (Call / WhatsApp pay link / Mark paid / Open) + pushes (new alert type awaiting_payment) at +1h, 9am daily, 24h and 3h (critical) before pickup. Never auto-cancelled. Watchdog passes `newBookingRepeatsPass` / `awaitingPaymentPass` (needs schedule:run cron).

- **24 Nov 2026 — Unpaid card/quote web bookings off the Jobs list.** Directors: they aren't jobs until paid; cash/account web bookings are. `Booking::scopeAwaitingWebPayment` / `notAwaitingWebPayment`; Jobs day view + dashboard schedule exclude them and show a folded "N website bookings awaiting payment" list. They were never pushed to Google Calendar (the Jobs view IS the office calendar now). Office-made bookings unchanged.

- **24 Nov 2026 — Surname required on website bookings (option A).** Widget + public book page: separate First name / Surname boxes (customer and, when booking for someone else, the passenger), both required; server joins them via `App\Support\FullName::merge` and rejects a one-word name. Office forms unchanged.

- **24 Nov 2026 — Phone number recheck (like ETO).** Directors: a web booking came in with 0789456123 (digit missing). `Phone::problem()` (+ mirror `public/js/cet-phone-check.js`): too short/long/not real. Website widget + public book page BLOCK and ask the customer to recheck (customer and passenger mobiles, server-side too). Admin forms (New/Edit booking, Paste a booking) only WARN and still save. International numbers with + are allowed (8–15 digits).

- **24 Nov 2026 — Calendar title uses the TOWN, not the house number.** CET-04008C read "Awais 80"; `CalendarEventBuilder::placeWord()` skips numbered street parts, postcodes and the country (now SALISBURY). Applies to titles built from now on; existing Google events are never edited automatically. Directors suspect CET-04008C is fake (10-digit number 0789456123, same for booker and passenger).

- **24 Nov 2026 — No reminder nudges for unpaid quote requests.** Directors (CET-04008C, Swindon→Salisbury V-Class): a pending website quote request the customer hasn't paid must not show "Send reminder". New `Booking::scopeReminderWorthy` used by the watchdog reminder alert and the dashboard reminders list. Office bookings unchanged.

- **24 Nov 2026 — Masked-line alerts on every call/text + health report.** Directors: "notify me if there's a call or message". New `Telephony\MaskedActivity::notify` now also pings for switchboard (fallback) CALLS and for calls/texts not linked to a live job (Proxy out-of-session, unmatched SMS). Body-free, 20s dedup. New read-only Claude report `/reports/claude/masking` (counts/timestamps only) so Claude can check Twilio is opening lines and events arrive.

- **24 Nov 2026 — AI phone agent v2: ElevenLabs realtime voice.** Directors said yes; they have a spare Twilio number. ElevenLabs = voice/ears (native Twilio import in their dashboard, no Twilio keys in our app), Command Centre = brain: `Webhooks/VoiceToolsController` tools /webhooks/voice-tools/{time,quote,booking,message} (?secret=, caller_id/conversation_id via ElevenLabs dynamic variables) + post-call webhook /webhooks/elevenlabs (secret or ElevenLabs-Signature HMAC, ELEVENLABS_WEBHOOK_SECRET). Super-admin setup page /phone-calls/setup has the prompt, tools and URLs to paste. Booking requests/messages alert live (ai_call). No migration.

- **24 Nov 2026 — AI phone agent (v1, Twilio speech + Claude).** Directors' choices: answer + quote + take booking (office confirms via Paste a booking), ring directors when needed (else take a message), new Twilio number, NO texts to callers. `Services/Voice/PhoneAgent` (quotes ONLY via QuoteService+TripPricer like the widget), `Webhooks/AiVoiceController` (/webhooks/ai-voice, /turn, /dial-done, /status, ?secret=), `phone_calls` table (migration 2026_11_24_000001), admin page /phone-calls (sidebar AI phone calls), alert type ai_call. Config cet.ai_voice.* (CET_AI_VOICE_*). Not live until a Twilio number points at it. Open: directors want a human-sounding voice like a TikTok demo; v1 uses Twilio <Say> + <Gather>, so 2-3s gaps. Next step proposed: ElevenLabs/Vapi-style realtime voice calling our tools.

- **23 Nov 2026 — Softer plate blur on all 149 posts.** Directors: the pixel-block blur looked unprofessional. New `scripts/cet_soft_blur.py` (feathered gaussian, keeps the plate's shape, still unreadable) used by `genn.py`. Next: rewrite posts as location/route posts (e.g. Heathrow T3 Upper Class Wing) in the izec style once directors confirm photo locations.

- **23 Nov 2026 — Batch 3 social posts from 9 new photos.** 14 images in `resources/social-media/b3/` (boot with 2 big cases, rear seats/water/USB, E-Class at dawn, hotel entrance), NIGHT theme, all plates blurred (pre-blurred copies b1–b9 in the photo library). Captions/schedule 16–29 Jan 2027 via migration `2026_11_23_000003_social_batch_three` (needs migrate; auto-deploy runs it). `genn.py` gained `band` for wide photos.

- **23 Nov 2026 — Social posts redone in the NIGHT theme (all 135).** Directors rejected the white design; studied competitor (izec) + TikTok design-skill videos (UI/UX Pro Max, Impeccable, Taste). Dark full-bleed photo, Archivo Bold headlines (directors chose C6), solid gold key word, gold Book online button, no phones, plates blurred. VAT story now uses the office-block fleet photo. New `cards/genn.py` + `scripts/cet_snap_reel.py` (TikTok-style fast-cut Reel: ivory title cards, one-word gold caption chips); demo `resources/social-media/prep-snap-reel.mp4`. Open: directors to film a talking-head clip for the split-screen format; decide on scheduled morning/evening briefs.

- **23 Nov 2026 — Driver job showed the booker, not the passenger (CET-B3411F).** Editing the booking saved the booker's name as the name override, so My Jobs still said "Claire Green". `Booking::passengerNotBooker()` now swaps any name matching a known booker (booked_by / booker_name / "Booked by X" in calendar notes, whitespace-insensitive) for the passenger (lead_name, else calendar Customer Name). Contact form no longer overwrites a known passenger with the booker. Tests added. No migration.
- **23 Nov 2026 (6)** — Directors chose reel ending C "headlights" (from 4 options: name board, arrivals board,
  headlights, number plate): all 12 reels now end on templates/end-headlights.mp4 ("Your driver is waiting.").
- **23 Nov 2026 (5)** — CET-B3411F: (1) notes phone parser was UK-only and cut the passenger's German mobile
  "0049 151 204 22619" to "0049 151 204 22" — now reads international numbers in full (+, 00, up to 15 digits);
  (2) driver job showed the BOOKER (Claire Green) not the passenger: when booked_by + lead_name differ, the
  passenger now always wins in displayName()/displayCustomerName(). Office must re-pick the full number on that
  booking ("Use this number") and set the name to the passenger.
- **23 Nov 2026 (4)** — Reel ending redesigned to be memorable (directors: old end card "boring and dry"): the
  meet & greet NAME BOARD with the CENTRAL logo rises over the car at night, then "Your driver is waiting." + website
  pill + "Book online 24/7" (.claude/skills/video-edit/templates/end-nameboard.mp4, applied to all 12 reels with
  scripts/cet_swap_end.py). Audi photo removed from batch 2 (old car, not fleet) — b2/06 now an E-Class at the terminal.
- **23 Nov 2026 (3)** — Reel end card ("Book online or call 0114 399 8242" in cet_tiktok_text.end_card_png) changed to
  "Book online 24/7"; the 6 slideshow reels re-rendered and the 4 footage reels (prep, fleet, wedding, courtyard) had
  just that line patched (edit unchanged). No phone number left on any social media image or video.
- **23 Nov 2026** — Social batch 2 (directors: "use all the photos"): 24 posts / 49 rows, Wed 16 Dec → Fri 15 Jan, from the unused photos (Ghost, The Grand, rainy wedding cars, Sheffield landmarks, fleet, airports) in the gold/black concierge cards. 3 carousels, 2 Stories + 2 music-free Reels, 4 LinkedIn, 4 Google. Migration `2026_11_23_000002_social_batch_two` (needs `migrate`), images `resources/social-media/b2/`, spec `cards-batch2.json`. Plates and bystander faces blurred; book-online CTA only. Open: the shared `cet_photo_reel.py` end card still says "call 0114…" (batch-2 reels skip it; older reels carry it).
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
- **9 Nov 2026** — Edit booking now has the New-booking options: Vehicles (2 × V Class etc., capacity ×
  count), Meet & greet with name board + meeting point, Wheelchair, How it came in, and (Payment section)
  Corporate account + optional cost code + PO/reference. Only fields the form sends are touched. No
  migration. Test: EditBookingMoreOptionsTest. Earlier today: multi-vehicle bookings, cost code optional,
  luggage-0 edit now sticks (Nicola Wright / Forged Solutions, 2 × V Class, VAT, £240).
- **9 Nov 2026** — New booking: pickup date & time reminder (directors kept forgetting it). Amber note
  under the field while it's empty; Next / Confirm without it turns it red, pops up a reminder and
  jumps to the field. Front-end only.
- **10 Nov 2026** — Claude read-only outreach report: super admin makes a key on Settings → Claude
  access (hash stored only, shown once); Claude reads `GET /reports/claude/outreach` with
  `Authorization: Bearer <key>` (today's sent/queued/batch awaiting approval, replies, due follow-ups,
  pipeline). No bookings/customers/money; changes nothing. No migration. Test: ClaudeReportTest.
  Also: social planner artifact (posts + ChatGPT prompts + first two real Reels), video skill styles.
- **10 Nov 2026** — Batch check before approval: First Travel already replied 3 Nov (registration link,
  supplier.fts-tech.co.uk/register) → Replied. Vulcan Seals is an existing customer → first email goes to
  Jane McGuinness personally (VAT registered, all travel nationwide, account; no closing question, directors'
  wording). William Cook first email mentions a recent Parkway Avenue visitor we looked after (no customer
  named, GDPR; Josh Polesky is Solar Turbines, not William Cook). Migration 2026_11_10_000001.
- **10–11 Nov 2026** — Bounce fixes (B. Braun → info.bbmuk@bbraun.com, bbraun.co.uk has no MX; Unipart → cs@;
  Irwin Mitchell on hold; Jane follow-up 18 Nov). 11 Nov: directors asked Claude to approve and schedule the day's
  batch ("do it yourself… start sending for 9am"): migration 2026_11_11_000001 fixes Addison Lee's follow-up to
  partnerships@ + the original subject and queues the 10 from 09:00, 5 min apart (approver Abdi). Social: planner
  artifact with 3 Reels (Remotion styles: futuristic HUD, editorial airport, fleet slideshow), GitHub fonts
  (Hubot Sans/Mona Sans/Geist Mono), bright natural grade, royalty-free Mixkit house music baked in.
- **11 Nov 2026 (pm)** — Outreach email font: tried Aptos 16px (12:43) but it looked massive on the phone;
  directors chose the original **Arial 14px** (the Forgemasters email look) — reverted, keep it. Also live today:
  all-London fixed fares (ETO Central London + £20, built in), no drag from home, return-time reminder on
  new booking. Open: Mercure Sheffield bounced (need a contact / hold); regenerate the Claude report key.
- **11 Nov 2026 (eve)** — Booking confirmations, cancellations and invoices now show the office LANDLINE
  0114 399 8242 (was the 07405 mobile); invoices use Settings → Invoice phone if set. WhatsApp links stay on the
  mobile. Open: switch other customer pages (thanks/book/paid/error) to the landline? — directors to confirm.
- **11 Nov 2026 (late)** — Contact number: ETO-style country picker (flag + code) on new/edit booking; saved
  as +E.164 read in the picked country (`Phone::normalise`), '+'/'00' auto-picks the country. Old 07… customers
  still matched (`Phone::variants`) so no duplicates.
- **11 Nov 2026 (late)** — Confirmation email money (directors): VAT bookings list each journey EX-VAT, then
  Subtotal (excl. VAT), VAT (20%), Total — replaces the "Includes VAT" note. US numbers display +1 734 629 2334.
- **11 Nov 2026 (late)** — Outreach: EG Chauffeurs marked Replied (no auto follow-up); Mercure H6628@accor.com
  bounced → on hold, no address (migration 2026_11_11_000002 — needs `migrate`). Pricing review agent run: findings
  being fixed with the directors (no-postcode = Sheffield default, PA3/Oban collision, Doncaster→Exeter typo).
- **11 Nov 2026 (night)** — Pricing (directors): fixed fares for the home catchment only (Sheffield/S20,
  Rotherham, Barnsley, Chesterfield on Sheffield fares). Doncaster/Worksop/Mansfield/Matlock/Bakewell → mileage
  guide. Radius stays 15 mi; near-end drag = £1/mile of the extra run when the nearer end is ≤35 mi (Doncaster →
  Heathrow ~£370, was £515); both ends >35 mi keep full furthest-end drag (Kidderminster £330). No-postcode
  addresses only get Sheffield fares if measured inside 15 mi; PA3 exact (Oban fix). Exeter typo now moot.
  Directors to confirm: Southend = Gatwick price, Bristol/V-Class long-run rates (agent review list).
- **11 Nov 2026 (late night)** — S25/S26 → Manchester Airport only: Exec £125, Estate £135, Minibus £165,
  XL £180, V-Class £190 (other routes unchanged). Sales kit prices updated to live fares. Outreach: EG reply now
  counted (replied_at, migration 2026_11_11_000003 — needs `migrate`); Claude report lists due/overdue follow-ups
  (dashboard showed 10 due — check which are real follow-ups vs replied prospects with a stale date).
- **11 Nov 2026 (late night)** — Outreach page: new "Total emails sent" box (every emailed activity) and every
  stat box is a link to its list (`?view=sent|week|replied|meetings|accounts|contacted`). Of the 10 "due": 2 real
  (Clarity, Corporé — capped at 3 follow-ups/day), 7 have no email (SMP, Rolls-Royce, Carey, Birchwood, Bekaert,
  Frasers, Danieli — contacted by form/hand), TBR replied with a stale date. Directors to decide how to handle.
- **11 Nov 2026 (late night)** — Vehicles: ETO-style "Available on" per vehicle (Frontend & backend / Backend
  only / Off; stored in vehicle_meta). Estate = Backend only (admin form + price strip, never customers). Favicon:
  /favicon.ico confirmed showing the circle-C; Google results still show the old icon (refresh takes up to ~3
  weeks from 4 Nov). Dates for comparison: sitemap ~30–31 Oct, indexing 31 Oct–4 Nov, Ads paused 4 Nov.
  Offered: noindex on staging (staging/widget is in Google) and a weekly bookings-by-source report.
- **12 Nov 2026** — Outreach: directors said "schedule the emails to send from 9am". Migration
  2026_11_12_000001 holds Keepmoat (email@keepmoat.co.uk unconfirmed) and queues today's batch from 09:00, 5 min
  apart, skipping any domain with no mail server (all 10 checked had MX). Video: POV reel style script added;
  directors disliked the red/yellow banners — choosing between TikTok Classic / viral captions / background box.
  Next: "How did you hear about us?" + weekly bookings-by-source report (directors said yes).
- **12 Nov 2026 (08:00)** — Directors: "too many chauffeuring companies, I want more businesses". Batch now
  businesses first, max 1 NEW partner email/day (`outreach.batch.max_new_partners`; partners = segment words in
  `Prospect::PARTNER_WORDS`); follow-ups to partners still go. Migration 2026_11_12_000002 re-queues today's batch
  that way (only if nothing sent). Also live: "How did you hear about us?" (website + admin forms, meta.heard_about)
  and Reports → Bookings by source (weekly, marker at Ads paused 4 Nov).
- **12 Nov 2026 (pm)** — Square webhook: /webhooks/square 500'd on every verified non-tip/non-fare event
  (called coverReferenceFromWebhook on the tip service) — fixed; it also accepts the "CET Tips" Square app's own
  signing key (Settings → Square card payments → "CET Tips" key). Square warned it stops CET Tips deliveries 18 Nov.
  Tips now follow the company: PVT LTD → Stripe (TIP- ref, webhook + reconcile log it), Ltd → Square. ITP Aero held
  (mailbox rejected us). Rebecca Fielding (Grad Consult, past customer) — personal email drafted, awaiting directors.
- **13 Nov 2026** — Social: bookings-first plan `docs/growth/social/plan-2026-11-13-bookings.md` (past-customer
  Christmas message, reviews, GBP weekly, outreach, hotels/venues; then 3 Reels/week). Video tool: POV/photo reels,
  TikTok-native text styles (A classic, B viral, C box, D mix — D used), music-free export (trending sound added
  in-app), photo reels keep original colours, new end card (real CENTRAL wordmark). Three Reels ready: prep, fleet,
  wedding (media in gitignored docs/growth/data). Square CET Tips webhook test passed 200 after deploy + key.
- **20 Nov 2026 (late, 2)** — Far-from-home jobs = quote REQUEST on the website (directors): when the drag is 40+
  miles (only happens when BOTH ends are well outside our area, e.g. Kidderminster → MAN), the website shows
  "On request", takes all details, no payment; the office gets a "quote request" alert with the guide price
  (meta office_quote_guide). Admin forms still show the full price. QuoteService 'office_quote' flag,
  cet.office_quote_drag_miles (40). Applies to /widget/book and /book.
- **23 Nov 2026** — Social media redesign #3 "Concierge" (directors: white/gold-word look "looks very AI"): genx.py,
  Schibsted Grotesk, photo-led, sentence case, no coloured words or caps labels, black logo, one gold square by
  "Book online". All 93 images + 6 slideshow reels redone. Copy: NO phone numbers (AI phone line coming), NO PO —
  "VAT-registered business accounts"; every caption now ends "Book online…" (migration 2026_11_23_000001).
  Door-only Story 2 replaced with a full car photo. A missed half-plate on p05r/5 blurred. Adobe + Canva connectors
  suggested (directors must connect on claude.ai; no CapCut connector exists). New batch from unused photos: in progress.
  Then directors: "keep the colours" — genx.py now has gold emphasis words, a gold rule under headlines and a full-width
  black strip "Book online at centralexecutivetransfers.co.uk" (site in gold); cleaner font + layout kept.
- **22 Nov 2026 (3)** — Social media quality redo (directors: videos/images low quality, logo + contact design
  poor). All 87 cards + 6 stories rebuilt with HTML templates at 1440px, JPEG q92 4:4:4 (were 1080px, 55–90 KB):
  CENTRAL wordmark + gold "EXECUTIVE TRANSFERS" lockup, gold website pill + WhatsApp/phone icons, photo textures
  on text cards. Readable plates found and blurred on 7 photos (wedding, Christmas markets, two GBP photos).
  Reels re-exported (prep 2.7→13 Mbps from master; fleet/wedding/courtyard re-rendered from source; 6 slideshow
  reels rebuilt from the new cards). Encode settings fixed in .claude/skills/video-edit/scripts/cet_encode.py;
  renderer saved in .claude/skills/video-edit/cards/. Then the directors chose the WHITE premium theme (genw.py:
  black logo, white canvas, gold accents, black contact strip) — all 93 images + 6 slideshow reels redone in it.
  Fleet + wedding footage reels: every plate now blurred (tracked through the zooms).
- **22 Nov 2026 (2)** — Driver job offer: the note now shows with any phone number blanked "(number via the
  office)" instead of hiding the whole note (CET-535316 showed "a note is on file" for "Meet & greet required").
- **22 Nov 2026** — Driver "Record the cash you collected": pressing Record again now CORRECTS the driver's
  figure instead of adding another line (3 taps on a £95 job showed £285). Button reads "Update" once recorded.
  Migration 2026_11_22_000001 collapses existing duplicate driver cash lines (same booking + same amount).
- **21 Nov 2026 (6)** — Edit booking now saves meta['flight_landing_at'] (booking page "Lands (customer)"); a
  re-booked flight kept showing the old 16:40 while pickup moved to 21:55 (CET-4DA06B). Re-save that booking once.
- **21 Nov 2026 (5)** — Social: every not-yet-posted caption rewritten (45 titles: Nov plan, Courtyard, December)
  to a short hook + 1–2 plain sentences + a comment question, humanizer style (directors' TikTok tip, @wchan28).
  New Holiday Inn Manchester Airport set (resources/social-media/hotel, warm grade, 4:5): carousel Sun 22 Nov
  IG/FB/TikTok, LinkedIn Wed 25 Nov, GBP Thu 26 Nov. Migration 2026_11_21_000002 (posted posts untouched).
  OPEN: directors still haven't posted; scheduling advice given (Meta Business Suite, TikTok Studio, LinkedIn).
- **21 Nov 2026 (4)** — CET-B3411F refund reason reworded to "Vehicle changed to Executive" (migration
  2026_11_21_000001; Square keeps its original text).
- **21 Nov 2026 (3)** — Confirmation email shows refunds (directors, CET-B3411F £72 back after V-Class →
  Executive): under "Payment received £210 by card" it adds "↩ Refunded: £72.00 to your card · date · reason ·
  3–5 working days", from meta['refunds'] on any leg of the trip.
- **21 Nov 2026 (2)** — New/Edit booking form greyed Executive as "Too small" for 3 pax + 0 suitcases + 3 cabin
  bags (CET-34BE8F): the form's JS still checked cabin bags against the cabin-bag count alone. Now uses the same
  rule as VehicleType::fitsLuggage (suitcase = 1.5 cabin bags; spare suitcase room holds cabin bags) for the card
  grey-out and the save warning.
- **21 Nov 2026** — PVT emails were ALL going out from the Ltd (Microsoft 365) mailbox even though Settings showed
  "via Resend": Laravel's Mail::to()->send() overwrites a mailable's ->mailer() with the default before sending,
  so the PVT pick in the 5 booking mailables was ignored (the Settings test used Mail::mailer() directly, so it
  passed). New trait App\Mail\Concerns\PinsMailer makes send() honour the pinned PVT mailer (falls back to the
  main mailer + log warning only if the PVT send throws). PvtEmailActuallyUsesPvtMailerTest sends for real.
  Google delegation (gmail.send) for the PVT Workspace was already in place; Resend key saved and in use.
- **20 Nov 2026 (night, 3)** — CET-4DA06B (return leg repriced £130 → £165 after the trip was paid on the
  outbound) showed "Refund difference £95" while £35 was OWED: overpaidAmount counted the one card payment twice
  (outbound record + return ledger seed, same payment id). Now deduped by payment id, and the refund box never
  shows while a balance is due; it's folded behind a small "↩ Refund £X…" button. Balance links after a part
  payment now say "Booking update … New total / already paid / Balance" (WhatsApp/SMS + email subject).
- **20 Nov 2026 (night, 2)** — Deep Barnsley now S71, S72 (Cudworth, Grimethorpe), S75 (Darton, Mapplewell,
  Dodworth) — directors. Barnsley keeps S70, S73, S74. Migration 2026_11_20_000004 (zone postcodes only). PDF updated.
- **20 Nov 2026 (night)** — Deep Doncaster (directors: Darren's DN7 too far for the town fare): DN6, DN7, DN8,
  DN9, DN12 = every Doncaster fare + £10, all vehicles (derived rules in QuoteService; migration 2026_11_20_000003
  copies the live Doncaster rows +£10). Doncaster keeps DN1–DN5, DN10, DN11. PDF updated. OPEN: which postcodes
  join Deep Barnsley (now S71 only; suggested S72, S75, maybe S36).
- **20 Nov 2026 (late)** — Drag (out-of-area dead mileage) now in BANDS (directors chose "each stretch at its own
  rate"): 0–15 mi free, 15–30 mi £1/mi, 30+ mi £2/mi, rounded to £5. FreeRoamPricer::deadMileageCharge; new
  settings deadmile_band_miles (30) + deadmile_rate2 (2.00) on Settings → Free-roam rates. 40 mi = £35 (was £40);
  Kidderminster 113.9 mi = £185 (was £115) → Kidderminster→MAN £400; Doncaster→LHR drag £5 → £355. Near-end and
  no-drag-from-home rules unchanged. Pricing tests + CLAUDE.md updated.
- **20 Nov 2026 (late)** — Fares (directors): Liverpool John Lennon raised — Executive Sheffield/Rotherham/Barnsley
  £170, S20 £175, new **Deep Rotherham** zone (S62–S66, out of Rotherham) £175, new **Deep Barnsley** zone (S71, as
  in ETO) £175; Minibus/XL/V-Class up by the same £ per zone; Chesterfield +£20 (Exec £185). **Doncaster DN1–DN12
  back on fixed fares** (was mileage since 11 Nov). Migration 2026_11_20_000002 writes only those zones + Liverpool
  rows (keeps deposits). Fixed-fares PDF in docs/pricing/. OPEN: confirm Deep Barnsley = S71 only, and the
  Chesterfield/bigger-vehicle Liverpool raises.
- **20 Nov 2026 (eve)** — Edit booking rebuilt (directors: "exactly the same" as New booking): BookingController@edit
  renders bookings.create in edit mode, filled from the booking via session old-input (bookingFormValues) — same
  layout, live price cards per vehicle, "Use £X" re-quote; saves through update (PUT), nothing new created, no
  customer email, calendar untouched. Return option hidden (each leg edited alone); waiting-time block added;
  consent/email/preview hidden. Update now takes postcodes, flight landing time (airport pickup: landing = pickup,
  UK time), passenger vs booker (lead_name/booked_by kept separate), VAT company details (VAT can't be switched off
  once paid). cet-forms.js auto-quote never overwrites a price already on the form (sw v38). Old edit.blade removed.
- **20 Nov 2026 (pm)** — Refund difference (JELD-WEN CET-B3411F downgraded V-Class → Executive after paying £210):
  the paid figure was capped at the new price, hiding the £72 overpaid. Now Booking::overpaidAmount() shows it and a
  "💷 Refund difference" box on the booking sends it back to the card via Square (/v2/refunds) or Stripe (/v1/refunds),
  office-confirmed, idempotent — or records a refund done by hand. Refunds live in meta['refunds'] and reduce the paid
  figure. Edit now recomputes AUTO driver pay when price/vehicle changes (was only "Set price"); one-off migration
  2026_11_20_000001 fixes B3411F's £157.50. Refund for B3411F itself still to be done by the office.
- **20 Nov 2026** — Booking page luggage: a suitcase now counts as 1.5 carry-ons, so spare suitcase room takes
  carry-ons (VehicleType::fitsLuggage / luggageSpace; same rule in the widget JS). E-Class (2+2): 0 cases + up to
  5 carry-ons, 1 case + 3, 2 + 2. Was: any 3rd carry-on greyed the E-Class out. Directors' call: 5 carry-ons max.
- **19 Nov 2026 (late)** — Directors chose photo filter **B · Warm premium** (removes the teal cast, warmer light,
  deeper blacks, soft vignette). Re-made every photo post, story, carousel and photo-slideshow Reel in
  resources/social-media with it (text cards and filmed Reels unchanged). Keep using B for new photos.
- **19 Nov 2026 (eve)** — Social: (1) December plan 2–15 Dec loaded on deploy (migration 2026_11_19_000003,
  SocialDecemberPlan): 31 posts, all Ready with files (resources/social-media/d01–d15): 4 new photo-slideshow Reels
  (Christmas airport runs, night out, winter weddings, which car), 2 carousels (party night, NYE ride home),
  2 stories x WhatsApp/IG/FB, LinkedIn cards, GBP photos. (2) Meta connection (read-only): MetaInsightsService +
  `cet:social-sync` nightly 03:20 UK — FB/IG followers into growth log, last-60-day post numbers, matches planned
  posts (marks Posted + link), adds posts made in-app. Connect card on Social → Overview (super-admin; Page ID +
  Page token in Settings, token never shown). Setup steps: docs/growth/social/meta-connect.md. Not connected yet.
- **19 Nov 2026 (pm)** — Social: EVERY planned post now carries its finished photos/video in the Command Centre
  (post page: gallery + ⬇ Download per file; planner shows thumbnails) and is marked Ready. Files ship in
  `resources/social-media/` (25 MB, committed deliberately: finished exports only, real CET photos + CET text
  graphics, plates blurred, no customers; never raw footage). Office can upload their own files per post
  (stored storage/app/private/social-uploads, admin-only route social.media). New platform "WhatsApp Status";
  6 stories x WhatsApp/IG/FB planned 20–25 Nov. Migrations 2026_11_19_000001 (media column) + 000002
  (SocialMediaLibrary::apply). Sheffield-evening post now a real photo (dropped "Illustrative image").
- **19 Nov 2026** — Postcodes + airport fence. (1) Web bookings: a pickup/drop-off with no postcode (Google's
  autocomplete text omits it, e.g. "19 High View, Sheffield, UK", CET-6CFDDC) now gets it from Google on save
  (after pricing, so the price never changes; airports skipped) — GeocodingService::postcode (cached 30 days,
  5-min back-off when Google fails). (2) Driver screens show Booking::driverAddress(): display address + postcode
  when missing (display only — booking/calendar untouched), so existing jobs get it too. (3) Driver couldn't
  Complete at Heathrow T5: airport ends now use a 2.5-mile fence (cet.airport_radius_metres 4023) instead of 1.5 mi;
  mid-route still blocked. Booking::geofenceRadius().
- **18 Nov 2026 (late)** — Courtyard by Marriott Sheffield night photos → photo Reel + 4-photo carousel, plates
  blurred (docs/growth/data/video/2026-11-18-courtyard, not in git). Planned as Ready: Reel Mon 23 Nov 19:00
  (TikTok/IG/FB), carousel Tue 24 Nov 19:30 (IG/FB) — migration 2026_11_18_000005. No hotel partnership implied.
- **18 Nov 2026 (eve)** — Social: the full prepared plan (plan-2026-11-10.json, 31 posts never posted) loads into the
  Command Centre on deploy (migration 2026_11_18_000004), moved to start Thu 19 Nov (same times), + wedding Reel
  (Sat 28 Nov) + 20 ideas incl. 7 "answer a common complaint" Reels. Starter button now loads this set. New
  Planner tool "Learn from a post that did well": paste a viral post, Claude breaks down hook/format/topic and
  writes a CET version to the ideas bank (no scraping/logins). Directors' 2nd TikTok (Agent Reach scraper):
  NOT installed: logs into LinkedIn/IG with account cookies, against their rules, could get CET accounts banned.
- **18 Nov 2026 (pm)** — Social Media Command Centre (/social, sidebar Marketing → 📱 Social media), modelled on the
  directors' TikTok reference: Overview (followers per platform from the shared growth log, views by week, posting
  consistency gauge vs weekly goal (default 10), best day, top 5, coming up, notes), Planner (week grid + ideas bank +
  "Suggest 6 ideas" via Claude), Posts (search/filter/sort, vs-average), per-platform tabs (what's working), post page
  (copy caption, ✓ Posted, numbers, Audit vs platform average). One-tap 2-week starter plan from the 3 ready Reels.
  Nothing posts anywhere: numbers typed from each app's insights (free, no APIs). New table social_posts (migration).
- **18 Nov 2026** — Outreach on AUTOPILOT (directors: "I don't have to always say"): cet:outreach-auto-queue runs
  weekdays 09:00–11:00 UK every 10 min, queues the day's batch 5 min apart (same rules: limit, businesses first,
  DNC/no-MX skipped), once per day. Off switch: Setting outreach_auto_send=0. Today's batch queued from 13:20.
  Still check the inbox for bounces/replies.
- **17 Nov 2026 (eve)** — Set-off nudge: with an office-set lead time it fires at lead time + 15 min
  (StatusWatchdog::setOffNudgeAt), unless live flight data shows an earlier landing (then the flight-based time).
  No lead time = smart estimate as before. Getting-ready prompt still at the lead time; urgent at pickup−10.
- **17 Nov 2026 (pm)** — SEO check (docs/growth/seo/seo-progress-2026-11-17.md): pages ~2 weeks old, not ranking yet
  for money terms (normal); next: GBP, reviews, Search Console check, free listings, gap pages. New read-only
  reports/claude/sources (counts only: weekly bookings, channel, new vs returning, heard-about, utm).
- **17 Nov 2026 (am)** — Missed alert: Richard Mauer's paid web booking (09:55) pushed to nobody — both directors were
  on live jobs and admins on a job got NO push. Directors' rule now: alerts push as NORMAL (sound) even mid-job / app
  open; only a manual "hold my alerts" stops them. On a job the dashboard ALARM/chime stays muted
  (no emergency sounds with a passenger) — feed mute_sound = busyForAlerts. Gmail: user's app had inbox sync off.
- **17 Nov 2026** — Outreach batch queued from 09:00, 5 min apart (migration 2026_11_17_000001): Forgemasters, CMAC,
  Flint Bishop final follow-ups; Beatson Clark, Swann-Morton, Leviat, Genuit/Polypipe, BHP, Sheffield Hallam, Passenger Plus.
- **16 Nov 2026 (eve)** — SIXT ride (Fabiola Fofana, Country Manager UKI/IE) replied to our 11 Nov partner email asking
  if we're VAT registered; reply sent via outreach mailer, threaded (migration 2026_11_16_000003): Ltd, VAT GB527220417,
  company 15749931, SCC operator licence (no number, directors' choice). OutreachMail supports In-Reply-To.
- **16 Nov 2026 (13:30)** — New booking: "Passenger name — if booking for someone else". Customer = booker (email/
  phone/payment, e.g. Nicola Wright of Forged Solutions); passenger (e.g. Erik Toth) goes on the job via meta lead_name
  + booked_by. A typed name differing from the matched customer is kept as the passenger, never swapped back.
- **16 Nov 2026 (13:10)** — New booking: choosing a business account with VAT invoice ticked fills Company name, VAT
  number and billing address from the account (only empty/auto-filled boxes; typing is never overwritten).
- **16 Nov 2026 (pm)** — Search: top box searches EVERYTHING as you type (Admin\QuickSearchController, route
  quick-search): bookings (ref/ETO ref/customer/lead/phone any format/email/addresses/flight/driver), customers,
  drivers, vehicles, business accounts, outreach. Bookings page has a Driver dropdown (all their jobs, past+future).
- **16 Nov 2026 (am)** — Outreach batch queued 5 min apart (migration 2026_11_16_000002): Castle Square CF, IFA,
  Marcegaglia follow-ups; Chesterfield Cylinders (enquiries@csc-holdings.com), Metalysis, Iceotope, TWI Yorkshire,
  Technicut, Abbey Forged Products, Parkers Chauffeurs. All domains have mail servers.
- **16 Nov 2026** — ETO import mapped "Incomplete" (unpaid abandoned checkout) to No Show — Justin Hall's unpaid
  ASBTVGa (CET-A53016) sat beside his paid Y8CZVWa (CET-975569) on 20 Nov. Importer now skips Incomplete; migration
  2026_11_16_000001 cancels unpaid No Shows nobody marked by hand. Check Google Calendar for a duplicate event by hand.
- **15 Nov 2026 (night)** — Confirmation "Payment received" heading showed one leg's share (£138) when sent from the
  return leg; now the whole trip payment (£264) from either leg. Customers get ONE confirmation per trip.
- **15 Nov 2026 (late)** — Names: PersonName::short — a title keeps the surname ("Mr Smith", never "Mr") in pay
  statements/driver labels and all customer greetings. Moved flights: outbound flagged meta flight_cleared so it
  stops falling back to the stale calendar block (migration 2026_11_15_000002). Remove the flight from Keith's
  outbound calendar event by hand.
- **15 Nov 2026 (eve)** — Web returns: the arrival flight + landing time now go on the leg that collects from the
  airport (were on the outbound drop-off — CET-F3979B had none; migration 2026_11_15_000001 moves upcoming ones).
  Form: "🛬 Arrival flight" (required) + optional "🛫 Departure flight" (on the drop-off leg), in journey order.
  Flight monitor only acts on pickups AT an airport (a delayed departure flight could have pushed a home pickup
  back). Admin page labels Flight (arriving/departing) + "Lands (customer)". Calendar not edited — add flight by hand.
- **15 Nov 2026 (pm)** — Confirmation email shows "Company: <name>" under the customer on VAT-invoice bookings
  (from vat_details on any leg), matching the invoice's Bill to.
- **15 Nov 2026** — Keith Boldock (BENDITNOW LTD, CET-F3979A/B) paid £264 incl. VAT but the invoice read "£220 paid,
  £44 VAT due" (InvoicePdf only counted net). Fixed: invoices honour the real online amount; return-leg invoice bills
  the company (vat_details from any leg); onFarePaid is locked per booking so twin webhooks can't double-send paid
  emails (he got two). Office to resend a corrected confirmation. Outreach: Chesterfield Cylinders → enquiries@csc-
  holdings.com (resend first email); MJ Gleeson + Parker Bestobell on hold (no working address).
- **13 Nov 2026 (17:00)** — Directors: Abdi/Maj Manchester Airport jobs (exec, either direction) now pay a flat £95
  driver pay (setting director_man_pay; Booking::isDirectorManchesterJob). Auto pay follows on allocation; hand-typed
  prices untouched. Migration 2026_11_13_000005 sets this week's (9–15 Nov) director MAN jobs to £95.
- **13 Nov 2026 (14:00)** — Outreach: today's batch (AMRC, NCM UK, Pricecheck follow-ups; MJ Gleeson, Loadhog, OSL,
  Jefferson Sheard, CTI, Chesterfield Special Cylinders, Little's Chauffeur Drive) queued by migration 2026_11_13_000003,
  5 min apart from deploy, approved by directors in chat. IFA + Marcegaglia follow-ups roll to Monday.
- **13 Nov 2026 (night)** — Read-only booking lookup for Claude: GET reports/claude/booking?ref=CET-XXXXXX (same
  Claude-access key): status, payment, customer name, masked email, online account, email history sent/failed.
  No phones/addresses; exact ref only.
- **13 Nov 2026 (eve)** — Admin-only customer chips (customer + booking page): online account, customer since, trips,
  business account, email/phone on file, VIP (marketing chip dropped — never asked on the form). Email history shows
  Sent/Failed under each type + "Emails (x sent, y failed)". After-response emails now run once (AfterResponse
  helper) — the old dispatch()->afterResponse() piled up in tests. Jessica's paid confirmation was delivered 11:55.
- **13 Nov 2026 (late)** — "Email didn't send" alerts flooded the dashboard (5-min paid-email retry during the
  outage). Now ONE alert per failed email (MailFailure dedupe, 24h after ticked); migration 2026_11_13_000002
  ticks off the existing repeats (keeps newest). Retries continue silently until mail works.
- **13 Nov 2026 (pm)** — Microsoft outage: a web customer (Aegis Energy) clicked "Book & pay" repeatedly while the
  page hung on the office email and got 6 return trips; only one paid (£120, CET-D95DCA/B). Fix: one-off form token
  + button locks after first click; exact repeat within 20 min returns the same booking; office AND customer emails
  now send after the page loads (SMTP timeout 10s); alert failures can't block the payment page; failed emails show
  on the alerts feed. New **⬇ Download PDF** of the customer confirmation next to Preview (admin only) for WhatsApp.
  Humanizer skill added. Directors: delete them. Migration 2026_11_13_000001 quietly deletes (to Trash) the 5 unpaid duplicate
  trips (EE0C1, 88CD4, 63C34, 95EB6, 9FBDC A/B) — same customer, web, pending, unpaid only; no email, calendar untouched.
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
