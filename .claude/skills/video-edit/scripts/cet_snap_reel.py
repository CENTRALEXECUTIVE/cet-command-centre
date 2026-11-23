#!/usr/bin/env python3
"""CET 'snap' Reel (23 Nov 2026): fast-cut edit in the style the directors picked from TikTok.
- Ivory title cards with a black Bodoni headline (gold italic emphasis) between clips.
- Full-frame b-roll, each clip with a slow push-in.
- Captions ONE WORD AT A TIME in a small rounded chip just below centre
  (gold chip; emphasis words in a black chip with gold text).
- Ends on the headlights end card (cet_swap_end.py). Silent stereo track: add the sound in the app.
Usage: cet_snap_reel.py <edl.json> <out.mp4>
EDL: {"items":[{"card":"Headline","emph":["word"],"dur":1.3} |
               {"src":"path.mp4","in_s":1.4,"out_s":4.2,"caption":"Before every job","emph":["every"]}]}
Paths in the EDL are relative to the EDL file."""
import json, os, subprocess, sys, tempfile, html as H
from pathlib import Path

HERE = Path(__file__).resolve().parent
F = HERE.parent / 'fonts'
W, Hh = 1080, 1920
sys.path.insert(0, str(HERE))
from cet_encode import INTER_VIDEO  # noqa: E402

FONTS = f"""
@font-face{{font-family:B;src:url('file://{F}/bodoni-moda-latin-500-normal.woff2');font-weight:500}}
@font-face{{font-family:B;src:url('file://{F}/bodoni-moda-latin-500-italic.woff2');font-weight:500;font-style:italic}}
@font-face{{font-family:J;src:url('file://{F}/jost-latin-600-normal.woff2');font-weight:600}}
@font-face{{font-family:J;src:url('file://{F}/jost-latin-500-normal.woff2');font-weight:500}}
*{{margin:0;padding:0;box-sizing:border-box}}html,body{{width:{W}px;height:{Hh}px;background:transparent;overflow:hidden}}
"""


def rich(text, emph):
    emph = [e.lower().strip(',.?!:') for e in emph or []]
    return ' '.join(f'<em>{H.escape(w)}</em>' if w.strip(',.?!:').lower() in emph else H.escape(w) for w in text.split(' '))


def card_html(text, emph):
    n = len(text); sz = 118 if n < 18 else (100 if n < 34 else 86)
    return f"""<!doctype html><meta charset="utf-8"><style>{FONTS}
body{{background:#EDE6D9}}
.c{{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:0 110px;text-align:center}}
h1{{font-family:B;font-weight:500;font-size:{sz}px;line-height:1.05;letter-spacing:-.015em;color:#14100b;text-wrap:balance;font-variant-numeric:lining-nums}}
h1 em{{font-style:italic;color:#A97A22}}
.d{{display:flex;align-items:center;gap:12px;margin-top:54px}}.d i{{width:48px;height:1px;background:#A97A22}}.d b{{width:8px;height:8px;transform:rotate(45deg);background:#A97A22}}
.w{{position:absolute;left:0;right:0;bottom:150px;text-align:center;font-family:J;font-weight:500;font-size:22px;letter-spacing:.42em;color:#6d5f4b}}
</style><div class="c"><h1>{rich(text, emph)}</h1><div class="d"><i></i><b></b><i></i></div></div><div class="w">CENTRAL EXECUTIVE TRANSFERS</div>"""


def chip_html(word, hot):
    bg, fg = ('#14100b', '#E7C46F') if hot else ('linear-gradient(100deg,#F3DA92,#D9AC4C 60%,#C0913A)', '#14100b')
    return f"""<!doctype html><meta charset="utf-8"><style>{FONTS}
.k{{position:absolute;left:50%;top:1150px;transform:translateX(-50%);background:{bg};color:{fg};font-family:J;font-weight:600;
font-size:62px;line-height:1;padding:18px 30px 22px;border-radius:14px;white-space:nowrap;box-shadow:0 12px 34px rgba(0,0,0,.35)}}
</style><div class="k">{H.escape(word)}</div>"""


