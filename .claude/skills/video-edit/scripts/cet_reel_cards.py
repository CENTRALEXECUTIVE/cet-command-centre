#!/usr/bin/env python3
"""CET 'numbered glass cards' Reel: a row of floating glass cards (1..N) over b-roll,
revealed one step at a time, active card in gold, big caption below, hook first, end card.
Usage: reel_cards.py <spec.json> <out.mp4>
spec: {"bg":[{"src":..,"in":s,"out":s},...], "hook":"..", "hook_emphasis":[..],
       "hook_s":2.6, "step_s":2.4, "steps":[{"label":"Flight","sub":"tracked","caption":"..","emphasis":[..]}]}"""
import json, subprocess, sys, tempfile
from pathlib import Path
from PIL import Image, ImageDraw, ImageFont, ImageFilter

sys.path.insert(0, "/home/user/cet-command-centre/.claude/skills/video-edit/scripts")
import burn_captions_pil as cap  # noqa
sys.path.insert(0, str(Path(__file__).parent))

W, H, FPS = 1080, 1920, 30
GOLD = (251, 186, 42, 255)
FONT = cap.FONT_PATH
BOLD = "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf"


def run(cmd):
    r = subprocess.run(cmd, capture_output=True)
    if r.returncode:
        sys.exit(r.stderr.decode()[-1500:])


def caption(d, text, y_ratio, size, emphasis):
    f = ImageFont.truetype(FONT, size)
    lines = cap.wrap_text(text.upper(), f, W - 140)
    lh = int(size * 1.15); y = int(H * y_ratio) - lh * len(lines) // 2
    emph = {e.upper() for e in emphasis}
    for ln in lines:
        words = ln.split(); widths = [f.getlength(w + " ") for w in words]
        x = (W - (sum(widths) - f.getlength(" "))) / 2
        for w, wd in zip(words, widths):
            col = GOLD if w.strip(".,!?") in emph else (255, 255, 255, 255)
            d.text((x, y), w, font=f, fill=col, stroke_width=7, stroke_fill=(0, 0, 0, 255))
            x += wd
        y += lh


def cards_png(steps, shown, active, out, cap_text=None, emph=()):
    img = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    n = len(steps); gap = 18; cw = (W - 80 - gap * (n - 1)) // n; ch = 250; top = 300
    fnum = ImageFont.truetype(BOLD, 54); flab = ImageFont.truetype(FONT, 30); fsub = ImageFont.truetype(FONT, 25)
    for i in range(shown):
        x0 = 40 + i * (cw + gap); y0 = top - (14 if i == active else 0)
        layer = Image.new("RGBA", (W, H), (0, 0, 0, 0)); ld = ImageDraw.Draw(layer)
        fill = (20, 20, 20, 205) if i == active else (30, 30, 30, 150)
        border = GOLD if i == active else (255, 255, 255, 70)
        ld.rounded_rectangle([x0, y0, x0 + cw, y0 + ch], radius=22, fill=fill, outline=border, width=4 if i == active else 2)
        ld.text((x0 + 20, y0 + 18), str(i + 1), font=fnum, fill=GOLD)
        ld.text((x0 + 20, y0 + 110), steps[i]["label"], font=flab, fill=(255, 255, 255, 255))
        ld.text((x0 + 20, y0 + 150), steps[i]["sub"], font=fsub, fill=(200, 194, 178, 255))
        # thin placeholder lines like a document card
        ld.rounded_rectangle([x0 + 20, y0 + 200, x0 + cw - 30, y0 + 206], radius=3, fill=(255, 255, 255, 45))
        if i == active:
            glow = layer.filter(ImageFilter.GaussianBlur(10))
            img = Image.alpha_composite(img, glow)
        img = Image.alpha_composite(img, layer)
    if cap_text:
        caption(ImageDraw.Draw(img), cap_text, 0.70, 100, emph)
    img.save(out)


