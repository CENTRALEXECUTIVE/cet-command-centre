"""CET social graphics v3: HTML templates -> crisp screenshots (shot.js).
Usage: gen.py <spec.json>... ; writes html/ + manifest.json in this folder."""
import json, os, sys, html as H
from PIL import Image, ImageOps, ImageFilter

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = '/home/user/cet-command-centre'
V = REPO + '/docs/growth/data/video/'
F = REPO + '/.claude/skills/video-edit/fonts/'
SCALE = 4 / 3  # 1080 CSS px -> 1440 px output
SIZES = {'feed': (1080, 1350), 'story': (1080, 1920), 'gbp': (1200, 900)}
TEXTURES = ['2026-11-12-fleet/1000206611.jpg', '2026-11-10-photos/1000063938.jpg',
            '2026-11-12-fleet/1000206609.jpg', '2026-11-18-courtyard/blur4.jpg',
            '2026-11-10-photos/1000063904.jpg', '2026-11-12-fleet/1000206612.jpg']
os.makedirs(HERE + '/html', exist_ok=True)
os.makedirs(HERE + '/img', exist_ok=True)

ICON = {
    'web': '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9.5" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M2.5 12h19M12 2.5c3 3.2 3 15.8 0 19M12 2.5c-3 3.2-3 15.8 0 19" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>',
    'chat': '<svg viewBox="0 0 24 24"><path d="M12 2.6a9.4 9.4 0 0 0-8.1 14.2L2.6 21.4l4.7-1.2A9.4 9.4 0 1 0 12 2.6z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M8.6 7.6c.3-.6.6-.6 1-.6.3 0 .5.1.7.6l.8 1.8c.1.3 0 .6-.2.8l-.6.7c.6 1.2 1.6 2.2 2.9 2.9l.7-.6c.2-.2.5-.3.8-.2l1.8.8c.4.2.6.4.6.7 0 .4 0 .8-.6 1.3-.5.5-1.3.8-2.1.6-3.3-.8-6-3.5-6.8-6.8-.2-.8.1-1.6.6-2z" fill="currentColor"/></svg>',
    'phone': '<svg viewBox="0 0 24 24"><path d="M6.6 3.2c.5-.4 1.2-.3 1.6.2l2 2.7c.4.5.3 1.2-.1 1.6l-1.3 1.2a12 12 0 0 0 6.3 6.3l1.2-1.3c.4-.4 1.1-.5 1.6-.1l2.7 2c.5.4.6 1.1.2 1.6l-1.3 1.7c-.6.8-1.6 1.1-2.5.8A17.5 17.5 0 0 1 4.1 7c-.3-.9 0-1.9.8-2.5z" fill="currentColor"/></svg>',
}

