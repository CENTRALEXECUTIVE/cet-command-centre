"""CET social graphics, 'Night' theme (23 Nov 2026).
Directors: the white theme was rejected; go dark and premium like the best chauffeur
accounts. Full-bleed photo fading to black, white CET wordmark, gold letter-spaced
labels, a Cormorant Garamond capitals headline, thin gold rules, quiet footer:
"Book online" + website. No phone numbers. Plates must already be blurred via `blur`.
Usage: genn.py <spec.json>... -> html/ + manifest.json (render with shot.js)."""
import json, os, sys, html as H
from PIL import Image, ImageOps, ImageFilter
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'scripts'))
from cet_soft_blur import soft_blur  # noqa: E402

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = '/home/user/cet-command-centre'
V = REPO + '/docs/growth/data/video/'
F = REPO + '/.claude/skills/video-edit/fonts/'
SCALE = 4 / 3
SIZES = {'feed': (1080, 1350), 'story': (1080, 1920), 'gbp': (1200, 900)}
os.makedirs(HERE + '/html', exist_ok=True)
os.makedirs(HERE + '/img', exist_ok=True)

CSS = """
@font-face{font-family:B;src:url('file://%(F)sbodoni-moda-latin-500-normal.woff2');font-weight:500}
@font-face{font-family:B;src:url('file://%(F)sbodoni-moda-latin-500-italic.woff2');font-weight:500;font-style:italic}
@font-face{font-family:CG;src:url('file://%(F)scormorant-garamond-latin-600-normal.woff2');font-weight:500}
@font-face{font-family:CZ;src:url('file://%(F)scinzel-latin-500-normal.woff2');font-weight:500}
@font-face{font-family:SG;src:url('file://%(F)sschibsted-grotesk-latin-600-normal.woff2');font-weight:500}
@font-face{font-family:J;src:url('file://%(F)sjost-latin-400-normal.woff2');font-weight:400}
@font-face{font-family:J;src:url('file://%(F)sjost-latin-500-normal.woff2');font-weight:500}
@font-face{font-family:J;src:url('file://%(F)sjost-latin-600-normal.woff2');font-weight:600}
*{margin:0;padding:0;box-sizing:border-box}
html,body{width:%(W)dpx;height:%(H)dpx;background:#0d0a07;overflow:hidden}
.c{position:relative;width:%(W)dpx;height:%(H)dpx;background:#0d0a07;color:#F4EDE0;font-family:J,sans-serif;overflow:hidden;-webkit-font-smoothing:antialiased}
.photo{position:absolute;inset:0;background-size:cover;background-position:center}
.photo.soft{filter:blur(18px) brightness(.42) saturate(.8);transform:scale(1.08)}
.tone{position:absolute;inset:0;background:linear-gradient(160deg,rgba(214,160,72,.16),rgba(20,12,4,.10) 60%%);mix-blend-mode:soft-light}
.shade{position:absolute;inset:0;background:
  linear-gradient(180deg,rgba(13,10,7,.78) 0%%,rgba(13,10,7,.25) 15%%,rgba(13,10,7,0) 28%%,rgba(13,10,7,0) %(G0)d%%,rgba(13,10,7,.80) %(G1)d%%,#0d0a07 %(G2)d%%)}
.glow{position:absolute;inset:0;background:
  radial-gradient(ellipse 80%% 50%% at 50%% 46%%,rgba(212,168,72,.16),rgba(13,10,7,0) 68%%),
  radial-gradient(ellipse 120%% 70%% at 50%% 120%%,rgba(120,80,30,.22),rgba(13,10,7,0) 60%%)}
.grain{position:absolute;inset:0;opacity:.07;mix-blend-mode:overlay;background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='240' height='240'><filter id='n'><feTurbulence type='fractalNoise' baseFrequency='.9' numOctaves='2' stitchTiles='stitch'/></filter><rect width='240' height='240' filter='url(%%23n)'/></svg>")}
.frame{position:absolute;inset:%(FR)dpx;border:1px solid rgba(222,184,98,.32);pointer-events:none}
.top{position:absolute;left:0;right:0;top:%(TOP)dpx;display:flex;flex-direction:column;align-items:center;gap:20px}
.top img{height:%(LH)dpx}
.dia{display:flex;align-items:center;gap:10px}
.dia i{display:block;width:44px;height:1px;background:linear-gradient(90deg,rgba(232,200,120,0),#E2C27A)}
.dia i+b+i{background:linear-gradient(90deg,#E2C27A,rgba(232,200,120,0))}
.dia b{width:6px;height:6px;transform:rotate(45deg);background:#E2C27A}
.body{position:absolute;left:%(M)dpx;right:%(M)dpx;bottom:%(BB)dpx}
.body.centre{top:0;bottom:0;display:flex;flex-direction:column;justify-content:center;align-items:center;text-align:center}
.eye{font-weight:500;font-size:%(EY)dpx;letter-spacing:.38em;text-transform:uppercase;margin-bottom:%(EG)dpx;color:#E2C27A}
h1{font-family:B;font-weight:500;letter-spacing:-.012em;line-height:1.04;color:#F7F1E6;text-wrap:balance;font-variant-numeric:lining-nums}
h1 em{font-style:italic;color:#E2BC63}
.rule{width:86px;height:1px;background:linear-gradient(90deg,#B8862E,#F1D58C);margin:%(RG)dpx 0}
.centre .rule{margin-left:auto;margin-right:auto;background:linear-gradient(90deg,rgba(184,134,46,0),#F1D58C,rgba(184,134,46,0))}
.lead{font-size:%(LS)dpx;line-height:1.5;color:rgba(244,237,224,.80);max-width:%(LW)dpx;text-wrap:pretty;font-weight:400}
.foot{position:absolute;left:%(M)dpx;right:%(M)dpx;bottom:%(FB)dpx;display:flex;align-items:center;gap:26px}
.centre-foot{justify-content:center}
.btn{background:linear-gradient(100deg,#F3DA92,#D9AC4C 55%%,#B98A33);color:#16100a;font-weight:600;font-size:%(BTN)dpx;letter-spacing:.2em;text-transform:uppercase;padding:%(BP)dpx %(BPX)dpx;border-radius:999px;box-shadow:0 10px 30px rgba(217,172,76,.25)}
.web{font-size:%(FS)dpx;letter-spacing:.14em;color:rgba(244,237,224,.88);font-weight:500}
"""