def render_pngs(jobs, tmp):
    js = tmp / 'shot.js'
    js.write_text("""const {chromium}=require('playwright');const jobs=require(process.argv[2]);
(async()=>{const b=await chromium.launch();const p=await b.newPage({viewport:{width:1080,height:1920}});
for(const j of jobs){await p.goto('file://'+j.file);await p.evaluate(()=>document.fonts.ready);await p.waitForTimeout(60);
await p.screenshot({path:j.out,omitBackground:true});}await b.close();})();""")
    for k, j in enumerate(jobs):
        j['file'] = str(tmp / f'job{k}.html'); Path(j['file']).write_text(j['html'])
    (tmp / 'jobs.json').write_text(json.dumps(jobs))
    env = dict(os.environ, NODE_PATH=subprocess.run(['npm', 'root', '-g'], capture_output=True, text=True).stdout.strip())
    subprocess.run(['node', str(js), str(tmp / 'jobs.json')], check=True, env=env)


def ff(args):
    r = subprocess.run(['ffmpeg', '-v', 'error', '-y'] + args, capture_output=True, text=True)
    if r.returncode:
        sys.exit(r.stderr[-2000:])


def main(edl_path, out):
    edl = json.load(open(edl_path)); base = Path(edl_path).resolve().parent
    tmp = Path(tempfile.mkdtemp(prefix='snap_'))
    jobs, plan = [], []
    for i, it in enumerate(edl['items']):
        if 'card' in it:
            p = str(tmp / f'card{i}.png'); jobs.append({'html': card_html(it['card'], it.get('emph')), 'out': p})
            plan.append(('card', it, p, []))
        else:
            words = it.get('caption', '').split()
            emph = [e.lower().strip(',.?!:') for e in it.get('emph', [])]
            chips = []
            for k, w in enumerate(words):
                p = str(tmp / f'chip{i}_{k}.png'); jobs.append({'html': chip_html(w, w.lower().strip(',.?!:') in emph), 'out': p})
                chips.append(p)
            plan.append(('clip', it, None, chips))
    render_pngs(jobs, tmp)
    segs = []
    for i, (kind, it, png, chips) in enumerate(plan):
        seg = str(tmp / f'seg{i:02d}.mp4')
        if kind == 'card':
            d = it.get('dur', 1.2); fr = int(d * 30)
            ff(['-loop', '1', '-i', png, '-vf', f"scale=2160:3840,zoompan=z='1+0.0006*on':x='iw/2-iw/zoom/2':y='ih/2-ih/zoom/2':d={fr}:s={W}x{Hh}:fps=30,format=yuv420p",
                '-frames:v', str(fr), '-an'] + INTER_VIDEO + [seg])
        else:
            d = it['out_s'] - it['in_s']
            vf = (f"[0:v]trim={it['in_s']}:{it['out_s']},setpts=PTS-STARTPTS,fps=30,scale={W}:{Hh}:force_original_aspect_ratio=increase,crop={W}:{Hh},"
                  f"scale=w='trunc({W}*(1+0.05*t/{d})/2)*2':h='trunc({Hh}*(1+0.05*t/{d})/2)*2':eval=frame,crop={W}:{Hh},"
                  f"eq=contrast=1.05:saturation=1.04,format=yuv420p[v0]")
            n = max(1, len(chips)); step = d / n; last = '[v0]'
            for k, c in enumerate(chips):
                a, b = k * step + 0.05, (k + 1) * step if k < n - 1 else d
                vf += f";{last}[{k + 1}:v]overlay=0:0:enable='between(t,{a:.3f},{b:.3f})'[v{k + 1}]"; last = f'[v{k + 1}]'
            args = ['-i', str(base / it['src'])]
            for c in chips:
                args += ['-i', c]
            ff(args + ['-filter_complex', vf, '-map', last, '-t', f'{d:.3f}', '-an'] + INTER_VIDEO + [seg])
        segs.append(seg)
    lst = tmp / 'list.txt'; lst.write_text(''.join(f"file '{s}'\n" for s in segs))
    body = str(tmp / 'body.mp4')
    ff(['-f', 'concat', '-safe', '0', '-i', str(lst), '-f', 'lavfi', '-i', 'anullsrc=r=48000:cl=stereo', '-shortest',
        '-map', '0:v', '-map', '1:a'] + INTER_VIDEO + ['-c:a', 'aac', '-b:a', '192k', body])
    subprocess.run([sys.executable, str(HERE / 'cet_swap_end.py'), body, out, 'none'], check=True)


if __name__ == '__main__':
    main(sys.argv[1], sys.argv[2])