CSS = """
@font-face{font-family:Head;src:url('file://%(F)sPoppins-ExtraBold.ttf');font-weight:800}
@font-face{font-family:Body;src:url('file://%(F)sTikTokSans-600.ttf');font-weight:600}
@font-face{font-family:Body;src:url('file://%(F)sTikTokSans-800.ttf');font-weight:800}
*{margin:0;padding:0;box-sizing:border-box}
html,body{width:%(W)dpx;height:%(H)dpx;background:#0b0b0d;overflow:hidden}
.c{position:relative;width:%(W)dpx;height:%(H)dpx;color:#fff;font-family:Body,sans-serif;font-weight:600;overflow:hidden}
.bgimg{position:absolute;inset:0;background-size:cover;background-position:center}
.tex{position:absolute;inset:0;background-size:cover;background-position:center;opacity:.20;filter:grayscale(.35) blur(1.5px)}
.shade{position:absolute;inset:0;background:linear-gradient(180deg,rgba(11,11,13,.86) 0%%,rgba(11,11,13,.94) 45%%,rgba(11,11,13,.97) 100%%)}
.glow{position:absolute;width:%(G)dpx;height:%(G)dpx;right:-%(G4)dpx;top:-%(G4)dpx;border-radius:50%%;background:radial-gradient(circle,rgba(251,186,42,.24) 0%%,rgba(251,186,42,.07) 40%%,rgba(251,186,42,0) 70%%)}
.photoShade{position:absolute;inset:0;background:linear-gradient(180deg,rgba(6,6,8,.80) 0%%,rgba(6,6,8,.35) 14%%,rgba(6,6,8,0) 26%%,rgba(6,6,8,0) %(FADE)d%%,rgba(6,6,8,.78) %(FADE2)d%%,rgba(6,6,8,.96) 100%%)}
.brand{z-index:5;position:absolute;left:%(M)dpx;top:%(TOP)dpx;display:flex;flex-direction:column;gap:%(BG)dpx}
.brand img{height:%(LH)dpx;width:auto;display:block}
.brand span{font-weight:800;font-size:%(BS)dpx;letter-spacing:.52em;color:#FBBA2A;padding-left:3px}
.corner{z-index:5;position:absolute;right:%(M)dpx;top:%(TOP)dpx;font-size:%(BS)dpx;color:#cfcfcf;letter-spacing:.08em;text-transform:uppercase;font-weight:800;border:1.5px solid rgba(255,255,255,.35);padding:8px 14px;border-radius:999px}
.mid{position:absolute;left:%(M)dpx;right:%(M)dpx;top:%(MT)dpx;bottom:%(MB)dpx;display:flex;flex-direction:column;justify-content:center}
.low{z-index:5;position:absolute;left:%(M)dpx;right:%(M)dpx;bottom:%(MB)dpx}
.eye{display:flex;align-items:center;gap:14px;font-weight:800;font-size:%(EY)dpx;letter-spacing:.16em;text-transform:uppercase;color:#FBBA2A;margin-bottom:%(EYM)dpx}
.eye:before{content:'';width:34px;height:3px;background:#FBBA2A;border-radius:2px}
.num{font-family:Head;font-weight:800;color:#FBBA2A;font-size:%(NUM)dpx;line-height:1;margin-bottom:18px;letter-spacing:-.02em}
h1{font-family:Head;font-weight:800;line-height:1.05;letter-spacing:-.015em;text-wrap:balance;text-shadow:0 2px 18px rgba(0,0,0,.35)}
h1 em{font-style:normal;color:#FBBA2A}
.rule{width:110px;height:6px;border-radius:3px;background:#FBBA2A;margin:%(RM)dpx 0}
ul{list-style:none;display:flex;flex-direction:column;gap:%(LG)dpx}
li{display:flex;gap:18px;align-items:flex-start;font-size:%(LS)dpx;line-height:1.28;color:#ececec}
li:before{content:'';flex:none;width:%(DOT)dpx;height:%(DOT)dpx;border-radius:50%%;background:#FBBA2A;margin-top:%(DOTM)dpx}
ol{list-style:none;display:flex;flex-direction:column;gap:%(LG)dpx;counter-reset:n}
ol li:before{counter-increment:n;content:counter(n);width:auto;height:auto;background:none;border-radius:0;font-family:Head;color:#FBBA2A;font-size:1.15em;line-height:1.1;margin-top:0;min-width:1.2em}
p.body{font-size:%(LS)dpx;line-height:1.35;color:#dedede;margin-top:6px}
.split{position:absolute;left:0;right:0;top:0;height:%(SH)dpx;background-size:cover;background-position:center}
.split:before{content:'';position:absolute;inset:0 0 auto 0;height:26%%;background:linear-gradient(180deg,rgba(6,6,8,.7),rgba(6,6,8,0));z-index:1}
.split:after{content:'';position:absolute;inset:auto 0 0 0;height:40%%;background:linear-gradient(180deg,rgba(11,11,13,0),#0b0b0d)}
.sub{font-size:%(SUB)dpx;color:#dcdcdc;margin-top:14px;line-height:1.3}
.foot{z-index:5;position:absolute;left:0;right:0;bottom:%(FB)dpx;padding:0 %(M)dpx}
.bar{display:flex;align-items:center;justify-content:space-between;gap:20px;border-top:1.5px solid rgba(251,186,42,.55);padding-top:%(FP)dpx}
.it{display:flex;align-items:center;gap:10px;font-size:%(FS)dpx;color:#e6e6e6;white-space:nowrap;font-weight:600}
.it svg{width:%(IC)dpx;height:%(IC)dpx;color:#FBBA2A;flex:none}
.it.web{font-weight:800;color:#0b0b0d;background:#FBBA2A;padding:11px 20px 11px 16px;border-radius:999px}
.it.web svg{color:#0b0b0d}
.nums{display:flex;gap:26px}
.foot.stack .bar{flex-direction:column;align-items:center;gap:14px}
.foot.stack .it.web{font-size:%(FSW)dpx}
"""


