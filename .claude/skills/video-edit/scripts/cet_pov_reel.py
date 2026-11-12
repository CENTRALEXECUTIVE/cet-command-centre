#!/usr/bin/env python3
"""CET "POV" reel (Nov 2026) — the look the directors picked from a chauffeur
creator's TikTok: first-person footage, fast 1–2 s cuts, a crisp teal-and-orange
grade (deep blacks, cool shadows, clean whites — never the muddy yellow look),
soft blurred top/bottom edges, a red banner headline + yellow banner subline with
emoji, small pop captions, royalty-free music, CET end card. 1080x1920, 30 fps.

Usage:  python cet_pov_reel.py plan.json out.mp4
plan.json = {"title": "...", "subtitle": "...", "music": "/path.mp3",
             "src_dir": "/clips", "shots": [{"file": "x.mp4", "start": 3.0,
             "dur": 1.4, "speed": 1.0, "caption": "optional"}]}
Edits only what was filmed — never invents claims; no prices on social.
"""
import json, subprocess, sys, tempfile
from pathlib import Path
from PIL import Image, ImageDraw, ImageFont, ImageFilter

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))
import cet_tiktok_text as tt  # noqa: E402

W, H = 1080, 1920
BOLD = str(HERE.parent / "fonts" / "TikTokSans-800.ttf")
SEMI = str(HERE.parent / "fonts" / "TikTokSans-600.ttf")
EMOJI = "/usr/share/fonts/truetype/noto/NotoColorEmoji.ttf"

# The matched grade. Highlights stay at 1.0 (no haze, no yellow skies).
GRADE = ("curves=all='0/0 0.07/0.025 0.22/0.17 0.5/0.52 0.78/0.83 1/1',"
         "colorbalance=rs=-0.06:gs=0.0:bs=0.08:rm=-0.015:bm=0.02,"
         "vibrance=intensity=0.5,eq=saturation=1.12,unsharp=5:5:0.55,vignette=PI/5")


def run(cmd):
    subprocess.run(cmd, check=True, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)


def _is_emoji(ch):
    return ord(ch) > 0x2100 and not ch.isalnum()


def _draw_mixed(img, xy, text, font, fill, size):
    """Text with colour emoji (Noto CBDT renders at 109px, then scaled)."""
    x, y = xy
    ef = ImageFont.truetype(EMOJI, 109)
    asc, _ = font.getmetrics()
    d = ImageDraw.Draw(img)
    for ch in text:
        if _is_emoji(ch) and ch not in "️":
            g = Image.new("RGBA", (136, 128), (0, 0, 0, 0))
            ImageDraw.Draw(g).text((0, 0), ch, font=ef, embedded_color=True)
            g = g.crop(g.getbbox() or (0, 0, 1, 1)).resize((int(size * 1.05), int(size * 1.05)), Image.LANCZOS)
            img.alpha_composite(g, (int(x), int(y + asc - size * 0.95)))
            x += size * 1.12
        elif ch == "️":
            continue
        else:
            d.text((x, y), ch, font=font, fill=fill)
            x += font.getlength(ch)
    return x


def _mixed_len(text, font, size):
    return sum(size * 1.12 if _is_emoji(c) else (0 if c == "️" else font.getlength(c)) for c in text)


def banner_png(title, subtitle, out):
    """Red pill headline + yellow pill subline, top of frame (like the reference)."""
    img = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    d = ImageDraw.Draw(img)
    y = 210
    for text, size, bg, fg, font_path in ((title, 54, (226, 44, 58, 255), (255, 255, 255, 255), BOLD),
                                          (subtitle, 40, (251, 214, 60, 255), (20, 20, 20, 255), SEMI)):
        if not text:
            continue
        f = ImageFont.truetype(font_path, size)
        tw = _mixed_len(text, f, size)
        while tw > W - 170 and size > 30:   # keep inside the safe area
            size -= 2
            f = ImageFont.truetype(font_path, size)
            tw = _mixed_len(text, f, size)
        padx, pady = 26, 12
        asc, desc = f.getmetrics()
        h = asc + desc + pady * 2
        x0 = (W - tw) / 2 - padx
        d.rounded_rectangle([x0, y, x0 + tw + padx * 2, y + h], radius=16, fill=bg)
        _draw_mixed(img, (x0 + padx, y + pady), text, f, fg, size)
        y += h + 10
    img.save(out)


