#!/usr/bin/env python3
"""
plan_edit.py — Load transcript + analysis and write a context file for Claude Code to plan the edit.

Usage:
    python plan_edit.py <video_path> [--broll-dir PATH] [--music-dir PATH]

Outputs: <video>.plan_context.md  — hand this to Claude Code with "plan the edit"

No API key needed. Claude Code reads this file and produces the .edl.json directly.
"""
import json, sys, argparse
from pathlib import Path

STYLE_GUIDE_PATH = Path(__file__).parent.parent / "references" / "style-guide.md"


def load_files(video_path: Path, broll_dir: Path | None, music_dir: Path | None):
    transcript_path = video_path.with_suffix(".transcript.json")
    analysis_path = video_path.with_suffix(".analysis.json")

    if not transcript_path.exists():
        print(f"Error: No transcript found at {transcript_path}", file=sys.stderr)
        print("Run: python transcribe.py <video>", file=sys.stderr)
        sys.exit(1)

    if not analysis_path.exists():
        print(f"Error: No analysis found at {analysis_path}", file=sys.stderr)
        print("Run: python scene_detect.py <video>", file=sys.stderr)
        sys.exit(1)

    transcript = json.loads(transcript_path.read_text())
    analysis = json.loads(analysis_path.read_text())
    style_guide = STYLE_GUIDE_PATH.read_text() if STYLE_GUIDE_PATH.exists() else "(no style guide found)"

    broll_files = []
    if broll_dir and broll_dir.exists():
        broll_files = [f.name for f in sorted(broll_dir.iterdir())
                       if f.suffix.lower() in (".mp4", ".mov", ".m4v")]

    music_files = []
    if music_dir and music_dir.exists():
        music_files = [f.name for f in sorted(music_dir.iterdir())
                       if f.suffix.lower() in (".mp3", ".wav", ".m4a", ".aac")]

    return transcript, analysis, style_guide, broll_files, music_files


def build_context(transcript, analysis, style_guide, broll_files, music_files, video_name, out_edl_path):
    full_text = " ".join(
        w["word"] for seg in transcript["segments"] for w in seg.get("words", [])
    )
    word_list = [
        {"word": w["word"], "start": w["start"], "end": w["end"]}
        for seg in transcript["segments"]
        for w in seg.get("words", [])
    ]

    mistakes = analysis.get("mistakes", [])
    mistake_table = ""
    if mistakes:
        rows = []
        for i, m in enumerate(mistakes):
            rows.append(f"| {i} | {m['start_s']} | {m['end_s']} | {m['type']} | {m['text'][:50]} |")
        mistake_table = (
            "\n## Mistake Regions — DO NOT use these as in_s/out_s\n"
            "| # | start_s | end_s | type | text |\n"
            "|---|---------|-------|------|------|\n"
            + "\n".join(rows)
            + "\n\nRules: Never place `in_s` inside a mistake region. "
            "If a clip must start near one, use `in_s = mistake.end_s + 0.05`. "
            "Repeated-phrase mistakes usually mean the speaker fumbled a take — "
            "find the cleaner version later in the transcript.\n"
        )

    return f"""# Edit Planning Context — {video_name}

## Task
Plan a short-form vertical video edit (Reels/Shorts). Produce an EDL JSON saved to:
`{out_edl_path}`

## Style Guide
{style_guide}

## Video Metadata
- Duration: {analysis['duration_s']}s
- Resolution: {analysis['width']}×{analysis['height']} ({analysis['orientation']})
- FPS: {analysis['fps']}
- Detected scenes: {analysis['scene_count']}
- Scene durations (s): {[s['duration_s'] for s in analysis['scenes']]}
- Silence regions: {analysis.get('silence_count', 0)}
- Audio tempo: {analysis['audio']['tempo_bpm']} bpm
{mistake_table}
## Full Transcript
{full_text}

## Word-level Timestamps
```json
{json.dumps(word_list, indent=2)}
```

## Available B-Roll Files
{json.dumps(broll_files) if broll_files else '(none — set broll_overlay to null on all clips)'}

## Available Music Files
{json.dumps(music_files) if music_files else '(none — set music_track to null)'}

---

## EDL Format
Write the EDL as valid JSON to `{out_edl_path}`. Schema:

```json
{{
  "video": "{video_name}",
  "music_track": null,
  "clips": [
    {{
      "clip_idx": 0,
      "source_video": "{video_name}",
      "in_s": 0.000,
      "out_s": 5.500,
      "caption_text": "The words spoken in this clip",
      "caption_emphasis": ["key", "words"],
      "broll_overlay": null,
      "confidence": 0.95,
      "cut_reason": "Opens on first content word, holds 5.5s for hook"
    }}
  ]
}}
```

Rules:
1. ENTRY CUT: Remove pre-amble ("alright so", "okay so", "hey") — start on first meaningful word.
2. SILENCE TRIM: Remove pauses >0.4s between sentences. Never cut mid-word.
3. OPENING: First clip ≥ 4s (hook window). Don't cut away until premise is landed.
4. MID-SECTION: Target 1.5–2.5s per clip. Cut on breath pauses between sentences.
5. TIMING: Set `in_s` to first word's start minus ≤0.05s. Set `out_s` to last word's end plus ≤0.05s. No trailing silence >0.1s.
6. CAPTIONS: caption_text = verbatim spoken words in that clip. caption_emphasis = 1–2 key words.
7. BROLL: Match spoken keywords to b-roll filenames (fuzzy). Set broll_overlay to filename or null.
8. MUSIC: Pick one music file if available. Set at top-level music_track. Null if none.
9. CONFIDENCE: 1.0 for clean sentence-boundary cuts. 0.7–0.85 for uncertain cuts (will trigger review).
10. MISTAKES: Never use a timestamp that falls within a mistake region listed above.
"""


def main():
    parser = argparse.ArgumentParser(
        description="Prepare edit planning context for Claude Code."
    )
    parser.add_argument("video", help="Path to source video")
    parser.add_argument("--broll-dir", help="Path to b-roll library folder")
    parser.add_argument("--music-dir", help="Path to music library folder")
    parser.add_argument("--out", help="Output context path (default: next to video)")
    args = parser.parse_args()

    video_path = Path(args.video)
    broll_dir = Path(args.broll_dir) if args.broll_dir else None
    music_dir = Path(args.music_dir) if args.music_dir else None
    out_path = Path(args.out) if args.out else video_path.with_suffix(".plan_context.md")
    edl_path = video_path.with_suffix(".edl.json")

    print(f"Loading context for: {video_path.name}", flush=True)
    transcript, analysis, style_guide, broll_files, music_files = load_files(
        video_path, broll_dir, music_dir
    )

    context = build_context(
        transcript, analysis, style_guide, broll_files, music_files,
        video_path.name, edl_path
    )
    out_path.write_text(context)

    print(f"Context written → {out_path}")
    print()
    print("Next: tell Claude Code:")
    print(f'  "Plan the edit for {video_path.name} — context is at {out_path}"')


if __name__ == "__main__":
    main()
