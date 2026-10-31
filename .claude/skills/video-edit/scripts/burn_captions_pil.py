#!/usr/bin/env python3
"""
burn_captions_pil.py — Burn styled captions onto each pre-cut clip using Pillow + ffmpeg overlay.

Generates a caption PNG per clip, overlays it onto the clip video, concatenates final output.

Usage:
    python burn_captions_pil.py <edl.json> [--clips-dir PATH] [--output PATH]
"""
import json, sys, subprocess, tempfile, argparse
from pathlib import Path
from PIL import Image, ImageDraw, ImageFont

W, H = 1080, 1920
FONT_PATH = str(Path(__file__).resolve().parent.parent / "fonts" / "TikTokSans_28pt-Medium.ttf")  # CET: bundled font (was macOS Impact)
FONT_SIZE = 72
FPS = 30
CAPTION_Y_RATIO = 0.84          # center of text block at 84% down the frame


def get_video_dims(path: Path) -> tuple[int, int]:
    cmd = ["ffprobe", "-v", "quiet", "-print_format", "json", "-show_streams", str(path)]
    out = json.loads(subprocess.run(cmd, capture_output=True, text=True).stdout)
    v = next(s for s in out["streams"] if s["codec_type"] == "video")
    return int(v["width"]), int(v["height"])


def get_rotation(path: Path) -> int:
    cmd = ["ffprobe", "-v", "quiet", "-print_format", "json",
           "-show_streams", "-show_entries", "stream_tags=rotate", str(path)]
    out = json.loads(subprocess.run(cmd, capture_output=True, text=True).stdout)
    for s in out.get("streams", []):
        tags = s.get("tags", {})
        rot = tags.get("rotate") or tags.get("Rotate") or tags.get("ROTATE")
        if rot:
            return int(rot)
    return 0


def crop_filter(src_w: int, src_h: int) -> str:
    target_ar = W / H  # 0.5625 (portrait)
    src_ar = src_w / src_h
    if src_ar > target_ar:
        new_w = int(src_h * target_ar)
        new_h = src_h
    else:
        new_w = src_w
        new_h = int(src_w / target_ar)
    return f"crop={new_w}:{new_h}:(iw-{new_w})/2:(ih-{new_h})/2,scale={W}:{H}"


def wrap_text(text: str, font: ImageFont.FreeTypeFont, max_width: int) -> list[str]:
    """Wrap text to fit max_width, returning list of lines."""
    words = text.split()
    lines = []
    current = []
    for word in words:
        test = " ".join(current + [word])
        w = font.getlength(test)
        if w > max_width and current:
            lines.append(" ".join(current))
            current = [word]
        else:
            current.append(word)
    if current:
        lines.append(" ".join(current))
    return lines


def draw_text_with_border(draw: ImageDraw.ImageDraw, pos: tuple, text: str,
                          font: ImageFont.FreeTypeFont, fill: tuple, border_w: int = 5):
    draw.text(pos, text, font=font, fill=fill, stroke_width=border_w, stroke_fill=(0, 0, 0, 255))


def make_caption_image(caption_text: str, emphasis_words: list, out_path: Path):
    """Generate a 1080x1920 RGBA PNG with styled caption."""
    img = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    draw = ImageDraw.Draw(img)
    font = ImageFont.truetype(FONT_PATH, FONT_SIZE)

    text_upper = caption_text.upper()
    emphasis_set = {w.lower().strip(".,!?\"'") for w in emphasis_words}
    max_text_w = int(W * 0.90)

    lines = wrap_text(text_upper, font, max_text_w)
    line_h = FONT_SIZE * 1.25
    total_h = len(lines) * line_h
    y_start = int(H * CAPTION_Y_RATIO - total_h / 2)

    for li, line in enumerate(lines):
        y = int(y_start + li * line_h)
        # Measure line width for centering
        line_w = font.getlength(line)
        x_start = (W - line_w) / 2

        # Draw word by word for color control
        words = line.split()
        x = x_start
        space_w = font.getlength(" ")
        for word in words:
            clean = word.lower().strip(".,!?\"'")
            color = (251, 186, 42, 255) if clean in emphasis_set else (255, 255, 255, 255)
            draw_text_with_border(draw, (x, y), word, font, color)
            x += font.getlength(word) + space_w

    img.save(str(out_path), "PNG")


