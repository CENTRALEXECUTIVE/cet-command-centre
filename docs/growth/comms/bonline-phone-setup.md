# bOnline phone setup — 0114 399 8242

Draft scripts and routing for CET's business landline on bOnline (UK hosted VoIP).
Nothing here has been configured. Every item marked `[CHECK bOnline]` must be
confirmed against what bOnline's plan and portal actually offer before building it.

Tone: calm, warm, short sentences, British spelling. Record in a natural voice, not a
"call centre" voice. Leave a half-second pause after each option.

---

## 1. Main greeting (about 15 seconds)

> "Thank you for calling Central Executive Transfers, Sheffield's executive
> chauffeur service.
> For a new booking or a price, press 1.
> For an existing booking, or to check on your driver, press 2.
> For drivers and partner operators, press 3.
> For accounts and invoices, press 4.
> Or stay on the line and we'll answer as soon as we can."

Notes:
- That is roughly 45 words, so about 15 seconds read aloud. Do not add to it.
- Callers who say nothing should fall through to the "stay on the line" route (section 3, Option 0 / timeout).
- Do not say "Sheffield's" if the directors would rather avoid it. Fallback opening: "Thank you for calling Central Executive Transfers."

## 2. Call menu (refined)

| Key | Label | What the caller hears first | Routes to |
|---|---|---|---|
| 1 | New booking or price | (none, rings straight through) | Ring group: both directors (section 5) |
| 2 | Existing booking / driver on the way | "If you have your booking reference or WhatsApp tracking link, it shows your driver's live position." | Ring group: both directors |
| 3 | Drivers and partner operators | (none) | Ring group: both directors (same people, different whisper, see below) |
| 4 | Accounts and invoices | "For invoices, you can also email admin@centralexecutivetransfers.co.uk." | Ring group, then voicemail to the admin mailbox |
| No key / timeout | Default | Replay menu once, then route as key 1 | Ring group |

Refinements to the brief:
- Keep it to four options. More than four loses callers.
- Option 2 plays a one-line pointer to the tracking link. Many "where is my driver" calls can be answered without a call. Keep it to one sentence so it does not feel like a barrier.
- Option 3: drivers and partner operators are usually people we know. If bOnline supports a "whisper" or call label, label it so the director picks up with the right opening (see below).
- Option 4: accounts is low urgency. It can go to voicemail sooner (after about 20 seconds) so it does not tie up both directors.
- Add a silent "9 to repeat the menu" only if bOnline makes it free to do. Do not announce it.

Director pick-up openings (for the team, not recorded):
- Key 1: "Central Executive Transfers, {name} speaking. How can I help?"
- Key 2: "Central Executive Transfers, {name}. Can I take your name or booking reference?"
- Key 3: "Central Executive Transfers, {name}."
- Key 4: "Central Executive Transfers, accounts, {name} speaking."

`[CHECK bOnline]` Does the menu allow per-key ring groups, whisper messages or call labels? If not, one ring group for all keys is fine.

## 3. Business hours

Opening hours are not confirmed in BUSINESS_FACTS.md (listed as `[CONFIRM operating hours]`).

- Menu hours (live answering): `[CONFIRM: e.g. 07:00 to 21:00, 7 days]`
- Outside those hours, play the out-of-hours message (section 4).
- Bank holidays and Christmas period: `[CONFIRM]` whether to use the out-of-hours message all day.

`[CHECK bOnline]` Does the time-based schedule support bank holidays and one-off overrides (for example, both directors on a long job or on leave)? Can it be switched from the mobile app quickly?

## 4. Out-of-hours message (about 20 seconds)

> "Thank you for calling Central Executive Transfers. We're not able to take your call
> right now.
> You can get an instant fixed price and book online at centralexecutivetransfers dot co dot UK.
> Or send us a WhatsApp on zero seven four zero five, one seven two, four three five, and we'll reply as soon as we can.
> If you'd like to leave a message after the tone, please give your name, number and what you need, and we'll call you back."

Notes:
- Say the WhatsApp number in groups, as written, so it is easy to write down. The international form is +44 7405 172435; the spoken UK form is 07405 172435.
- If a director is on a live job and cannot answer in hours, use the same message but swap the first line: "We're with a customer at the moment."
- The website is spoken as "centralexecutivetransfers dot co dot UK". Check it sounds natural when recorded; if not, shorten to "our website".

## 5. Voicemail prompt (about 10 seconds)

> "You've reached Central Executive Transfers. Please leave your name, your number,
> and what you need. If it's about a pickup today, say so and we'll call you back
> straight away. Thank you."