def pop_png(text, out):
    """Small bold caption that pops in mid-frame: white, soft shadow, CET gold accent."""
    img = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    f = ImageFont.truetype(BOLD, 60)
    tw = _mixed_len(text, f, 60)
    x, y = (W - tw) / 2, int(H * 0.60)
    sh = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    _draw_mixed(sh, (x + 3, y + 4), text, f, (0, 0, 0, 200), 60)
    sh = sh.filter(ImageFilter.GaussianBlur(5))
    img.alpha_composite(sh)
    _draw_mixed(img, (x, y), text, f, (255, 255, 255, 255), 60)
    img.save(out)


POPPINS = str(HERE.parent / "fonts" / "Poppins-ExtraBold.ttf")
YELLOW = (255, 225, 60, 255)


def _shadowed(img, draw_fn, blur=6):
    sh = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    draw_fn(ImageDraw.Draw(sh), shadow=True)
    img.alpha_composite(sh.filter(ImageFilter.GaussianBlur(blur)))
    draw_fn(ImageDraw.Draw(img), shadow=False)


def _wrap_lines(text, font, max_w):
    return tt._balanced(text, font, max_w) if hasattr(tt, "_balanced") else [text]


def hook_png_style(text, out, style, highlight=None):
    """The headline, upper third, in one of the TikTok-native looks:
    classic = white TikTok Sans + thin black outline + soft shadow (TikTok's default text)
    viral   = heavy caps, thick black outline, one word in yellow (CapCut captions)
    box     = white rounded box per line, black text (TikTok 'background' style)"""
    img = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    y0 = int(H * 0.19)
    if style == "box":
        f = ImageFont.truetype(BOLD, 64)
        d = ImageDraw.Draw(img)
        for ln in _wrap_lines(text, f, W - 220):
            tw = _mixed_len(ln, f, 64); x0 = (W - tw) / 2 - 26
            d.rounded_rectangle([x0, y0, x0 + tw + 52, y0 + 92], radius=18, fill=(255, 255, 255, 255))
            _draw_mixed(img, (x0 + 26, y0 + 10), ln, f, (12, 12, 12, 255), 64)
            y0 += 98
    elif style == "viral":
        f = ImageFont.truetype(POPPINS, 76)
        words_hi = {w.upper() for w in (highlight or "").split()}
        for ln in _wrap_lines(text.upper(), f, W - 200):
            d = ImageDraw.Draw(img); tw = d.textlength(ln, font=f); x = (W - tw) / 2
            for w in ln.split():
                d.text((x, y0), w, font=f, fill=YELLOW if w.strip(".,!?") in words_hi else (255, 255, 255, 255),
                       stroke_width=8, stroke_fill=(0, 0, 0, 255))
                x += d.textlength(w + " ", font=f) + 4
            y0 += 100
    else:  # classic
        f = ImageFont.truetype(BOLD, 74)
        lines = _wrap_lines(text, f, W - 200)

        def draw(d, shadow):
            y = y0
            for ln in lines:
                tw = d.textlength(ln, font=f); x = (W - tw) / 2
                if shadow:
                    d.text((x, y + 5), ln, font=f, fill=(0, 0, 0, 170))
                else:
                    d.text((x, y), ln, font=f, fill=(255, 255, 255, 255), stroke_width=3, stroke_fill=(0, 0, 0, 255))
                y += 92
        _shadowed(img, draw)
    img.save(out)


def caption_png_style(text, out, style):
    """Mid-frame step caption in the same family of looks (viral: last word yellow)."""
    img = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    y = int(H * 0.58)
    if style == "box":
        f = ImageFont.truetype(BOLD, 60); tw = _mixed_len(text, f, 60); x0 = (W - tw) / 2 - 24
        ov = Image.new("RGBA", (W, H), (0, 0, 0, 0))
        ImageDraw.Draw(ov).rounded_rectangle([x0, y, x0 + tw + 48, y + 88], radius=16, fill=(0, 0, 0, 165))
        img.alpha_composite(ov); _draw_mixed(img, (x0 + 24, y + 10), text, f, (255, 255, 255, 255), 60)
    elif style in ("viral", "mix"):
        f = ImageFont.truetype(POPPINS, 92); d = ImageDraw.Draw(img)
        words = text.upper().split(); tw = d.textlength(" ".join(words), font=f) + 4 * (len(words) - 1); x = (W - tw) / 2
        for i, w in enumerate(words):
            d.text((x, y), w, font=f, fill=YELLOW if i == len(words) - 1 else (255, 255, 255, 255),
                   stroke_width=9, stroke_fill=(0, 0, 0, 255))
            x += d.textlength(w + " ", font=f) + 4
    else:  # classic
        f = ImageFont.truetype(BOLD, 70)

        def draw(d, shadow):
            tw = d.textlength(text, font=f); x = (W - tw) / 2
            if shadow:
                d.text((x, y + 5), text, font=f, fill=(0, 0, 0, 170))
            else:
                d.text((x, y), text, font=f, fill=(255, 255, 255, 255), stroke_width=3, stroke_fill=(0, 0, 0, 255))
        _shadowed(img, draw)
    img.save(out)