def cover(src, W, H, cx, cy, blur, name):
    im = ImageOps.exif_transpose(Image.open(V + src)).convert('RGB')
    w, h = int(W * SCALE), int(H * SCALE)
    r = max(w / im.width, h / im.height)
    im = im.resize((int(im.width * r) + 1, int(im.height * r) + 1), Image.LANCZOS)
    x = int((im.width - w) * cx); y = int((im.height - h) * cy)
    im = im.crop((x, y, x + w, y + h))
    for b in blur or []:  # soft, feathered plate blur (directors: pixel blocks looked unprofessional)
        soft_blur(im, tuple(int(v * SCALE) for v in b))
    p = f'{HERE}/img/n_{name}.jpg'; im.save(p, quality=95); return p


def rich(text, emph):
    emph = [e.lower().strip(',.?!:') for e in emph or []]
    return ' '.join(f'<em>{H.escape(w)}</em>' if w.strip(',.?!:').lower() in emph else H.escape(w) for w in text.split(' '))


def sentence(items):
    out = []
    for x in items:
        x = x.replace(' · ', ', ').strip()
        out.append(x if x.endswith(('.', '?', '!')) else x + '.')
    return ' '.join(out)


EYEBROWS = [('v-class', 'Mercedes-Benz V-Class'), ('up to 7', 'Mercedes-Benz V-Class'), ('vat', 'Business accounts'), ('invoice', 'Business accounts'), ('christmas', 'Christmas & New Year'),
            ('flight', 'Airport transfers'), ('airport', 'Airport transfers'), ('wedding', 'Weddings'),
            ('style', 'Weddings'), ('v-class', 'Mercedes-Benz V-Class'), ('7', 'Mercedes-Benz V-Class'),
            ('waiting', 'Executive chauffeurs')]


def eyebrow(s):
    if s.get('eyebrow'):
        return s['eyebrow']
    t = (s.get('head', '') + ' ' + ' '.join(s.get('list', []))).lower()
    for k, v in EYEBROWS:
        if k in t:
            return v
    return 'Executive chauffeurs · Sheffield'


