#!/usr/bin/env python3
"""CET photo slideshow Reel (TikTok style): each photo fills 9:16 with a slow push-in,
optional TikTok-style caption, hook on the first slide, CENTRAL end card.
Usage: cet_photo_reel.py <spec.json> <out.mp4>
spec: {"slide_s":1.6, "hook":"..", "slides":[{"src":"photo.jpg","caption":"..","emphasis":[..]}]}"""
import json, subprocess, sys, tempfile
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import cet_tiktok_text as tt  # noqa

W, H, FPS = 1080, 1920, 30


def run(cmd):
    r = subprocess.run(cmd, capture_output=True)
    if r.returncode:
        sys.exit(r.stderr.decode()[-1500:])


def main():
    spec_path = Path(sys.argv[1]); spec = json.loads(spec_path.read_text()); out = Path(sys.argv[2])
    dur = float(spec.get("slide_s", 1.6)); frames = int(dur * FPS)
    with tempfile.TemporaryDirectory() as tmp:
        tmp = Path(tmp); parts = []
        for i, s in enumerate(spec["slides"]):
            src = (spec_path.parent / s["src"]).resolve()
            pngs = []
            if s.get("caption"):
                p = tmp / f"c{i}.png"; tt.caption_png(s["caption"], p, s.get("emphasis", []), y_ratio=0.66); pngs.append(p)
            if i == 0 and spec.get("hook"):
                h = tmp / "hook.png"; tt.hook_png(spec["hook"], h); pngs.append(h)
            # fill 9:16 (cover), then a slow 6% push-in
            vf = (f"scale={W*2}:{H*2}:force_original_aspect_ratio=increase,crop={W*2}:{H*2},"
                  f"zoompan=z='1+0.06*on/{frames}':x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':d={frames}:s={W}x{H}:fps={FPS},"
                  "format=yuv420p")
            inputs = ["-loop", "1", "-t", str(dur), "-i", str(src)]
            fc = f"[0:v]{vf}[v0];"; last = "v0"
            for k, pp in enumerate(pngs, 1):
                inputs += ["-loop", "1", "-t", str(dur), "-i", str(pp)]
                fc += f"[{k}:v]format=rgba,fade=in:st=0:d=0.15:alpha=1[o{k}];[{last}][o{k}]overlay=0:0:shortest=1[v{k}];"
                last = f"v{k}"
            part = tmp / f"p{i}.mp4"
            run(["ffmpeg", "-y", *inputs, "-f", "lavfi", "-t", str(dur), "-i", "anullsrc=r=48000:cl=stereo",
                 "-filter_complex", fc.rstrip(";"), "-map", f"[{last}]", "-map", f"{len(pngs)+1}:a",
                 "-t", str(dur), "-c:v", "libx264", "-preset", "fast", "-crf", "20", "-r", str(FPS),
                 "-c:a", "aac", "-b:a", "128k", str(part)])
            parts.append(part)
        ec = tmp / "end.png"; tt.end_card_png(ec); endv = tmp / "end.mp4"
        run(["ffmpeg", "-y", "-loop", "1", "-t", "2.2", "-i", str(ec), "-f", "lavfi", "-t", "2.2", "-i",
             "anullsrc=r=48000:cl=stereo", "-vf", "fade=in:st=0:d=0.25,format=yuv420p", "-c:v", "libx264",
             "-preset", "fast", "-crf", "20", "-r", str(FPS), "-c:a", "aac", "-b:a", "128k", "-shortest", str(endv)])
        parts.append(endv)
        lst = tmp / "l.txt"; lst.write_text("".join(f"file '{p}'\n" for p in parts))
        run(["ffmpeg", "-y", "-f", "concat", "-safe", "0", "-i", str(lst), "-c:v", "libx264", "-preset", "medium",
             "-crf", "23", "-c:a", "aac", "-b:a", "128k", "-movflags", "+faststart", str(out)])
    print("Done", out)


main()
