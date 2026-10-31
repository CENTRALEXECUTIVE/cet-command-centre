#!/usr/bin/env python3
"""
render_composition.py — Generate a HyperFrames HTML composition from an EDL JSON,
lint it, then optionally render to MP4.

Usage:
    python render_composition.py <edl_json> [--output-dir PATH] [--no-render] [--approve-all]

    --no-render : write index.html only, don't call npx hyperframes render
    --approve-all : skip the confidence gate

Requires:
    - Node.js >= 22 + npx
    - npx skills add heygen-com/hyperframes  (run once in project dir)
    - ffmpeg in PATH
"""
import json, sys, os, argparse, subprocess, re
from pathlib import Path
from jinja2 import Environment, FileSystemLoader

TEMPLATES_DIR = Path(__file__).parent.parent / "templates"
COMPOSITION_TEMPLATE = "composition.html.j2"
CONFIDENCE_THRESHOLD = 0.85


MAX_CAPTION_WORDS = 5


# ── Caption HTML ──────────────────────────────────────────────────────────────

def build_caption_html(caption_text: str, emphasis_words: list) -> str:
    """Lowercase caption text, wrapping emphasis words in yellow spans."""
    if not emphasis_words:
        return caption_text.lower()
    emphasis_set = {w.lower().strip(".,!?\"'") for w in emphasis_words}
    parts = []
    for word in caption_text.lower().split():
        clean = word.strip(".,!?\"'")
        if clean in emphasis_set:
            parts.append(f'<span style="color:#FBBA2A">{word}</span>')
        else:
            parts.append(word)
    return " ".join(parts)


def split_caption_groups(caption_text: str, emphasis_words: list, max_words: int = MAX_CAPTION_WORDS) -> list:
    """Split caption into word groups of at most max_words, preserving emphasis."""
    words = caption_text.split()
    groups = []
    for i in range(0, len(words), max_words):
        chunk = words[i:i + max_words]
        group_text = " ".join(chunk)
        groups.append({"text": group_text, "emphasis": emphasis_words or []})
    return groups or [{"text": caption_text, "emphasis": emphasis_words or []}]


# ── EDL loading & validation ──────────────────────────────────────────────────

def load_edl(edl_path: Path) -> dict:
    return json.loads(edl_path.read_text())


def validate_edl(edl: dict):
    clips = edl.get("clips", [])
    if not clips:
        raise ValueError("EDL has no clips")
    for i, c in enumerate(clips):
        if c["in_s"] >= c["out_s"]:
            raise ValueError(f"Clip {i}: in_s ({c['in_s']}) >= out_s ({c['out_s']})")


# ── Confidence gate ───────────────────────────────────────────────────────────

def check_confidence(edl: dict) -> list:
    return [c for c in edl.get("clips", []) if c.get("confidence", 1.0) < CONFIDENCE_THRESHOLD]


def print_gate_report(flagged: list):
    print(f"\n⚠️  {len(flagged)} cut(s) need your approval before rendering:\n")
    for i, clip in enumerate(flagged):
        caption = clip.get("caption_text", "")
        preview = f'"{caption[:55]}..."' if len(caption) > 55 else f'"{caption}"'
        print(f"  [{i+1}] Clip {clip['clip_idx']}  {clip['in_s']}s → {clip['out_s']}s  "
              f"(confidence {clip.get('confidence', '?'):.2f})")
        print(f"       {preview}")
        print(f"       Reason: {clip.get('cut_reason', 'none given')}")
        print()
    print("Fix: edit .edl.json (adjust in_s/out_s or raise confidence), then re-run.")
    print("     Or pass --approve-all to skip the gate.")


# ── Template context ──────────────────────────────────────────────────────────

def build_template_context(edl: dict) -> dict:
    clips = edl["clips"]
    cumulative = 0.0
    enriched = []
    caption_groups_flat = []

    for clip in clips:
        dur = round(clip["out_s"] - clip["in_s"], 3)
        groups = split_caption_groups(
            clip.get("caption_text", ""),
            clip.get("caption_emphasis", []),
        )
        n = len(groups)
        group_dur = round(dur / n, 3) if n > 0 else dur

        timed_groups = []
        for j, g in enumerate(groups):
            abs_start = round(cumulative + j * group_dur, 3)
            entry = {
                "clip_idx": clip["clip_idx"],
                "group_idx": j,
                "abs_start": abs_start,
                "duration": group_dur,
                "caption_html": build_caption_html(g["text"], g["emphasis"]),
            }
            timed_groups.append(entry)
            caption_groups_flat.append(entry)

        enriched.append({
            **clip,
            "timeline_start_s": round(cumulative, 3),
            "clip_duration_s": dur,
            "caption_groups": timed_groups,
        })
        cumulative += dur

    total_duration = round(cumulative, 3)
    video_stem = re.sub(r"[^a-z0-9]", "-", Path(edl["video"]).stem.lower())

    return {
        "video_name": edl["video"],
        "composition_id": video_stem,
        "clips": enriched,
        "caption_groups_flat": caption_groups_flat,
        "total_duration_s": total_duration,
        "music_track": edl.get("music_track"),
        "fps": 30,
        "width": 1080,
        "height": 1920,
    }


