"""CET social graphics, WHITE premium theme (directors' choice 22 Nov 2026: "B, more white").
White canvas, black CENTRAL wordmark + gold tagline, black headline with deep-gold emphasis,
centred editorial layout, black contact strip. Photos sit in the top of the card.
Usage: genw.py <spec.json>...  -> html/ + manifest.json (render with shot.js)."""
import json, os, sys, html as H
from PIL import Image, ImageOps, ImageFilter

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = '/home/user/cet-command-centre'
V = REPO + '/docs/growth/data/video/'
F = REPO + '/.claude/skills/video-edit/fonts/'
SCALE = 4 / 3
SIZES = {'feed': (1080, 1350), 'story': (1080, 1920), 'gbp': (1200, 900)}
os.makedirs(HERE + '/html', exist_ok=True)
os.makedirs(HERE + '/img', exist_ok=True)

GOLD = '#A87B0F'      # deep gold: readable on white
GOLD_L = '#F2C14E'    # bright gold: on black

CSS = """
@font-face{font-family:Head;src:url('file://%(F)sPoppins-ExtraBold.ttf')}
@font-face{font-family:Body;src:url('file://%(F)sTikTokSans-600.ttf');font-weight:600}
@font-face{font-family:Body;src:url('file://%(F)sTikTokSans-800.ttf');font-weight:800}
*{margin:0;padding:0;box-sizing:border-box}
html,body{width:%(W)dpx;height:%(H)dpx;background:#fff;overflow:hidden}
.c{position:relative;width:%(W)dpx;height:%(H)dpx;background:#fff;color:#0d0d0e;font-family:Body,sans-serif;font-weight:600;overflow:hidden}
.brand{position:absolute;left:0;right:0;top:%(BT)dpx;display:flex;flex-direction:column;align-items:center;gap:13px;z-index:5}
.brand img{height:%(LH)dpx}
.brand span{font-weight:800;font-size:%(BS)dpx;letter-spacing:.55em;color:%(GOLD)s;padding-left:.55em}
.hair{position:absolute;left:%(HM)dpx;right:%(HM)dpx;top:%(HT)dpx;height:1px;background:rgba(13,13,14,.14)}
.photo{position:absolute;left:0;right:0;top:0;height:%(PH)dpx;background-size:cover;background-position:center}
.tab{position:absolute;left:50%%;top:%(TT)dpx;transform:translateX(-50%%);background:#fff;padding:24px 50px 18px;border-radius:16px 16px 0 0;display:flex;flex-direction:column;align-items:center;gap:11px;z-index:5}
.tab img{height:%(TLH)dpx} .tab span{font-weight:800;font-size:%(TBS)dpx;letter-spacing:.55em;color:%(GOLD)s;padding-left:.55em}
.mid{position:absolute;left:%(M)dpx;right:%(M)dpx;top:%(MT)dpx;bottom:%(MB)dpx;display:flex;flex-direction:column;justify-content:center;align-items:center;text-align:center}
.eye{font-weight:800;font-size:%(EY)dpx;letter-spacing:.36em;color:%(GOLD)s;margin-bottom:26px;padding-left:.36em}
.num{font-family:Head;color:%(GOLD)s;font-size:%(NUM)dpx;line-height:1;margin-bottom:14px}
h1{font-family:Head;line-height:1.07;letter-spacing:-.012em;text-wrap:balance}
h1 em{font-style:normal;color:%(GOLD)s}
.rule{width:64px;height:2px;background:%(GOLD)s;margin:%(RM)dpx auto}
.li{font-size:%(LS)dpx;color:#3b3b3d;line-height:1.55}
.li b{color:%(GOLD)s;font-family:Head;font-weight:800;margin-right:10px}
.sub{font-size:%(LS)dpx;color:#3b3b3d;margin-top:16px}
.foot{position:absolute;left:0;right:0;bottom:0;height:%(FH)dpx;background:#0d0d0e;display:flex;align-items:center;justify-content:center;gap:24px;font-size:%(FS)dpx;color:#e8e8e8;z-index:5}
.foot.stack{flex-direction:column;gap:12px;justify-content:center}
.foot .row{display:flex;gap:24px;align-items:center}
.foot .web{color:%(GOLD_L)s;font-weight:800}
.foot.stack .web{font-size:%(FSW)dpx}
.dot{width:5px;height:5px;border-radius:50%%;background:#D9AE48}
"""


def cover_window(src, W, H, cx, cy, blur, name, wy, PH, full_h=None):
    """Cover-crop to the FULL card (so blur boxes keep their old coordinates), blur
    plates, then cut the window of height PH centred at wy*H for the photo panel."""
    im = ImageOps.exif_transpose(Image.open(V + src)).convert('RGB')
    if full_h:
        H = full_h  # split cards: blur boxes were measured on a W x 0.66H photo
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
    if ph > h:  # window taller than the source crop: scale up to fit
        im = im.resize((int(w * ph / h), ph), Image.LANCZOS); x0 = (im.width - w) // 2; im = im.crop((x0, 0, x0 + w, ph))
    else:
        y0 = int(min(max(0, wy * h - ph / 2), h - ph))
        im = im.crop((0, y0, w, y0 + ph))
    p = f'{HERE}/img/w_{name}.jpg'; im.save(p, quality=95); return p


def rich(text, emph):
    emph = [e.lower().strip(',.?!:') for e in emph]
    return ' '.join(f'<em>{H.escape(w)}</em>' if w.strip(',.?!:').lower() in emph else H.escape(w) for w in text.split(' '))


