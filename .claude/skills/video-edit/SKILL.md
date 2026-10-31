---
name: video-edit
description: Edit raw phone footage (talking-head, car/airport b-roll) into finished vertical Reels/TikToks/Shorts for Central Executive Transfers — silence trim, tight cuts, burned-in captions in CET gold, 1080x1920. Use when the user sends raw videos and asks to edit, cut, caption, or turn them into a reel/short. Edits only what was filmed; never invents claims.
---

# CET video editing (read this first)

Adapted from FeezRM/AI-ShortForm-Editor (Apache-2.0, see LICENSE). CET rules on top:

- **Where files live:** raw uploads and renders go in `docs/growth/data/video/<date>/`
  (gitignored). **Never commit video files** — they show customers, cars and plates.
- **Privacy check before export:** blur or cut customers' faces (unless they agreed),
  number plates other than ours, booking screens, phone numbers and addresses.
- **Brand:** captions white, 1–2 emphasis words in CET gold `#FBBA2A`, black outline.
  Optional end card: "Central Executive Transfers · centralexecutivetransfers.co.uk".
- **Claims:** only what's in `docs/growth/BUSINESS_FACTS.md`. No made-up prices or reviews.
- **Pipeline here (no HyperFrames on this box):**
  1. `python3 scripts/transcribe.py <video>` (needs Whisper — see below)
  2. `python3 scripts/scene_detect.py <video>` → scenes, silences, pacing (works offline)
  3. Write `<video>.edl.json` yourself from the transcript + analysis (format below)
  4. `python3 scripts/cet_render.py <video>.edl.json` → `<video>_cet.mp4`: cut, 9:16 crop,
     wrapped captions (white + CET-gold emphasis). `--no-captions` for a clean cut.
- **Captions need the Whisper speech model** (downloaded from huggingface.co). If that
  host is blocked by the environment's network policy, say so and either (a) deliver
  the cut without captions, or (b) caption from a script/transcript the user supplies.
  Never invent caption text.
- **Setup once per session:** `python3 -m pip install faster-whisper "scenedetect[opencv]" librosa jinja2 pillow numpy`
- Deliver the MP4 to the user with SendUserFile; nothing is posted anywhere automatically.

---

# viral-shortform-editor

AI-powered short-form video editor for Reels and Shorts. Takes raw talking-head footage and produces a HyperFrames HTML composition that renders to a finished MP4 — captions, b-roll overlays, music, style-guide-matched pacing.

**The video is already recorded. This skill only edits — it never writes copy or invents content.**

---

## Quick start

```bash
# Activate Python env
source venv/bin/activate

# 1. Transcribe (word-level timestamps)
python viral-shortform-editor/scripts/transcribe.py "input/video.mp4"

# 2. Analyze (scenes, silences, audio beats)
python viral-shortform-editor/scripts/scene_detect.py "input/video.mp4"

# 3. Prepare planning context
python viral-shortform-editor/scripts/plan_edit.py "input/video.mp4" \
  --broll-dir BROLL --music-dir MUSIC
# → writes input/video.plan_context.md
# Then tell Claude Code: "Plan the edit — context is at input/video.plan_context.md"
# Claude reads it and writes input/video.edl.json directly. No API key needed.

# 4. Render (lint runs automatically before render)
python viral-shortform-editor/scripts/render_composition.py "input/video.edl.json"
# → output: input/video_edited.mp4
```

---

## What each step produces

| Script | Input | Output |
|--------|-------|--------|
| `transcribe.py` | `.mp4 / .mov` | `.transcript.json` — word-level timestamps |
| `scene_detect.py` | `.mp4 / .mov` | `.analysis.json` — scenes, silences, audio tempo |
| `plan_edit.py` | video + transcript + analysis | `.plan_context.md` — formatted context for Claude |
| Claude (you) | `.plan_context.md` | `.edl.json` — Edit Decision List with confidence scores |
| `render_composition.py` | `.edl.json` | `hyperframes-composition/index.html` + `_edited.mp4` |

---

## EDL format