# ── HTML generation ───────────────────────────────────────────────────────────

def generate_html(edl: dict, output_dir: Path, edl_path: Path = None) -> tuple[Path, dict]:
    env = Environment(
        loader=FileSystemLoader(str(TEMPLATES_DIR)),
        variable_start_string="<<",
        variable_end_string=">>",
        block_start_string="<%",
        block_end_string="%>",
        comment_start_string="<#",
        comment_end_string="#>",
        autoescape=False,
    )
    tmpl = env.get_template(COMPOSITION_TEMPLATE)
    ctx = build_template_context(edl)

    # Resolve source_video paths relative to output_dir so HTML src= works correctly
    if edl_path:
        for clip in ctx["clips"]:
            raw = clip.get("source_video", "")
            resolved = (edl_path.parent / raw).resolve()
            clip["source_video"] = os.path.relpath(str(resolved), str(output_dir.resolve()))

    html = tmpl.render(**ctx)

    out_path = output_dir / "index.html"
    out_path.write_text(html)
    return out_path, ctx


# ── Lint ──────────────────────────────────────────────────────────────────────

def lint(composition_dir: Path) -> bool:
    print("Linting composition...", flush=True)
    result = subprocess.run(
        ["npx", "hyperframes", "lint"],
        cwd=str(composition_dir),
        capture_output=True,
        text=True,
    )
    if result.stdout:
        print(result.stdout)
    if result.stderr:
        print(result.stderr)
    if result.returncode != 0:
        print("Lint errors found — fix index.html before rendering.", file=sys.stderr)
        return False
    print("  ✓ Lint passed", flush=True)
    return True


# ── Render ────────────────────────────────────────────────────────────────────

def render_video(composition_dir: Path, output_path: Path):
    cmd = ["npx", "hyperframes", "render", "--workers", "1", "--output", str(output_path)]
    print(f"Running: {' '.join(cmd)}", flush=True)
    result = subprocess.run(cmd, cwd=str(composition_dir))
    if result.returncode != 0:
        print("Error: HyperFrames render failed.", file=sys.stderr)
        print("Run 'npx hyperframes doctor' to diagnose.", file=sys.stderr)
        sys.exit(1)


# ── Main ──────────────────────────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser(
        description="Convert an EDL JSON into a HyperFrames composition and render to MP4."
    )
    parser.add_argument("edl", help="Path to .edl.json file")
    parser.add_argument("--output-dir", help="Directory for composition files (default: alongside EDL)")
    parser.add_argument("--no-render", action="store_true", help="Write index.html only, skip render")
    parser.add_argument("--approve-all", action="store_true", help="Skip confidence gate")
    parser.add_argument("--skip-lint", action="store_true", help="Skip lint step")
    args = parser.parse_args()

    edl_path = Path(args.edl)
    if not edl_path.exists():
        print(f"Error: {edl_path} not found", file=sys.stderr)
        sys.exit(1)

    output_dir = Path(args.output_dir) if args.output_dir else edl_path.parent / "hyperframes-composition"
    output_dir.mkdir(parents=True, exist_ok=True)

    # Load
    print(f"Loading EDL: {edl_path.name}", flush=True)
    edl = load_edl(edl_path)

    # Confidence gate
    if not args.approve_all:
        flagged = check_confidence(edl)
        if flagged:
            print_gate_report(flagged)
            sys.exit(2)

    # Validate
    try:
        validate_edl(edl)
    except ValueError as e:
        print(f"Validation failed: {e}", file=sys.stderr)
        sys.exit(1)

    # Generate HTML
    print(f"Generating composition → {output_dir}", flush=True)
    html_path, ctx = generate_html(edl, output_dir, edl_path)
    n_clips = len(ctx["clips"])
    total_s = ctx["total_duration_s"]
    print(f"  ✓ index.html written  ({n_clips} clips, {total_s}s total)", flush=True)
    print(f"    NOTE: src paths in index.html must be relative to {output_dir}", flush=True)

    if args.no_render:
        print(f"\nTo lint    : cd {output_dir} && npx hyperframes lint")
        print(f"To preview : cd {output_dir} && npx hyperframes preview")
        print(f"To render  : cd {output_dir} && npx hyperframes render --output ../output.mp4")
        return

    # Lint
    if not args.skip_lint:
        if not lint(output_dir):
            print("\nFix lint errors, then re-run. Or pass --skip-lint to force render.", file=sys.stderr)
            sys.exit(1)

    # Render
    video_stem = Path(edl["video"]).stem
    output_mp4 = output_dir.parent / f"{video_stem}_edited.mp4"
    print(f"\nRendering → {output_mp4}", flush=True)
    render_video(output_dir, output_mp4)
    print(f"\n✓ Done: {output_mp4}")


if __name__ == "__main__":
    main()