def overlay_caption_on_clip(clip_path: Path, caption_png: Path, out_path: Path):
    """Center-crop clip to 1080x1920, apply rotation correction, then overlay caption PNG."""
    src_w, src_h = get_video_dims(clip_path)
    rotation = get_rotation(clip_path)

    filters = []
    if rotation in (90, 270):
        # Swap dims for correct crop after rotation
        src_w, src_h = src_h, src_w
        transpose = "1" if rotation == 90 else "2"
        filters.append(f"transpose={transpose}")

    filters.append(crop_filter(src_w, src_h))
    vf = ",".join(filters)

    cmd = [
        "ffmpeg", "-y",
        "-i", str(clip_path),
        "-i", str(caption_png),
        "-filter_complex", f"[0:v]{vf}[cropped];[cropped][1:v]overlay=0:0",
        "-c:v", "libx264", "-preset", "fast", "-crf", "20",
        "-c:a", "copy",
        "-movflags", "+faststart",
        str(out_path)
    ]
    result = subprocess.run(cmd, capture_output=True)
    if result.returncode != 0:
        print(f"Overlay error: {result.stderr.decode()[-300:]}", file=sys.stderr)
        sys.exit(1)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("edl", help="Path to .edl.json")
    parser.add_argument("--clips-dir", help="Directory with clip_N.mp4 pre-cut files")
    parser.add_argument("--output", help="Output MP4 path")
    args = parser.parse_args()

    edl_path = Path(args.edl)
    edl = json.loads(edl_path.read_text())
    clips = edl["clips"]

    clips_dir = Path(args.clips_dir) if args.clips_dir else edl_path.parent / "hyperframes-composition"
    output_path = Path(args.output) if args.output else edl_path.parent / (edl_path.stem + "_final.mp4")

    print(f"Burning captions onto {len(clips)} clips → {output_path}\n")

    with tempfile.TemporaryDirectory() as tmp:
        tmp_dir = Path(tmp)
        captioned_clips = []

        for clip in clips:
            idx = clip["clip_idx"]
            clip_file = clips_dir / f"clip_{idx}.mp4"
            if not clip_file.exists():
                print(f"Error: {clip_file} not found. Run render_ffmpeg.py first.", file=sys.stderr)
                sys.exit(1)

            caption = clip.get("caption_text", "")
            emphasis = clip.get("caption_emphasis", [])

            if caption:
                png_path = tmp_dir / f"cap_{idx}.png"
                make_caption_image(caption, emphasis, png_path)

                cap_clip = tmp_dir / f"capped_{idx}.mp4"
                overlay_caption_on_clip(clip_file, png_path, cap_clip)
                captioned_clips.append(cap_clip)
                print(f"  ✓ clip_{idx} — {caption[:50]}")
            else:
                captioned_clips.append(clip_file)
                print(f"  – clip_{idx} — (no caption)")

        # Concatenate
        print("\nConcatenating...")
        list_file = tmp_dir / "concat.txt"
        with open(list_file, "w") as f:
            for p in captioned_clips:
                f.write(f"file '{p}'\n")

        cmd = [
            "ffmpeg", "-y",
            "-f", "concat", "-safe", "0", "-i", str(list_file),
            "-c:v", "libx264", "-preset", "fast", "-crf", "20",
            "-c:a", "aac", "-b:a", "256k",
            "-movflags", "+faststart",
            str(output_path)
        ]
        result = subprocess.run(cmd, capture_output=True)
        if result.returncode != 0:
            print(f"Concat error:\n{result.stderr.decode()[-400:]}", file=sys.stderr)
            sys.exit(1)

    probe = subprocess.run(
        ["ffprobe", "-v", "quiet", "-show_entries", "format=duration", "-of", "csv=p=0", str(output_path)],
        capture_output=True, text=True
    )
    dur = float(probe.stdout.strip())
    print(f"\n✓ Done: {output_path}  ({dur:.2f}s)")


if __name__ == "__main__":
    main()
