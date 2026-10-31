#!/usr/bin/env python3
"""
precut_clips.py — Extract individual clip files from source video based on EDL.

Writes clip_N.mp4 files into the hyperframes-composition directory, then rewrites
the index.html so each clip references its own small file at in_s=0.

Usage:
    python precut_clips.py <edl.json> [--composition-dir PATH]
"""
import json, sys, subprocess, argparse
from pathlib import Path


def precut(edl_path: Path, composition_dir: Path):
    edl = json.loads(edl_path.read_text())
    clips = edl["clips"]

    # Find the source video (resolve relative to composition dir)
    source_rel = clips[0]["source_video"]
    source_file = (composition_dir / source_rel).resolve()
    if not source_file.exists():
        # Try relative to EDL path
        source_file = (edl_path.parent / source_rel).resolve()
    if not source_file.exists():
        print(f"Error: source video not found: {source_rel}", file=sys.stderr)
        sys.exit(1)

    print(f"Source: {source_file}")
    print(f"Cutting {len(clips)} clips into {composition_dir}/\n")

    for clip in clips:
        idx = clip["clip_idx"]
        in_s = clip["in_s"]
        dur = round(clip["out_s"] - clip["in_s"], 4)
        out_file = composition_dir / f"clip_{idx}.mp4"

        cmd = [
            "ffmpeg", "-y",
            "-i", str(source_file),
            "-ss", str(in_s),           # accurate seek (after -i = frame-accurate, no black leader)
            "-t", str(dur),
            "-vf", "scale=-2:1920,crop=1080:1920:(in_w-1080)/2:0,setpts=PTS-STARTPTS",
            "-af", "asetpts=PTS-STARTPTS",
            "-avoid_negative_ts", "make_zero",
            "-c:v", "libx264", "-g", "1",
            "-movflags", "+faststart",
            "-c:a", "aac", "-b:a", "256k",
            str(out_file)
        ]
        print(f"  clip_{idx}  {in_s:.2f}s → {clip['out_s']:.2f}s  ({dur:.2f}s)", flush=True)
        result = subprocess.run(cmd, capture_output=True)
        if result.returncode != 0:
            print(f"  ERROR: {result.stderr.decode()[-300:]}", file=sys.stderr)
            sys.exit(1)

    print("\nAll clips cut. Patching index.html...", flush=True)

    html_path = composition_dir / "index.html"
    html = html_path.read_text()

    import re
    for clip in clips:
        idx = clip["clip_idx"]
        old_src_full = clip["source_video"]                 # e.g. "../synced_output_web.mp4"
        old_src_base = Path(old_src_full).name              # e.g. "synced_output_web.mp4"
        new_src = f"clip_{idx}.mp4"

        # Replace src= in video/audio elements for this clip — handle both path forms
        for old in (old_src_full, old_src_base):
            html = re.sub(
                rf'(id="[va]-{idx}"[^>]*?\bsrc="){re.escape(old)}"',
                rf'\g<1>{new_src}"',
                html, flags=re.DOTALL
            )

        # Zero out data-media-start (clips now start at t=0 in their own file)
        html = re.sub(
            rf'(id="v-{idx}"[^>]*?\bdata-media-start=")[^"]*"',
            r'\g<1>0"',
            html, flags=re.DOTALL
        )
        html = re.sub(
            rf'(id="a-{idx}"[^>]*?\bdata-media-start=")[^"]*"',
            r'\g<1>0"',
            html, flags=re.DOTALL
        )

    # Sanity check
    leftover = re.findall(r'src="[^"]*synced_output_web[^"]*"', html)
    if leftover:
        print(f"  ⚠ WARNING: {len(leftover)} src(s) still reference source video — check path format", file=sys.stderr)
    else:
        print("  ✓ all src paths updated to clip files")

    html_path.write_text(html)
    print("  ✓ index.html patched")
    print(f"\nReady to render:")
    print(f"  cd {composition_dir} && npx hyperframes render --output ../output_edited.mp4 --workers 1")


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("edl", help="Path to .edl.json")
    parser.add_argument("--composition-dir", help="HyperFrames composition directory")
    args = parser.parse_args()

    edl_path = Path(args.edl)
    composition_dir = Path(args.composition_dir) if args.composition_dir else edl_path.parent / "hyperframes-composition"

    if not composition_dir.exists():
        print(f"Error: composition dir not found: {composition_dir}", file=sys.stderr)
        sys.exit(1)

    precut(edl_path, composition_dir)


if __name__ == "__main__":
    main()
