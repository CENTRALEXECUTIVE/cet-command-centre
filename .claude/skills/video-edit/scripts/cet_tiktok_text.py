#!/usr/bin/env python3
"""CET TikTok-native text styles (Nov 2026). TikTok Sans, sentence case.

hook_png(text, out)        -> TikTok "background" style: each line on its own rounded
                              white pill, black TikTok Sans ExtraBold. Upper third.
caption_png(text, out, emphasis=())
                           -> clean white TikTok Sans SemiBold with a soft drop shadow
                              (no heavy outline); 1 word may be CET gold. Sits at ~60%
                              height, inside TikTok/Reels safe zones (bottom 22% and the
                              right-hand button column stay clear).
end_card_png(out)          -> black card, CENTRAL wordmark, gold rule, website.
"""
from pathlib import Path
from PIL import Image, ImageDraw, ImageFont, ImageFilter

W, H = 1080, 1920
FONTS = Path(__file__).resolve().parent.parent / "fonts"
BOLD = str(FONTS / "TikTokSans-800.ttf")
SEMI = str(FONTS / "TikTokSans-600.ttf")
GOLD = (251, 186, 42, 255)
WHITE = (255, 255, 255, 255)
SAFE_X = 90            # left/right margin; right column (likes/share) needs ~150px
MAX_W = W - SAFE_X - 170


def _wrap(text, font, max_w):
    words, lines, cur = text.split(), [], []
    for w in words:
        t = " ".join(cur + [w])
        if font.getlength(t) > max_w and cur:
            lines.append(" ".join(cur)); cur = [w]
        else:
            cur.append(w)
    if cur:
        lines.append(" ".join(cur))
    return lines


def hook_png(text, out, y_ratio=0.24, size=64):
    img = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    d = ImageDraw.Draw(img)
    f = ImageFont.truetype(BOLD, size)
    lines = _balanced(text, f, MAX_W - 60)
    padx, pady, gap = 28, 14, 6
    asc, desc = f.getmetrics()
    lh = asc + desc + pady * 2
    y = int(H * y_ratio) - (lh * len(lines) + gap * (len(lines) - 1)) // 2
    cx = SAFE_X + MAX_W / 2
    for ln in lines:
        tw = f.getlength(ln)
        x0 = cx - tw / 2 - padx
        d.rounded_rectangle([x0, y, x0 + tw + padx * 2, y + lh], radius=18, fill=WHITE)
        d.text((x0 + padx, y + pady), ln, font=f, fill=(12, 12, 12, 255))
        y += lh + gap
    img.save(out)


def _balanced(text, font, max_w):
    """Wrap, then even out the lines so no word is left on its own."""
    lines = _wrap(text, font, max_w)
    if len(lines) < 2:
        return lines
    words = text.split(); n = len(lines)
    best, best_w = lines, max(font.getlength(l) for l in lines)
    if n == 2:
        for i in range(1, len(words)):
            a, b = " ".join(words[:i]), " ".join(words[i:])
            w = max(font.getlength(a), font.getlength(b))
            if w <= max_w and w < best_w:
                best, best_w = [a, b], w
    return best


def caption_png(text, out, emphasis=(), y_ratio=0.60, size=60):
    """TikTok 'background' caption: white TikTok Sans on a dark translucent rounded box
    per line (readable on any footage), 1 word may be CET gold."""
    img = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    d = ImageDraw.Draw(img)
    f = ImageFont.truetype(BOLD, size)
    lines = _balanced(text, f, MAX_W - 60)
    padx, pady, gap = 24, 12, 4
    asc, desc = f.getmetrics(); lh = asc + desc + pady * 2
    y = int(H * y_ratio) - (lh * len(lines) + gap * (len(lines) - 1)) // 2
    cx = SAFE_X + MAX_W / 2
    emph = {e.lower() for e in emphasis}
    for ln in lines:
        words = ln.split(); sp = f.getlength(" ")
        widths = [f.getlength(w) for w in words]; tw = sum(widths) + sp * (len(words) - 1)
        x0 = cx - tw / 2 - padx
        d.rounded_rectangle([x0, y, x0 + tw + padx * 2, y + lh], radius=16, fill=(0, 0, 0, 165))
        x = x0 + padx
        for w, wd in zip(words, widths):
            col = GOLD if w.strip(".,!?:;").lower() in emph else WHITE
            d.text((x, y + pady), w, font=f, fill=col)
            x += wd + sp
        y += lh + gap
    img.save(out)


def end_card_png(out):
    img = Image.new("RGBA", (W, H), (10, 10, 10, 255)); d = ImageDraw.Draw(img)
    f1 = ImageFont.truetype(BOLD, 96); word = "CENTRAL"; sp = 30
    total = sum(f1.getlength(c) for c in word) + sp * (len(word) - 1); x = (W - total) / 2
    for c in word:
        d.text((x, 760), c, font=f1, fill=WHITE); x += f1.getlength(c) + sp
    d.rectangle([(W / 2 - 170, 900), (W / 2 + 170, 905)], fill=GOLD)
    f2 = ImageFont.truetype(SEMI, 44); f3 = ImageFont.truetype(SEMI, 40)
    for i, (t, col, ff) in enumerate([("Executive Transfers", WHITE, f2),
                                      ("Sheffield · South Yorkshire · Nationwide", (185, 178, 160, 255), f3),
                                      ("centralexecutivetransfers.co.uk", GOLD, f3)]):
        d.text(((W - ff.getlength(t)) / 2, 950 + i * 66), t, font=ff, fill=col)
    img.save(out)