def cover(src, W, H, cx=.5, cy=.5, blur=None, name='x'):
    im = ImageOps.exif_transpose(Image.open(V + src)).convert('RGB')
    w, h = int(W * SCALE), int(H * SCALE)
    r = max(w / im.width, h / im.height)
    im = im.resize((int(im.width * r) + 1, int(im.height * r) + 1), Image.LANCZOS)
    x = int((im.width - w) * cx); y = int((im.height - h) * cy)
    im = im.crop((x, y, x + w, y + h))
    for b in blur or []:  # boxes are in W,H (CSS) coordinates
        b = tuple(int(v * SCALE) for v in b); reg = im.crop(b)
        s = reg.resize((max(1, reg.width // 14), max(1, reg.height // 14))).resize(reg.size, Image.NEAREST).filter(ImageFilter.GaussianBlur(8))
        im.paste(s, b)
    p = f'{HERE}/img/{name}.jpg'; im.save(p, quality=95); return p


def rich(text, emph):
    emph = [e.lower().strip(',.?!:') for e in emph]
    out = []
    for w in text.split(' '):
        clean = w.strip(',.?!:').lower()
        out.append(f'<em>{H.escape(w)}</em>' if clean in emph else H.escape(w))
    return ' '.join(out)


def head_size(s, W, Hh, story):
    if s.get('size'):
        return int(s['size'])
    n = len(s['head'])
    if Hh < W:
        return 74 if n < 34 else 62
    if story:
        return 112 if n < 26 else (98 if n < 42 else 84)
    return 106 if n < 22 else (92 if n < 38 else (80 if n < 56 else 70))


def footer(story, gbp):
    web = f'<div class="it web">{ICON["web"]}centralexecutivetransfers.co.uk</div>'
    nums = f'<div class="nums"><div class="it">{ICON["chat"]}07405 172435</div><div class="it">{ICON["phone"]}0114 399 8242</div></div>'
    cls = 'foot stack' if story else 'foot'
    return f'<div class="{cls}"><div class="bar">{web}{nums}</div></div>'


def build(s, idx):
    kind = s.get('size_kind', 'feed'); W, Hh = SIZES[kind]; story = kind == 'story'; gbp = kind == 'gbp'
    name = s['out'].replace('/', '_').replace('.jpg', '')
    m = 70 if not gbp else 64
    top = 230 if story else 62
    fb = 250 if story else 52
    v = dict(F=F, W=W, H=Hh, M=m, TOP=top, FB=fb, G=int(W * 1.1), G4=int(W * .35),
             LH=48 if not gbp else 40, BS=17, BG=13,
             EY=24, EYM=22, NUM=200 if story else 170, RM=30, LG=18,
             LS=40 if story else 34, DOT=13, DOTM=15 if story else 13,
             SUB=38, SH=int(Hh * .62), FS=25 if not gbp else 22, FSW=30, IC=27, FP=22,
             FADE=int(s.get('fade', .52) * 100) - 10, FADE2=int(s.get('fade', .52) * 100) + 18,
             MT=top + 150, MB=(fb + 160) if story else (fb + 110))
    css = CSS % v
    body = []
    has_head = bool(s.get('head'))
    if s['kind'] == 'photo':
        p = cover(s['src'], W, Hh, s.get('cx', .5), s.get('cy', .5), s.get('blur'), name)
        body.append(f'<div class="bgimg" style="background-image:url(\'file://{p}\')"></div>')
        if not has_head:  # clean photo (e.g. Google Business): no overlays
            return name, W, Hh, f'<style>{css}</style><div class="c">{"".join(body)}</div>'
        body.append('<div class="photoShade"></div>')
    elif s['kind'] == 'split':
        p = cover(s['src'], W, int(Hh * .62), s.get('cx', .5), s.get('cy', .5), s.get('blur'), name)
        body.append(f'<div class="split" style="background-image:url(\'file://{p}\')"></div>')
    else:
        t = TEXTURES[idx % len(TEXTURES)]
        p = cover(t, W, Hh, .5, .55, None, 'tex_' + name)
        body.append(f'<div class="tex" style="background-image:url(\'file://{p}\')"></div><div class="shade"></div><div class="glow"></div>')

    logo = F + 'cet-wordmark-white.png'
    body.append(f'<div class="brand"><img src="file://{logo}"><span>EXECUTIVE TRANSFERS</span></div>')
    if s.get('corner'):
        body.append(f'<div class="corner">{H.escape(s["corner"])}</div>')

    inner = []
    if s.get('num'):
        inner.append(f'<div class="num">{H.escape(str(s["num"]))}</div>')
    if s.get('eyebrow'):
        inner.append(f'<div class="eye">{H.escape(s["eyebrow"])}</div>')
    if has_head:
        sz = head_size(s, W, Hh, story)
        if s['kind'] == 'photo':
            sz = int(s.get('size', 0)) or (96 if story else 86)
        if s['kind'] == 'split':
            sz = 86
        inner.append(f'<h1 style="font-size:{sz}px">{rich(s["head"], s.get("emph", []))}</h1>')
    if s['kind'] == 'split':
        inner.insert(0, '<div class="rule" style="margin-top:0"></div>')
        if s.get('sub'):
            inner.append(f'<div class="sub">{H.escape(s["sub"])}</div>')
    elif s['kind'] == 'text':
        inner.append('<div class="rule"></div>')
    else:
        inner.append('<div style="height:22px"></div>')
    if s.get('list'):
        tag = 'ol' if s.get('numbered') else 'ul'
        inner.append(f'<{tag}>' + ''.join(f'<li>{H.escape(x)}</li>' for x in s['list']) + f'</{tag}>')
    for b in s.get('body', []):
        inner.append(f'<p class="body">{H.escape(b)}</p>')

    if s['kind'] == 'text':
        body.append(f'<div class="mid">{"".join(inner)}</div>')
    elif s['kind'] == 'split':
        body.append(f'<div class="low" style="top:{int(Hh * .62) + 10}px;bottom:auto">{"".join(inner)}</div>')
    else:
        body.append(f'<div class="low" style="bottom:{(fb + 96) if not story else (fb + 165)}px">{"".join(inner)}</div>')
    body.append(footer(story, gbp))
    return name, W, Hh, f'<!doctype html><meta charset="utf-8"><style>{css}</style><div class="c">{"".join(body)}</div>'


manifest = []
i = 0
for spec in sys.argv[1:]:
    for s in json.load(open(spec)):
        name, W, Hh, doc = build(s, i); i += 1
        hp = f'{HERE}/html/{name}.html'
        open(hp, 'w').write(doc)
        manifest.append({'html': hp, 'out': s['out'], 'W': W, 'H': Hh})
json.dump(manifest, open(HERE + '/manifest.json', 'w'), indent=1)
print(len(manifest), 'cards')