Rules:
- Voicemail should email the recording or transcript to admin@centralexecutivetransfers.co.uk `[CHECK bOnline]`.
- Check voicemail every time the phone has been out of reach for more than 30 minutes.
- Do not leave customer details in voicemail greetings or on shared screens (UK GDPR).

## 6. "Ring both directors at once" routing (recommendation)

Recommended set-up:
1. Create one **ring group** (simultaneous / "ring all") containing Abdi and Maj, using the bOnline mobile app on each director's phone. `[CHECK bOnline]` the name for this in the portal (ring group, hunt group, call group).
2. Ring time: about 20 seconds, then voicemail. Longer than 25 seconds feels long to a caller.
3. If one director answers, the other phone must stop ringing immediately. `[CHECK bOnline]`
4. Fallback if neither answers: voicemail (section 5) and an email or app notification of the missed call.
5. Each director sets "Do not disturb" in the app when driving a live job or when a call would be unsafe to take. If one is on DND, the ring group should still ring the other. `[CHECK bOnline]`
6. If a director is already on a call, the second caller should ring the other director, not go straight to voicemail. `[CHECK bOnline]` call waiting / busy behaviour in a ring group.
7. Caller ID on outbound callbacks: set the business number 0114 399 8242 as the outgoing ID from the app, so customers see the number they called. `[CHECK bOnline]`
8. Fixed-price, driver and safety rule: never read out a driver's real phone number on a call. Customers get the masked line only.

Why simultaneous ring and not one after the other: the first director to answer wins, and nobody waits through a 20-second ring on a phone that is switched off.

`[CHECK bOnline]` mobile app reliability on iPhone and Android when the phone is locked (push notification for incoming calls), and call-quality behaviour on mobile data in weak signal areas on the way to and from airports.

## 7. Missed-call callback routine

Rule: **call back within 5 minutes, during opening hours.** Outside hours, call back at opening time, or send the WhatsApp straight away.

1. Missed call appears in the bOnline app or voicemail email. Whichever director sees it first replies "Mine" in the directors' chat so it is not called twice.
2. Call back from the business number.
3. No answer: leave no voicemail with personal detail. Send the WhatsApp below straight away.
4. Try one more call after about 15 minutes. If still no answer, log it in the Command Centre notes for the enquiry (once a booking or customer record exists).
5. Unknown numbers that look like spam or sales calls: do not call back. Check the number online first.

### WhatsApp template after no answer

> Hi {first name if known, otherwise "there"},
>
> Sorry we missed you. This is *Central Executive Transfers*, returning your call.
> If you're after a price or a booking, you can reply here with *date, time, pickup, drop-off, passengers and bags*, and I'll send your fixed price. Or get an instant price at https://centralexecutivetransfers.co.uk
>
> Prefer a call? Tell me a good time.
>
> *Central Executive Transfers*

Check before sending: first name if known, and that the person has not already been answered by the other director.

### Out-of-hours variant

> Hi {first name},
>
> Thanks for calling *Central Executive Transfers*. We're closed until {opening time `[CONFIRM]`}.
> Reply here with *date, time, pickup, drop-off, passengers and bags* and we'll send your fixed price first thing. Or book online: https://centralexecutivetransfers.co.uk
>
> *Central Executive Transfers*

---

## Things to check against bOnline's actual features

Checklist for whoever sets this up. Do not assume any of these exist until seen in the portal.

- [ ] Auto-attendant / IVR with at least 4 options and a default route
- [ ] Time-of-day schedules, bank-holiday dates and a quick manual override
- [ ] Ring groups with simultaneous ring, and ring timeout
- [ ] Mobile app: incoming-call reliability, outbound caller ID set to 0114 399 8242
- [ ] Busy behaviour when one member is on a call
- [ ] Voicemail to email, with transcription if offered
- [ ] Custom recorded prompts (upload a file, or record by phone) and the maximum length
- [ ] Missed-call notification or log (app, email, or both)
- [ ] Call recording: whether available and whether it is on by default. If used, the greeting must say calls may be recorded, and a retention period needs deciding (UK GDPR). Do not enable without a decision.
- [ ] Per-key whisper messages or labels
- [ ] Call reporting (volumes, missed calls, peak hours)
- [ ] Costs: any extra charge for ring groups, extra lines, app licences, recording or call menus
- [ ] Opening hours to be confirmed by the directors (`[CONFIRM operating hours]` in BUSINESS_FACTS.md)
