#!/usr/bin/env python3
"""CET Reel v2 renderer: punch-in zoom per clip, pop-in captions, optional hook line,
branded end card. Usage: reel_v2.py <edl.json> <out.mp4>"""
import json, subprocess, sys, tempfile
from pathlib import Path
from PIL import Image, ImageDraw, ImageFont

sys.path.insert(0, str(Path(__file__).resolve().parent))
import burn_captions_pil as cap  # noqa
import cet_tiktok_text as tt  # noqa

W, H, FPS = 1080, 1920, 30
GOLD = (251, 186, 42, 255)
FONT = cap.FONT_PATH
BOLD = "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf"


def run(cmd):
    r = subprocess.run(cmd, capture_output=True)
    if r.returncode:
        sys.exit(r.stderr.decode()[-1500:])


def text_png(lines, out, y_ratio, size, emphasis=()):
    img = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    d = ImageDraw.Draw(img)
    f = ImageFont.truetype(FONT, size)
    wrapped = []
    for ln in lines:
        wrapped += cap.wrap_text(ln.upper(), f, W - 140)
    lh = int(size * 1.15)
    y = int(H * y_ratio) - lh * len(wrapped) // 2
    emph = {e.upper() for e in emphasis}
    for ln in wrapped:
        words = ln.split()
        widths = [f.getlength(w + " ") for w in words]
        x = (W - (sum(widths) - f.getlength(" "))) / 2
        for w, wd in zip(words, widths):
            col = GOLD if w.strip(".,!?") in emph else (255, 255, 255, 255)
            d.text((x, y), w, font=f, fill=col, stroke_width=7, stroke_fill=(0, 0, 0, 255))
            x += wd
        y += lh
    img.save(out)


def end_card(out_png):
    img = Image.new("RGBA", (W, H), (13, 13, 13, 255))
    d = ImageDraw.Draw(img)
    f1 = ImageFont.truetype(BOLD, 92)
    word = "CENTRAL"
    # letter-spaced wordmark like the email header
    sp = 34
    total = sum(f1.getlength(c) for c in word) + sp * (len(word) - 1)
    x = (W - total) / 2
    for c in word:
        d.text((x, 760), c, font=f1, fill=(255, 255, 255, 255))
        x += f1.getlength(c) + sp
    d.rectangle([(W / 2 - 180, 890), (W / 2 + 180, 896)], fill=GOLD)
    f2 = ImageFont.truetype(FONT, 46)
    for i, (t, col) in enumerate([("EXECUTIVE TRANSFERS", (255, 255, 255, 255)),
                                  ("Sheffield · South Yorkshire", (190, 182, 160, 255)),
                                  ("centralexecutivetransfers.co.uk", GOLD)]):
        tw = f2.getlength(t)
        d.text(((W - tw) / 2, 940 + i * 70), t, font=f2, fill=col)
    img.save(out_png)


def main():
    edl_path = Path(sys.argv[1]); out = Path(sys.argv[2])
    edl = json.loads(edl_path.read_text())
    with tempfile.TemporaryDirectory() as tmp:
        tmp = Path(tmp); parts = []
        for c in edl["clips"]:
            i = c["clip_idx"]; dur = c["out_s"] - c["in_s"]
            src = (edl_path.parent / c["source_video"]).resolve()
            sw, sh = cap.get_video_dims(src)
            frames = int(dur * FPS)
            zoom = f"zoompan=z='1+0.07*on/{frames}':x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':d=1:s={W}x{H}:fps={FPS}"
            pngs = []
            p = tmp / f"cap{i}.png"; tt.caption_png(c["caption_text"], p, c.get("caption_emphasis", [])); pngs.append(p)
            if c.get("hook"):
                h = tmp / f"hook{i}.png"; tt.hook_png(c["hook"], h); pngs.append(h)
            inputs = ["-ss", str(c["in_s"]), "-t", str(dur), "-i", str(src)]
            fc = f"[0:v]{cap.crop_filter(sw, sh)},{zoom},format=yuv420p[v0];"
            last = "v0"
            for k, pp in enumerate(pngs, 1):
                inputs += ["-loop", "1", "-t", str(dur), "-i", str(pp)]
                fc += f"[{k}:v]format=rgba,fade=in:st=0:d=0.18:alpha=1,scale=iw:ih[o{k}];[{last}][o{k}]overlay=0:0:shortest=1[v{k}];"
                last = f"v{k}"
            fc += f"[0:a]volume=0.45,aresample=48000[a]"
            part = tmp / f"part{i}.mp4"
            run(["ffmpeg", "-y", *inputs, "-filter_complex", fc, "-map", f"[{last}]", "-map", "[a]",
                 "-c:v", "libx264", "-preset", "fast", "-crf", "20", "-r", str(FPS),
                 "-c:a", "aac", "-b:a", "160k", "-ac", "2", str(part)])
            parts.append(part)
        ec = tmp / "end.png"; tt.end_card_png(ec)
        endv = tmp / "end.mp4"
        run(["ffmpeg", "-y", "-loop", "1", "-t", "2.2", "-i", str(ec), "-f", "lavfi", "-t", "2.2", "-i",
             "anullsrc=r=48000:cl=stereo", "-vf", "fade=in:st=0:d=0.25,format=yuv420p", "-c:v", "libx264",
             "-preset", "fast", "-crf", "20", "-r", str(FPS), "-c:a", "aac", "-b:a", "160k", "-shortest", str(endv)])
        parts.append(endv)
        lst = tmp / "l.txt"; lst.write_text("".join(f"file '{p}'\n" for p in parts))
        run(["ffmpeg", "-y", "-f", "concat", "-safe", "0", "-i", str(lst), "-c:v", "libx264", "-preset", "medium",
             "-crf", "24", "-c:a", "aac", "-b:a", "160k", "-movflags", "+faststart", str(out)])
    print("Done", out)


main()