```json
{
  "video": "video.mp4",
  "music_track": null,
  "clips": [
    {
      "clip_idx": 0,
      "source_video": "video.mp4",
      "in_s": 3.21,
      "out_s": 8.70,
      "caption_text": "The words spoken in this clip",
      "caption_emphasis": ["key", "words"],
      "broll_overlay": null,
      "confidence": 0.95,
      "cut_reason": "Opens on first content word, holds 5.5s for hook"
    }
  ]
}
```

`source_video` paths must be **relative to `hyperframes-composition/index.html`** (e.g., `../video.mp4`).

---

## Confidence gate

Any clip with `confidence < 0.85` stops the pipeline before render:

```
⚠️  2 cut(s) need your approval before rendering:

  [1] Clip 3  4.21s → 5.83s  (confidence 0.71)
       "could be intentional emphasis"
       Reason: mid-sentence pause

Fix: edit .edl.json (adjust in_s/out_s or raise confidence), then re-run.
     Or pass --approve-all to skip.
```

---

## Editing the output

The composition is plain HTML at `hyperframes-composition/index.html`.

| Change | How |
|--------|-----|
| Caption text | Find `<div id="cg-N">` and edit the text content |
| Emphasis word | Find `<span style="color:#FBBA2A">` and change/remove |
| Trim a clip | Change `data-media-start` (offset into source) and `data-duration` |
| Swap music | Change `src` on the `<audio id="music">` element |
| Preview live | `cd hyperframes-composition && npx hyperframes preview` |
| Re-render | `cd hyperframes-composition && npx hyperframes render --output ../output.mp4` |

---

## HyperFrames composition rules

These rules are enforced by the template and must be respected when editing `index.html` manually:

- **Video must be `muted playsinline`** — audio comes from a separate `<audio>` element with the same `src`, `data-media-start`, and `data-start`
- **`data-media-start`** — trim offset into the source file in seconds (not `data-media-start-time`)
- **`data-track-index`** — controls timeline track, NOT visual z-order; use CSS `z-index` for layering
- **Captions** — GSAP-animated divs registered in `window.__timelines["<composition-id>"]`; every group must have a hard `tl.set(..., { visibility: "hidden" })` kill at its end time
- **No `repeat: -1`** on any GSAP tween — HyperFrames capture engine requires finite repeats
- **No `data-type`, `data-content`, `data-end`, `data-layer`** — these are not real HyperFrames attributes

---

## Style guide

`references/style-guide.md` — extracted from 4 example videos. Defines:

- Pacing curve: open slow (4–11s hook), mid fast (1.5–2.5s), close medium (2–4s)
- Cut style: hard cuts only, no dissolves
- Caption style: uppercase, CET gold `#FBBA2A` emphasis, bold Impact/Arial Black, centered lower-third
- Music volume: 15% under speech

`DESIGN.md` — visual identity consumed by HyperFrames before any composition is generated.

---

## Dependencies

```bash
# Python (already installed in venv/)
pip install faster-whisper "scenedetect[opencv]" librosa ffmpeg-python jinja2

# System
# ffmpeg >= 5 must be in PATH
# Node.js >= 22 required

# HyperFrames (already installed — run once to reinstall)
npx skills add heygen-com/hyperframes
```

Environment check: `npx hyperframes doctor`

---

## Content modes

| Mode | Status | Description |
|------|--------|-------------|
| Talking head | ✅ Phase 1 | Transcript-driven, silence trim, captions, b-roll overlays |
| Podcast clips | 🔜 Phase 2 | Viral-moment finder on long-form content |
| B-roll / vlog | 🔜 Phase 3 | Music-driven beat-sync cutting |

---

## Troubleshooting

**Confidence gate triggered** — expected. Open `.edl.json`, fix the flagged clip, re-run. Or `--approve-all`.

**Lint errors** — run `cd hyperframes-composition && npx hyperframes lint --verbose` to see detail. Common: overlapping `data-track-index` on same track, missing `data-composition-id`.

**Captions visible past clip end** — look for `[caption-lint]` warnings in the browser console during preview. Adjust the `exitAt` time in the GSAP timeline or reduce clip overlap.

**Video/audio out of sync** — verify `data-media-start` on both `<video>` and its matching `<audio>` are identical.

**"npx hyperframes not found"** — run `npx skills add heygen-com/hyperframes` in the project directory.

**Low memory warning from `doctor`** — close other apps; renders can fail with <1GB free. Use `--quality draft` for iteration.
