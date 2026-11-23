"""CET social graphics, 'Concierge' theme (23 Nov 2026).
Directors: white, premium, must NOT look AI. Photo-led, one typeface (Schibsted Grotesk,
regular + medium), sentence case, left aligned, no coloured words, no caps labels, no
middle-dot strings. True black on white. Brand gold appears once: a small square beside
"Book online". Every card ends on the same quiet line: logo + booking address.
Usage: genx.py <spec.json>... -> html/ + manifest.json (render with shot.js)."""
import json, os, re, sys, html as H
from collections import Counter
from PIL import Image, ImageOps, ImageFilter

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = '/home/user/cet-command-centre'
V = REPO + '/docs/growth/data/video/'
F = REPO + '/.claude/skills/video-edit/fonts/'
SCALE = 4 / 3
SIZES = {'feed': (1080, 1350), 'story': (1080, 1920), 'gbp': (1200, 900)}
os.makedirs(HERE + '/html', exist_ok=True)
os.makedirs(HERE + '/img', exist_ok=True)

CSS = """
@font-face{font-family:G;src:url('file://%(F)sschibsted-grotesk-latin-400-normal.woff2');font-weight:400}
@font-face{font-family:G;src:url('file://%(F)sschibsted-grotesk-latin-500-normal.woff2');font-weight:500}
@font-face{font-family:G;src:url('file://%(F)sschibsted-grotesk-latin-600-normal.woff2');font-weight:600}
*{margin:0;padding:0;box-sizing:border-box}
html,body{width:%(W)dpx;height:%(H)dpx;background:#fff;overflow:hidden}
.c{position:relative;width:%(W)dpx;height:%(H)dpx;background:#fff;color:#000;font-family:G,sans-serif;font-weight:400;overflow:hidden;-webkit-font-smoothing:antialiased}
.photo{position:absolute;left:0;right:0;top:0;height:%(PH)dpx;background-size:cover;background-position:center}
.logo{height:%(LH)dpx;display:block}
.top{position:absolute;left:%(M)dpx;right:%(M)dpx;top:%(M)dpx;display:flex;justify-content:space-between;align-items:center}
.page{font-size:%(PG)dpx;color:#6b6b6b;font-weight:500;font-variant-numeric:tabular-nums}
.band{position:absolute;left:%(M)dpx;right:%(M)dpx;top:%(BT)dpx}
.mid{position:absolute;left:%(M)dpx;right:%(M2)dpx;top:%(MT)dpx;bottom:%(MB)dpx;display:flex;flex-direction:column;justify-content:center}
h1{font-weight:500;letter-spacing:-.028em;line-height:1.04;text-wrap:balance;color:#000}
.lead{font-size:%(LS)dpx;line-height:1.38;color:#4a4a4a;margin-top:%(LG)dpx;max-width:%(LW)dpx;text-wrap:pretty}
.rows{margin-top:%(RG)dpx;border-bottom:1px solid #dcdcdc;max-width:%(LW)dpx}
.rows div{font-size:%(LS)dpx;color:#2a2a2a;padding:%(RP)dpx 0;border-top:1px solid #dcdcdc;display:flex;gap:22px}
.rows div b{font-weight:500;color:#9a9a9a;min-width:1.2em;font-variant-numeric:tabular-nums}
.foot{position:absolute;left:%(M)dpx;right:%(M)dpx;bottom:%(FB)dpx;display:flex;justify-content:space-between;align-items:center}
.book{display:flex;align-items:center;gap:14px;font-size:%(BS)dpx;color:#000;font-weight:500}
.book i{width:11px;height:11px;background:#FBBA2A;display:block}
.book span{color:#6b6b6b;font-weight:400}
"""