def build(s):
    kind = s.get('size_kind', 'feed'); W, Hh = SIZES[kind]; story = kind == 'story'
    name = s['out'].replace('/', '_').replace('.jpg', '')
    has_photo = s['kind'] in ('photo', 'split')
    if s['kind'] == 'photo' and not s.get('head'):
        p = cover(s['src'], W, Hh, s.get('cx', .5), s.get('cy', .5), s.get('blur'), name)
        return name, W, Hh, f'<!doctype html><meta charset="utf-8"><style>*{{margin:0}}body{{width:{W}px;height:{Hh}px;background:url(\'file://{p}\') center/cover}}</style>'

    m = 76
    fb = 230 if story else 64          # stories: keep clear of the reply bar
    v = dict(F=F, W=W, H=Hh, M=m, FR=26 if not story else 30, BTN=19 if story else 17, BP=18 if story else 15, BPX=34 if story else 28, TOP=150 if story else 70, LH=34 if story else 30,
             EY=20 if story else 18, EG=26 if story else 20, RG=30 if story else 24,
             LS=30 if story else 26, LW=820, FS=24 if story else 21, FB=fb,
             BB=fb + (120 if story else 104),
             G0=34 if story else 30, G1=58 if story else 52, G2=80 if story else 76)
    head = s.get('head', '')
    n = len(head)
    if story:
        sz = 116 if n < 22 else (104 if n < 36 else 90)
    else:
        sz = 92 if n < 22 else (82 if n < 36 else (72 if n < 52 else 62))
    if not has_photo:
        sz = int(sz * 1.08)
    body = []
    if has_photo:
        band = s.get('band')  # wide photos: keep the whole frame in the top part, text below
        if band:
            bh = int(Hh * band); b0 = int(band * 100)
            p = cover(s['src'], W, bh, s.get('cx', .5), s.get('cy', .5), s.get('blur'), name)
            body.append(f'<div class="photo" style="bottom:auto;height:{bh}px;background-image:url(\'file://{p}\')"></div><div class="tone" style="bottom:auto;height:{bh}px"></div>'
                        f'<div class="shade" style="background:linear-gradient(180deg,rgba(13,10,7,.78) 0%,rgba(13,10,7,0) 14%,rgba(13,10,7,0) {b0 - 14}%,#0d0a07 {b0}%)"></div>')
        else:
            p = cover(s['src'], W, Hh, s.get('cx', .5), s.get('cy', .5), s.get('blur'), name)
            body.append(f'<div class="photo" style="background-image:url(\'file://{p}\')"></div><div class="tone"></div><div class="shade"></div>')
    else:
        pool = ['2026-11-18-courtyard/blur1.jpg', '2026-11-18-courtyard/blur3.jpg', '2026-11-18-courtyard/blur5.jpg',
                '2026-11-18-courtyard/blur6.jpg', '2026-11-12-fleet/1000206606.jpg', '2026-11-12-fleet/1000206609.jpg']
        src = s.get('bg') or pool[sum(map(ord, s['out'])) % len(pool)]
        p = cover(src, W, Hh, .5, .5, s.get('blur'), name + '_bg')
        body.append(f'<div class="photo soft" style="background-image:url(\'file://{p}\')"></div><div class="glow"></div><div class="grain"></div>')
    body.append(f'<div class="frame"></div><div class="top"><img src="file://{F}cet-wordmark-white.png"><div class="dia"><i></i><b></b><i></i></div></div>')
    lst = [x for x in s.get('list', []) if x]
    txt = sentence(lst + s.get('body', []) + ([s['sub']] if s.get('sub') else []))
    inner = [f'<h1 style="font-size:{sz}px">{rich(head, s.get("emph"))}</h1>', '<div class="rule"></div>']
    if txt:
        inner.append(f'<p class="lead">{H.escape(txt)}</p>')
    body.append(f'<div class="body{"" if has_photo else " centre"}">{"".join(inner)}</div>')
    body.append(f'<div class="foot{"" if has_photo else " centre-foot"}"><div class="btn">Book online</div><div class="web">centralexecutivetransfers.co.uk</div></div>')
    face = s.get('face', 'archivo')
    extra = {'cormorant': 'h1{font-family:CG;text-transform:uppercase;letter-spacing:.035em}h1 em{font-style:normal}',
             'cinzel': 'h1{font-family:CZ;letter-spacing:.04em;line-height:1.12}h1 em{font-style:normal}',
             'grotesk': 'h1{font-family:SG;letter-spacing:-.03em}h1 em{font-style:normal}'}.get(face, '')
    SANS = {'geist': ('geist-sans-latin-600', -.035), 'jakarta': ('plus-jakarta-sans-latin-700', -.035),
            'manrope': ('manrope-latin-700', -.035), 'intertight': ('inter-tight-latin-600', -.03),
            'outfit': ('outfit-latin-600', -.025), 'archivo': ('archivo-latin-700', -.025)}
    if face in SANS:
        f, tr = SANS[face]
        extra = (f"@font-face{{font-family:X;src:url('file://{F}{f}-normal.woff2')}}"
                 f"h1{{font-family:X;font-weight:normal;letter-spacing:{tr}em}}h1 em{{font-style:normal}}")
    return name, W, Hh, f'<!doctype html><meta charset="utf-8"><style>{CSS % v}{extra}</style><div class="c">{"".join(body)}</div>'


manifest = []
for spec in sys.argv[1:]:
    for s in json.load(open(spec)):
        name, W, Hh, doc = build(s)
        hp = f'{HERE}/html/n_{name}.html'; open(hp, 'w').write(doc)
        manifest.append({'html': hp, 'out': s['out'], 'W': W, 'H': Hh})
json.dump(manifest, open(HERE + '/manifest.json', 'w'), indent=1)
print(len(manifest), 'cards')