def end_card(out_png):
    img = Image.new("RGBA", (W, H), (13, 13, 13, 255)); d = ImageDraw.Draw(img)
    f1 = ImageFont.truetype(BOLD, 92); word = "CENTRAL"; sp = 34
    total = sum(f1.getlength(c) for c in word) + sp * (len(word) - 1); x = (W - total) / 2
    for c in word:
        d.text((x, 760), c, font=f1, fill=(255, 255, 255, 255)); x += f1.getlength(c) + sp
    d.rectangle([(W / 2 - 180, 890), (W / 2 + 180, 896)], fill=GOLD)
    f2 = ImageFont.truetype(FONT, 46)
    for i, (t, col) in enumerate([("EXECUTIVE TRANSFERS", (255, 255, 255, 255)),
                                  ("Sheffield · South Yorkshire", (190, 182, 160, 255)),
                                  ("centralexecutivetransfers.co.uk", GOLD)]):
        d.text(((W - f2.getlength(t)) / 2, 940 + i * 70), t, font=f2, fill=col)
    img.save(out_png)


def main():
    spec = json.loads(Path(sys.argv[1]).read_text()); out = Path(sys.argv[2])
    steps = spec["steps"]; hs, ss = spec.get("hook_s", 2.6), spec.get("step_s", 2.4)
    total = hs + ss * len(steps)
    with tempfile.TemporaryDirectory() as tmp:
        tmp = Path(tmp)
        # 1) background: concat bg segments, crop 9:16, slight darken, trim to total
        segs = []
        for k, b in enumerate(spec["bg"]):
            src = Path(b["src"]); sw, sh = cap.get_video_dims(src); o = tmp / f"bg{k}.mp4"
            run(["ffmpeg", "-y", "-ss", str(b["in"]), "-t", str(b["out"] - b["in"]), "-i", str(src),
                 "-vf", f"{cap.crop_filter(sw, sh)},eq=brightness=-0.06:saturation=0.9,fps={FPS},format=yuv420p",
                 "-af", "volume=0.35,aresample=48000", "-ac", "2", "-c:v", "libx264", "-preset", "fast", "-crf", "20",
                 "-c:a", "aac", str(o)])
            segs.append(o)
        lst = tmp / "bg.txt"; lst.write_text("".join(f"file '{p}'\n" for p in segs))
        bg = tmp / "bg.mp4"
        run(["ffmpeg", "-y", "-f", "concat", "-safe", "0", "-i", str(lst), "-t", str(total), "-c", "copy", str(bg)])
        # 2) overlay states
        pngs = []
        hook = tmp / "s0.png"; img = Image.new("RGBA", (W, H), (0, 0, 0, 0))
        caption(ImageDraw.Draw(img), spec["hook"], 0.45, 108, spec.get("hook_emphasis", [])); img.save(hook)
        pngs.append((hook, 0, hs))
        for i, st in enumerate(steps):
            p = tmp / f"s{i+1}.png"; cards_png(steps, i + 1, i, p, st["caption"], st.get("emphasis", []))
            pngs.append((p, hs + i * ss, hs + (i + 1) * ss))
        inputs = ["-i", str(bg)]; fc = ""; last = "0:v"
        for k, (p, a, b) in enumerate(pngs, 1):
            inputs += ["-loop", "1", "-t", str(total), "-i", str(p)]
            fc += (f"[{k}:v]format=rgba,fade=in:st={a}:d=0.2:alpha=1[o{k}];"
                   f"[{last}][o{k}]overlay=0:0:enable='between(t,{a},{b - 0.001})'[v{k}];")
            last = f"v{k}"
        body = tmp / "body.mp4"
        run(["ffmpeg", "-y", *inputs, "-filter_complex", fc.rstrip(";"), "-map", f"[{last}]", "-map", "0:a?",
             "-t", str(total), "-c:v", "libx264", "-preset", "fast", "-crf", "20", "-c:a", "aac", "-b:a", "160k", str(body)])
        ec = tmp / "end.png"; end_card(ec); endv = tmp / "end.mp4"
        run(["ffmpeg", "-y", "-loop", "1", "-t", "2.2", "-i", str(ec), "-f", "lavfi", "-t", "2.2", "-i",
             "anullsrc=r=48000:cl=stereo", "-vf", "fade=in:st=0:d=0.25,format=yuv420p", "-c:v", "libx264",
             "-preset", "fast", "-crf", "20", "-r", str(FPS), "-c:a", "aac", "-b:a", "160k", "-shortest", str(endv)])
        l2 = tmp / "all.txt"; l2.write_text(f"file '{body}'\nfile '{endv}'\n")
        run(["ffmpeg", "-y", "-f", "concat", "-safe", "0", "-i", str(l2), "-c:v", "libx264", "-preset", "medium",
             "-crf", "24", "-c:a", "aac", "-b:a", "160k", "-movflags", "+faststart", str(out)])
    print("Done", out)


main()
