# CET futuristic Reel (Remotion) — house style v4, Nov 2026

Motion-graphics Reels: cinematic grade, zoom-blur + flash cuts on a 120 BPM grid (15 frames
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