def edge_mask(out):
    """Alpha mask: opaque at top/bottom ~17%, fading to clear — for the soft edge blur."""
    m = Image.new("L", (W, H), 0)
    px = m.load()
    band = int(H * 0.17)
    for yy in range(H):
        a = max(0, band - yy) / band if yy < band else max(0, yy - (H - band)) / band if yy > H - band else 0
        v = int(255 * min(1, a * 1.1))
        for xx in range(W):
            px[xx, yy] = v
    m.save(out)


def main(plan_path, out):
    plan = json.loads(Path(plan_path).read_text())
    src = Path(plan["src_dir"])
    tmp = Path(tempfile.mkdtemp(prefix="cetpov_"))
    edge_mask(tmp / "mask.png")
    style = plan.get("text_style", "banner")   # banner | classic | viral | box | mix
    if style == "banner":
        banner_png(plan["title"], plan.get("subtitle", ""), tmp / "banner.png")
    else:
        hook_png_style(plan["title"], tmp / "banner.png", "classic" if style == "mix" else style, plan.get("hook_highlight"))

    segs = []
    for i, s in enumerate(plan["shots"]):
        seg = tmp / f"s{i:02d}.mp4"
        speed = float(s.get("speed", 1.0))
        src_dur = float(s["dur"]) * speed
        fc = (f"[0:v]setpts=PTS/{speed},scale={W}:{H}:force_original_aspect_ratio=increase,crop={W}:{H},"
              f"fps=30,format=yuv420p,{GRADE},split[a][b];"
              f"[b]gblur=sigma=16[bl];[2:v]scale={W}:{H},format=gray[m];[bl][m]alphamerge[blm];"
              f"[a][blm]overlay=0:0[g];[g][1:v]overlay=0:0")
        inputs = ["-ss", str(s["start"]), "-t", f"{src_dur:.3f}", "-i", str(src / s["file"]),
                  "-i", str(tmp / "banner.png"), "-loop", "1", "-i", str(tmp / "mask.png")]
        if s.get("caption"):
            if style == "banner":
                pop_png(s["caption"], tmp / f"c{i}.png")
            else:
                caption_png_style(s["caption"], tmp / f"c{i}.png", style)
            inputs += ["-i", str(tmp / f"c{i}.png")]
            fc += f"[v0];[v0][3:v]overlay=0:0:enable='gte(t,0.12)'"
        run(["ffmpeg", "-y", *inputs, "-filter_complex", fc, "-t", f"{float(s['dur']):.3f}",
             "-an", "-c:v", "libx264", "-preset", "veryfast", "-crf", "18", "-pix_fmt", "yuv420p", str(seg)])
        segs.append(seg)

    tt.end_card_png(str(tmp / "end.png"))
    end = tmp / "end.mp4"
    run(["ffmpeg", "-y", "-loop", "1", "-t", "2.2", "-i", str(tmp / "end.png"), "-vf", f"scale={W}:{H},fps=30,format=yuv420p",
         "-c:v", "libx264", "-preset", "veryfast", "-crf", "18", str(end)])
    segs.append(end)

    lst = tmp / "list.txt"
    lst.write_text("".join(f"file '{p}'\n" for p in segs))
    body = tmp / "body.mp4"
    run(["ffmpeg", "-y", "-f", "concat", "-safe", "0", "-i", str(lst), "-c", "copy", str(body)])
    dur = float(subprocess.check_output(["ffprobe", "-v", "error", "-show_entries", "format=duration",
                                         "-of", "csv=p=0", str(body)]).decode().strip())
    run(["ffmpeg", "-y", "-i", str(body), "-ss", str(plan.get("music_start", 0)), "-i", plan["music"], "-filter_complex",
         f"[1:a]atrim=0:{dur:.2f},afade=t=in:d=0.4,afade=t=out:st={dur - 1.6:.2f}:d=1.6,volume=0.85[a]",
         "-map", "0:v", "-map", "[a]", "-c:v", "copy", "-c:a", "aac", "-b:a", "192k", "-shortest", out])
    print(out, f"{dur:.1f}s")


if __name__ == "__main__":
    main(sys.argv[1], sys.argv[2])