def cover_window(src, W, H, cx, cy, blur, name, wy, PH, full_h=None):
    im = ImageOps.exif_transpose(Image.open(V + src)).convert('RGB')
    if full_h:
        H = full_h
    w, h = int(W * SCALE), int(H * SCALE)
    r = max(w / im.width, h / im.height)
    im = im.resize((int(im.width * r) + 1, int(im.height * r) + 1), Image.LANCZOS)
    x = int((im.width - w) * cx); y = int((im.height - h) * cy)
    im = im.crop((x, y, x + w, y + h))
    for b in blur or []:
        b = tuple(int(v * SCALE) for v in b); reg = im.crop(b)
        s = reg.resize((max(1, reg.width // 14), max(1, reg.height // 14))).resize(reg.size, Image.NEAREST).filter(ImageFilter.GaussianBlur(8))
        im.paste(s, b)
    ph = int(PH * SCALE)
    if ph > h:
        im = im.resize((int(w * ph / h), ph), Image.LANCZOS); x0 = (im.width - w) // 2; im = im.crop((x0, 0, x0 + w, ph))
    else:
        y0 = int(min(max(0, wy * h - ph / 2), h - ph)); im = im.crop((0, y0, w, y0 + ph))
    p = f'{HERE}/img/x_{name}.jpg'; im.save(p, quality=95); return p


def clean(t):
    t = re.sub(r'^\d+\.\s*', '', t)          # "1. We track" -> page number does that job
    return t.replace(' · ', ', ')


def sentence(items):
    out = []
    for x in items:
        x = clean(x).strip()
        out.append(x if x.endswith(('.', '?', '!')) else x + '.')
    return ' '.join(out)


def foot(W):
    logo = F + 'cet-wordmark-black.png'
    return (f'<div class="foot"><img class="logo" src="file://{logo}">'
            f'<div class="book"><i></i>Book online <span>centralexecutivetransfers.co.uk</span></div></div>')


def build(s, pages):
    kind = s.get('size_kind', 'feed'); W, Hh = SIZES[kind]; story = kind == 'story'; gbp = kind == 'gbp'
    name = s['out'].replace('/', '_').replace('.jpg', '')
    has_photo = s['kind'] in ('photo', 'split')
    if s['kind'] == 'photo' and not s.get('head'):
        p = cover_window(s['src'], W, Hh, s.get('cx', .5), s.get('cy', .5), s.get('blur'), name, .5, Hh)
        return name, W, Hh, f'<!doctype html><meta charset="utf-8"><style>*{{margin:0}}body{{width:{W}px;height:{Hh}px;background:url(\'file://{p}\') center/cover}}</style>'

    m = 64 if not gbp else 60
    ph = int(Hh * (0.68 if story else 0.70)) if has_photo else 0
    v = dict(F=F, W=W, H=Hh, M=m, M2=m + (60 if not has_photo else 0), PH=ph, LH=32 if not story else 38,
             PG=22, LS=32 if story else 27, LG=22, LW=860 if not gbp else 900, RG=34, RP=16,
             BS=21 if not story else 24, FB=(260 if story else (m - 6)),
             BT=ph + (54 if story else 44), MT=170 if not story else 360, MB=150 if not story else 420)
    if gbp:
        v.update(MT=110, MB=110, LS=24)
    css = CSS % v
    body = []
    if has_photo:
        p = cover_window(s['src'], W, Hh, s.get('cx', .5), s.get('cy', .5), s.get('blur'), name,
                         s.get('wy', .5), ph, full_h=int(Hh * .66) if s['kind'] == 'split' else None)
        body.append(f'<div class="photo" style="background-image:url(\'file://{p}\')"></div>')

    head = clean(s.get('head', ''))
    n = len(head)
    if has_photo:
        sz = (74 if n < 30 else 62) if story else (54 if n < 34 else 46)
    elif gbp:
        sz = 64 if n < 34 else 54
    elif story:
        sz = 104 if n < 24 else (92 if n < 40 else 80)
    else:
        sz = 106 if n < 22 else (92 if n < 36 else (80 if n < 52 else 70))

    lst = [x for x in s.get('list', []) if x]
    extra = s.get('body', []) + ([s['sub']] if s.get('sub') else [])
    inner = [f'<h1 style="font-size:{sz}px">{H.escape(head)}</h1>']
    if has_photo:
        txt = sentence(lst + extra)
        if txt:
            inner.append(f'<p class="lead">{H.escape(txt)}</p>')
        body.append(f'<div class="band">{"".join(inner)}</div>')
    else:
        page = pages.get(s['out'])
        logo = F + 'cet-wordmark-black.png'
        body.append(f'<div class="top"><img class="logo" src="file://{logo}">'
                    + (f'<div class="page">{page}</div>' if page else '') + '</div>')
        if lst and len(lst) >= 3:
            num = s.get('numbered')
            inner.append('<div class="rows">' + ''.join(
                f'<div>{f"<b>{i + 1}</b>" if num else ""}{H.escape(clean(x))}</div>' for i, x in enumerate(lst)) + '</div>')
            if extra:
                inner.append(f'<p class="lead">{H.escape(sentence(extra))}</p>')
        else:
            txt = sentence(lst + extra)
            if txt:
                inner.append(f'<p class="lead">{H.escape(txt)}</p>')
        body.append(f'<div class="mid">{"".join(inner)}</div>')
    body.append(foot(W) if has_photo or True else '')
    if not has_photo:
        # text cards already carry the logo at the top: footer shows only the booking line
        body[-1] = (f'<div class="foot" style="justify-content:flex-start;border-top:1px solid #dcdcdc;padding-top:24px">'
                    f'<div class="book"><i></i>Book online <span>centralexecutivetransfers.co.uk</span></div></div>')
    return name, W, Hh, f'<!doctype html><meta charset="utf-8"><style>{css}</style><div class="c">{"".join(body)}</div>'


specs = []
for spec in sys.argv[1:]:
    specs += json.load(open(spec))
# Page indicator for multi-slide text carousels: "2/7" on every slide of the folder.
folders = Counter(os.path.dirname(s['out']) for s in specs)
pages = {}
for s in specs:
    d = os.path.dirname(s['out']); base = os.path.basename(s['out'])
    if folders[d] > 1 and base[0].isdigit() and not d.startswith('stories'):
        pages[s['out']] = f'{base.split(".")[0]}/{folders[d]}'
manifest = []
for s in specs:
    name, W, Hh, doc = build(s, pages)
    hp = f'{HERE}/html/x_{name}.html'; open(hp, 'w').write(doc)
    manifest.append({'html': hp, 'out': s['out'], 'W': W, 'H': Hh})
json.dump(manifest, open(HERE + '/manifest.json', 'w'), indent=1)
print(len(manifest), 'cards')
