# CET futuristic Reel (Remotion) — house style v4, Nov 2026

Motion-graphics Reels: clean bright natural grade (directors, 10 Nov: NO teal/orange or yellow tint, no heavy vignette or grain — "gloomy"; use eq=contrast=1.06:saturation=1.1:brightness=0.035:gamma=1.03,unsharp=5:5:0.5), zoom-blur + flash cuts on a 120 BPM grid (15 frames
per beat, so an in-app trending sound lines up), HUD overlay (gold corner brackets, scan line,
live timecode, "CET // SHEFFIELD", progress bar), glass caption panels with words springing in,
gold glow word + underline wipe, glitch typewriter hook on white pills, CENTRAL end card with
light sweep. No sound effects (directors: they sounded odd) and no baked-in music; the video ships silent and trending music is added in the
TikTok/Instagram app (copyright: music baked into the file gets muted).

Run (from a scratch copy, never commit node_modules or videos):
1. `npm i` (remotion 4.0.300, react 18.3.1)
2. Grade clips into `public/` with ffmpeg (9:16 crop, 30 fps, teal/orange grade, vignette,
   grain) — see the GRADE filter in the session notes; copy fonts TikTokSans-600/800.ttf and a
   mono font as public/mono.ttf; make whoosh.wav / impact.wav with ffmpeg lavfi.
3. Edit `src/prep.json` (hook + segments: src, dur in frames, caption, emphasis).
4. `npx remotion render src/index.jsx PrepReel out/reel.mp4 --browser-executable=/opt/pw-browsers/chromium_headless_shell-*/chrome-headless-shell-linux64/chrome-headless-shell`

## Style 2: editorial airport (composition `Airport`, src/Airport.jsx) — from reference TikToks
Split-flap departures board hook (SHEFFIELD → MAN/LHR/LBA), floating glass UI cards with a 3D tilt-in
(illustrations only, never real customer/flight/plate data), two-tier type (gold mono lead-in + gold
rule + big TikTok Sans line + Instrument Serif italic accent in gold), 01/05 step counter, soft
blur-in cuts, navy gradient grade, end card "Every detail, handled." Fonts in public/: TikTokSans
600/800, mono.ttf, serif-italic.ttf (Instrument Serif Italic, OFL).
