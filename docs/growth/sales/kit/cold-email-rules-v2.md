# Cold email rules v2 (Nov 2026)

Why v2: the first drafts read as promotional. They opened with who we are, listed
services, and used vendor words. These rules come from the `cold-email` and
`copy-editing` skills (`.claude/skills/`), applied to CET. Facts only from
`docs/growth/BUSINESS_FACTS.md`.

## The shape (50 to 90 words in the body, before the signature)
1. **Their world first.** One specific, true observation about them (a site, a
   project, visitors, where they are). It must lead into the problem. If you can
   delete it and the email still works, it is decoration: rewrite it.
2. **The problem in their words.** The moment they care about: a visitor landing at
   7am, a candidate arriving for interview, a client who must not be left waiting,
   a partner job in Sheffield with no local driver.
3. **One line about us, as proof, not a pitch.** Pick ONE true thing:
   - we already do this for a few manufacturers / firms in the area (true: JELD-WEN,
     LB Foster and Forged Solutions are account clients; do NOT name them);
   - one account, one monthly VAT invoice, per-journey PO references;
   - the two directors drive the executive work themselves and answer the phone 24/7;
   - licensed by Sheffield City Council; enhanced-DBS chauffeurs (partners, care, schools).
4. **One easy question** they can answer in a line. Interest, not a meeting:
   "Worth sending our account details over?", "Who looks after visitor travel?",
   "Is this something you'd use?". Never ask for a call in the first email.

## Subject lines
2 to 4 words, lowercase, plain, like a colleague wrote it: `visitors to bessemer park`,
`candidate travel`, `sheffield affiliate`, `airport pickups for {town} office`.
No company pitch, no urgency, no emojis, no first name.

## Voice
"We" (signed by both directors). Contractions. Plain British English. Read it aloud;
if it sounds like a brochure, cut it.

## Banned (AI tells and vendor words)
- Em dashes. Use a full stop or comma.
- "I hope this finds you well", "My name is", "We are a leading", "We run X, a ...".
- "experienced", "discreet", "dependable", "premium", "bespoke", "seamless",
  "hassle-free", "peace of mind", "tailored", "solutions", "leverage", "elevate",
  "look no further", "don't hesitate", "reach out", "touch base".
- Contrast reveals ("It's not just X, it's Y"), "no X, no Y, no Z" lists, colon
  reveals, self-answered questions.
- Lists of services. One proof point beats five features.
- "Just following up" / "just checking in".

## Fixed parts (keep exactly)
- Greeting: `Hello {first_name},` when we have a name, else `Hello,`.
- Signature block and opt-out line are added from `config/outreach.php`. Keep the
  existing signature + opt-out text at the end of each body exactly as it is now.

## Gold examples

**Manufacturer (ITM Power, Bessemer Park)**
Subject: `visitors to bessemer park`
> Hello,
>
> With the Chronos line ramping up at Bessemer Park, you must have a steady run of customers and engineers flying in to see it.
>
> When someone lands at Manchester at 7am, whoever booked their car mostly wants to know it'll be there. We already look after this for a few manufacturers around Tinsley, all on one account with a monthly VAT invoice.
>
> Worth us sending our account details over?

**Partner (TBR Global Chauffeuring)**
Subject: `sheffield affiliate`
> Hello,
>
> When a TBR client lands at Manchester and needs to get to Sheffield, who covers that for you at the moment?
>
> We're a Sheffield chauffeur firm run by the two of us, licensed by Sheffield City Council and on call 24/7, and we work unbranded for partners. If your Yorkshire or East Midlands cover is ever thin, we'd like to be on your affiliate list.
>
> Could you point us to whoever handles affiliate onboarding?
