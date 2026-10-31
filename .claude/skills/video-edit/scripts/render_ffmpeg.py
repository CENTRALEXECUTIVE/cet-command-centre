#!/usr/bin/env python3
"""
render_ffmpeg.py — Render EDL to MP4 directly via ffmpeg (no browser needed).

Crops each clip to 1080x1920 (center-crop), concatenates, burns in captions
with drawtext. Outputs a single edited MP4.

Usage:
    python render_ffmpeg.py <edl.json> [--clips-dir PATH] [--output PATH]

Clips in clips-dir must be pre-cut (clip_0.mp4, clip_1.mp4, ...) from precut_clips.py
OR the script reads in_s/out_s from the EDL and cuts from the source video on the fly.
"""
import json, sys, os, subprocess, argparse, tempfile, shutil
from pathlib import Path


W, H = 1080, 1920
FPS = 30

# Caption style
FONT_SIZE = 68
FONT_COLOR = "white"
BORDER_COLOR = "black"
BORDER_W = 4
EMPHASIS_COLOR = "FBBA2A"       # CET gold
CAPTION_Y_PCT = 0.77            # 77% down = 23% from bottom, above bottom 18%


def crop_filter(src_w: int, src_h: int) -> str:
    """Center-crop to target 9:16 ratio."""
    target_ar = W / H                        # 0.5625
    src_ar = src_w / src_h
    if src_ar > target_ar:
        # wider than target — crop left/right
        new_w = int(src_h * target_ar)
        new_h = src_h
    else:
        # taller than target — crop top/bottom
        new_w = src_w
        new_h = int(src_w / target_ar)
    return f"crop={new_w}:{new_h},scale={W}:{H}"


def build_drawtext(caption_text: str, emphasis_words: list, start_s: float, end_s: float) -> list:
    """Build ffmpeg drawtext filter entries for a caption group."""
    words = caption_text.upper().split()
    emphasis_set = {w.lower().strip(".,!?\"'") for w in emphasis_words}
    filters = []
    enable = f"between(t,{start_s:.3f},{end_s:.3f})"

    # Build a single text block — drawtext doesn't natively support mixed colors.
    # Strategy: render plain text, then overlay yellow text for emphasis words.
    plain_text = " ".join(words)
    # Escape special chars for ffmpeg drawtext
    plain_text_esc = plain_text.replace("'", "\\'").replace(":", "\\:").replace(",", "\\,")

    # Base caption (all white)
    filters.append(
        f"drawtext=text='{plain_text_esc}':"
        f"fontsize={FONT_SIZE}:fontcolor={FONT_COLOR}:"
        f"bordercolor={BORDER_COLOR}:borderw={BORDER_W}:"
        f"x=(w-text_w)/2:y=h*{CAPTION_Y_PCT}:"
        f"font=Impact:"
        f"enable='{enable}'"
    )

    # Emphasis overlay — per emphasis word, render yellow on top
    for emph in emphasis_set:
        # Find which word(s) match
        emph_words = [w for w in words if w.lower().strip(".,!?\"'") == emph]
        if not emph_words:
            continue
        emph_text = " ".join(emph_words)
        # We can't easily position per-word without knowing pixel offsets.
        # Instead: for each emphasis word, build a second drawtext that covers the
        # same region but with yellow. This requires knowing exact x position.
        # Simple fallback: render emphasis word separately in yellow but center it
        # (acceptable for single-word emphasis; multi-word will overlap).
        emph_esc = emph_text.replace("'", "\\'").replace(":", "\\:").replace(",", "\\,")
        # For now, skip per-word positioning — output a note instead.

    return filters


def get_video_dims(path: Path) -> tuple:
    cmd = ["ffprobe", "-v", "quiet", "-print_format", "json", "-show_streams", str(path)]
    out = json.loads(subprocess.run(cmd, capture_output=True, text=True).stdout)
    v = next(s for s in out["streams"] if s["codec_type"] == "video")
    return int(v["width"]), int(v["height"])


def render_clip(clip: dict, clips_dir: Path, source_video: Path | None, tmp_dir: Path) -> Path:
    idx = clip["clip_idx"]
    precut = clips_dir / f"clip_{idx}.mp4"
    out = tmp_dir / f"rendered_{idx}.mp4"

    if precut.exists():
        src = precut
        t_start = 0.0
        duration = round(clip["out_s"] - clip["in_s"], 4)
    elif source_video and source_video.exists():
        src = source_video
        t_start = clip["in_s"]
        duration = round(clip["out_s"] - clip["in_s"], 4)
    else:
        print(f"Error: no source for clip {idx}", file=sys.stderr)
        sys.exit(1)

    w, h = get_video_dims(src)
    crop = crop_filter(w, h)
    in_args = ["-ss", str(t_start), "-i", str(src), "-t", str(duration)]

    cmd = [
        "ffmpeg", "-y",
        *in_args,
        "-vf", crop,
        "-c:v", "libx264", "-preset", "fast", "-crf", "22",
        "-r", str(FPS),
        "-c:a", "aac", "-b:a", "256k",
        "-movflags", "+faststart",
        str(out)
    ]
    result = subprocess.run(cmd, capture_output=True)
    if result.returncode != 0:
        print(f"Clip {idx} encode error:\n{result.stderr.decode()[-400:]}", file=sys.stderr)
        sys.exit(1)
    return out


