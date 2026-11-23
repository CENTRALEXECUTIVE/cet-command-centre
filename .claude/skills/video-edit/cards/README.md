# CET social graphics (v3, 22 Nov 2026)

**Current theme: CONCIERGE (`genx.py`, 23 Nov 2026)** — directors said the gold-word/bold look read as AI.
One typeface (Schibsted Grotesk 400/500, woff2 in ../fonts), sentence case, left aligned, true black on white,
photo-led (photo top ~70%, white band with headline + short sentence), black CENTRAL logo, one small gold square
beside "Book online centralexecutivetransfers.co.uk". No phone numbers, no PO, no caps labels, no coloured words.
Text carousels use hairline rows and a quiet page number (2/7). `python3 genx.py cards-*.json`.

Previous: WHITE (`genw.py`), chosen by the directors on 22 Nov 2026 ("B, more white"):
white canvas, black CENTRAL wordmark + deep-gold tagline, centred black headlines with gold emphasis,
photo in the top of the card with the logo on a white tab, black contact strip at the bottom.
Use `python3 genw.py cards-*.json` instead of gen.py (gen.py = the earlier dark theme).

HTML templates screenshotted at 1440 px wide (4:5 feed 1440x1800, stories 1440x2560,
Google Business 1600x1200), saved as JPEG q92 4:4:4 so text and the logo stay sharp
after Instagram/TikTok re-compress them.

Design: real CENTRAL wordmark + gold "EXECUTIVE TRANSFERS" tagline top-left; Poppins
ExtraBold headlines with gold emphasis words; gold rule; contact bar at the bottom with
the website as a gold pill and WhatsApp / phone with icons. Photo cards keep plates
blurred via `blur` boxes (CSS coordinates of the card). Google Business photos with no
headline stay clean (no overlays).

    python3 gen.py cards-november.json cards-december.json cards-stories.json   # writes html/ + manifest.json
    node shot.js [prefix]                                                       # renders png/ (retries)
    # then convert png -> JPEG q92 subsampling 0 into resources/social-media/<same path>

Slideshow Reels: `scripts/cet_photo_reel.py spec.json out.mp4` (spec = slide_s + slides);
final encode settings live in `scripts/cet_encode.py`.
Sources (photos/footage) are in docs/growth/data/video/ (not in git).
