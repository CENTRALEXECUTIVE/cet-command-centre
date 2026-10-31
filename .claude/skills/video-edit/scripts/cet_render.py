#!/usr/bin/env python3
"""
cet_render.py — CET one-step renderer: EDL -> finished 1080x1920 MP4.

For each EDL clip: cut in_s..out_s from the source, centre-crop to 9:16, overlay a
wrapped caption (white, emphasis words in CET gold) via burn_captions_pil, then join.
No browser / HyperFrames needed.

Usage:
    python cet_render.py <edl.json> [--source-video PATH] [--output PATH] [--no-captions]
"""
import argparse, json, subprocess, sys, tempfile
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import burn_captions_pil as cap  # noqa: E402


def run(cmd):
    r = subprocess.run(cmd, capture_output=True)
    if r.returncode != 0:
        sys.exit(f"ffmpeg failed: {r.stderr.decode()[-500:]}")


def main():
    p = argparse.ArgumentParser()
    p.add_argument("edl")
    p.add_argument("--source-video")
    p.add_argument("--output")
    p.add_argument("--no-captions", action="store_true")
    a = p.parse_args()

    edl_path = Path(a.edl)
    edl = json.loads(edl_path.read_text())
    out = Path(a.output) if a.output else edl_path.with_name(edl_path.stem.replace(".edl", "") + "_cet.mp4")

    with tempfile.TemporaryDirectory() as tmp:
        tmp = Path(tmp)
        parts = []
        for clip in edl["clips"]:
            i = clip["clip_idx"]
            src = Path(a.source_video) if a.source_video else edl_path.parent / clip.get("source_video", edl["video"])
            raw = tmp / f"raw_{i}.mp4"
            run(["ffmpeg", "-y", "-ss", str(clip["in_s"]), "-to", str(clip["out_s"]), "-i", str(src),
                 "-c:v", "libx264", "-preset", "fast", "-crf", "18", "-r", str(cap.FPS),
                 "-c:a", "aac", "-b:a", "192k", "-ar", "48000", "-ac", "2", str(raw)])
            png = tmp / f"cap_{i}.png"
            text = "" if a.no_captions else clip.get("caption_text", "")
            cap.make_caption_image(text, clip.get("caption_emphasis", []), png)
            final = tmp / f"clip_{i}.mp4"
            cap.overlay_caption_on_clip(raw, png, final)
            parts.append(final)

        lst = tmp / "list.txt"
        lst.write_text("".join(f"file '{x}'\n" for x in parts))
        run(["ffmpeg", "-y", "-f", "concat", "-safe", "0", "-i", str(lst),
             "-c:v", "libx264", "-preset", "fast", "-crf", "20", "-c:a", "aac", "-b:a", "192k",
             "-movflags", "+faststart", str(out)])
    print(f"Done: {out}")


if __name__ == "__main__":
    main()