def concat_clips(rendered_clips: list, tmp_dir: Path) -> Path:
    list_file = tmp_dir / "concat.txt"
    with open(list_file, "w") as f:
        for p in rendered_clips:
            f.write(f"file '{p}'\n")
    out = tmp_dir / "concatenated.mp4"
    cmd = [
        "ffmpeg", "-y",
        "-f", "concat", "-safe", "0", "-i", str(list_file),
        "-c:v", "libx264", "-preset", "fast", "-crf", "20",
        "-c:a", "aac", "-b:a", "256k",
        "-movflags", "+faststart",
        str(out)
    ]
    result = subprocess.run(cmd, capture_output=True)
    if result.returncode != 0:
        print(f"Concat error:\n{result.stderr.decode()[-400:]}", file=sys.stderr)
        sys.exit(1)
    return out


def burn_captions(video_path: Path, edl: dict, output_path: Path):
    clips = edl["clips"]
    cumulative = 0.0
    filter_parts = []

    for clip in clips:
        dur = round(clip["out_s"] - clip["in_s"], 3)
        start = cumulative
        end = round(start + dur, 3)

        caption = clip.get("caption_text", "")
        emphasis = clip.get("caption_emphasis", [])
        if not caption:
            cumulative += dur
            continue

        for f in build_drawtext(caption, emphasis, start, end):
            filter_parts.append(f)
        cumulative = end

    if not filter_parts:
        shutil.copy(video_path, output_path)
        return

    filter_str = ",".join(filter_parts)
    cmd = [
        "ffmpeg", "-y",
        "-i", str(video_path),
        "-vf", filter_str,
        "-c:v", "libx264", "-preset", "fast", "-crf", "20",
        "-c:a", "copy",
        "-movflags", "+faststart",
        str(output_path)
    ]
    result = subprocess.run(cmd, capture_output=True)
    if result.returncode != 0:
        print(f"Caption burn error:\n{result.stderr.decode()[-600:]}", file=sys.stderr)
        # Fall back: output without captions
        shutil.copy(video_path, output_path)
        print("Warning: caption burn failed, output has no captions.")


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("edl", help="Path to .edl.json")
    parser.add_argument("--clips-dir", help="Directory with pre-cut clip_N.mp4 files")
    parser.add_argument("--source-video", help="Path to source video (used if no pre-cut clips)")
    parser.add_argument("--output", help="Output MP4 path")
    args = parser.parse_args()

    edl_path = Path(args.edl)
    edl = json.loads(edl_path.read_text())

    clips_dir = Path(args.clips_dir) if args.clips_dir else edl_path.parent / "hyperframes-composition"
    source_video = Path(args.source_video) if args.source_video else None
    if source_video is None and edl.get("video"):
        # CET: default to the EDL's own "video", relative to the EDL file
        source_video = edl_path.parent / edl["video"]
    output_path = Path(args.output) if args.output else edl_path.parent / (edl_path.stem + "_final.mp4")

    print(f"EDL: {edl_path.name}  ({len(edl['clips'])} clips)")
    print(f"Output: {output_path}\n")

    with tempfile.TemporaryDirectory() as tmp:
        tmp_dir = Path(tmp)

        print("Step 1/3 — Rendering clips (crop to 1080x1920)...")
        rendered = []
        for clip in edl["clips"]:
            r = render_clip(clip, clips_dir, source_video, tmp_dir)
            print(f"  ✓ clip_{clip['clip_idx']}  ({round(clip['out_s']-clip['in_s'],2)}s)")
            rendered.append(r)

        print("\nStep 2/3 — Concatenating...")
        concat = concat_clips(rendered, tmp_dir)
        print(f"  ✓ {concat}")

        print("\nStep 3/3 — Burning captions...")
        burn_captions(concat, edl, output_path)
        print(f"  ✓ {output_path}")

    # Verify
    probe = subprocess.run(
        ["ffprobe", "-v", "quiet", "-show_entries", "format=duration", "-of", "csv=p=0", str(output_path)],
        capture_output=True, text=True
    )
    dur = float(probe.stdout.strip())
    print(f"\nDone. Output: {output_path}  ({dur:.2f}s)")


if __name__ == "__main__":
    main()