def footer(story):
    web = '<div class="web">centralexecutivetransfers.co.uk</div>'
    nums = '<div>WhatsApp 07405 172435</div><div class="dot"></div><div>0114 399 8242</div>'
    if story:
        return f'<div class="foot stack">{web}<div class="row">{nums}</div></div>'
    return f'<div class="foot">{web}<div class="dot"></div>{nums}</div>'


def build(s):
    kind = s.get('size_kind', 'feed'); W, Hh = SIZES[kind]; story = kind == 'story'; gbp = kind == 'gbp'
    name = s['out'].replace('/', '_').replace('.jpg', '')
    has_photo = s['kind'] in ('photo', 'split')
    if s['kind'] == 'photo' and not s.get('head'):
        # Clean photo (Google Business): no overlays, full frame.
        p = cover_window(s['src'], W, Hh, s.get('cx', .5), s.get('cy', .5), s.get('blur'), name, .5, Hh)
        css = CSS % dict(F=F, W=W, H=Hh, GOLD=GOLD, GOLD_L=GOLD_L, BT=0, LH=0, BS=0, HM=0, HT=0, PH=Hh, TT=0, TLH=0, TBS=0,
                         M=0, MT=0, MB=0, EY=0, NUM=0, RM=0, LS=0, FH=0, FS=0, FSW=0)
        return name, W, Hh, f'<!doctype html><meta charset="utf-8"><style>{css}</style><div class="c"><div class="photo" style="height:{Hh}px;background-image:url(\'file://{p}\')"></div></div>'

    fh = 210 if story else (96 if gbp else 112)
    if story:
        fh = 250  # leaves the bottom safe zone clear of Story UI
    ph = int(Hh * (0.52 if story else 0.56)) if has_photo else 0
    v = dict(F=F, W=W, H=Hh, GOLD=GOLD, GOLD_L=GOLD_L,
             BT=250 if story else (52 if gbp else 72), LH=50 if not gbp else 40, BS=15,
             HM=90, HT=(250 + 130) if story else (52 + 108 if gbp else 72 + 120),
             PH=ph, TT=ph - 100, TLH=40, TBS=13,
             M=100 if not gbp else 90,
             EY=19, NUM=150 if story else 130, RM=34 if not has_photo else 26, LS=36 if story else 30,
             FH=fh, FS=24 if story else 22, FSW=30)
    if has_photo:
        v['MT'] = ph + 30
        v['MB'] = fh + (60 if story else 40)
    else:
        v['MT'] = v['HT'] + 20
        v['MB'] = fh + 30
    css = CSS % v
    body = []
    logo = F + 'cet-wordmark-black.png'
    if has_photo:
        p = cover_window(s['src'], W, Hh, s.get('cx', .5), s.get('cy', .5), s.get('blur'), name, s.get('wy', .55 if not story else .52), ph,
                         full_h=int(Hh * .66) if s['kind'] == 'split' else None)
        body.append(f'<div class="photo" style="background-image:url(\'file://{p}\')"></div>')
        body.append(f'<div class="tab"><img src="file://{logo}"><span>EXECUTIVE TRANSFERS</span></div>')
    else:
        body.append(f'<div class="brand"><img src="file://{logo}"><span>EXECUTIVE TRANSFERS</span></div><div class="hair"></div>')

    inner = []
    if s.get('num'):
        inner.append(f'<div class="num">{H.escape(str(s["num"]))}</div>')
    if s.get('eyebrow'):
        inner.append(f'<div class="eye">{H.escape(s["eyebrow"].upper())}</div>')
    if s.get('head'):
        n = len(s['head'])
        if has_photo:
            sz = 84 if story else (74 if n < 30 else 64)
        elif gbp:
            sz = 66 if n < 34 else 56
        elif story:
            sz = 104 if n < 26 else (92 if n < 42 else 80)
        else:
            sz = 98 if n < 22 else (86 if n < 38 else (76 if n < 56 else 66))
        sz = int(s.get('size', 0)) or sz
        if has_photo and s.get('size'):
            sz = min(sz, 84)
        inner.append(f'<h1 style="font-size:{sz}px">{rich(s["head"], s.get("emph", []))}</h1>')
    lst = s.get('list', [])
    if s['kind'] == 'split' and s.get('sub'):
        inner.append('<div class="rule"></div>')
        inner.append(f'<div class="sub">{H.escape(s["sub"])}</div>')
    elif lst or s.get('body'):
        inner.append('<div class="rule"></div>')
    elif not has_photo:
        inner.append('<div class="rule" style="margin-bottom:0"></div>')
    if lst:
        if s.get('numbered'):
            inner.append('<div class="li">' + '<br>'.join(f'<b>{i + 1}</b>{H.escape(x)}' for i, x in enumerate(lst)) + '</div>')
        else:
            inner.append('<div class="li">' + '<br>'.join(H.escape(x) for x in lst) + '</div>')
    for b in s.get('body', []):
        inner.append(f'<div class="li" style="margin-top:8px">{H.escape(b)}</div>')
    body.append(f'<div class="mid">{"".join(inner)}</div>')
    body.append(footer(story))
    return name, W, Hh, f'<!doctype html><meta charset="utf-8"><style>{css}</style><div class="c">{"".join(body)}</div>'


manifest = []
for spec in sys.argv[1:]:
    for s in json.load(open(spec)):
        name, W, Hh, doc = build(s)
        hp = f'{HERE}/html/w_{name}.html'
        open(hp, 'w').write(doc)
        manifest.append({'html': hp, 'out': s['out'], 'W': W, 'H': Hh})
json.dump(manifest, open(HERE + '/manifest.json', 'w'), indent=1)
print(len(manifest), 'cards')
