// Small hand-drawn SVG charts. Colours come from CSS tokens so both themes read.
export const PALETTE = ['var(--c1)', 'var(--c2)', 'var(--c3)', 'var(--c4)', 'var(--c5)', 'var(--c6)', 'var(--c7)', 'var(--c8)'];
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

// Donut: items [{name,total}], shows the top 7 + "Everything else".
export function donut(items, { size = 200, label = '', sub = '' } = {}) {
  const total = items.reduce((t, i) => t + i.total, 0);
  if (!total) return `<div class="empty-chart">Nothing in this period yet</div>`;
  const r = 80, cx = 100, cy = 100, w = 26;
  let a0 = -Math.PI / 2;
  const segs = items.map((it, i) => {
    const frac = it.total / total;
    const a1 = a0 + frac * Math.PI * 2;
    const large = a1 - a0 > Math.PI ? 1 : 0;
    const p = (a) => `${(cx + r * Math.cos(a)).toFixed(2)} ${(cy + r * Math.sin(a)).toFixed(2)}`;
    const d = frac >= 0.9999 ? `M ${cx} ${cy - r} A ${r} ${r} 0 1 1 ${cx - 0.01} ${cy - r}` : `M ${p(a0)} A ${r} ${r} 0 ${large} 1 ${p(a1)}`;
    a0 = a1;
    return `<path d="${d}" fill="none" stroke="${PALETTE[i % PALETTE.length]}" stroke-width="${w}"><title>${esc(it.name)}: ${(frac * 100).toFixed(1)}%</title></path>`;
  });
  return `<svg class="donut" viewBox="0 0 200 200" width="${size}" height="${size}" role="img" aria-label="${esc(label)}">
    <circle cx="${cx}" cy="${cy}" r="${r}" fill="none" stroke="var(--line)" stroke-width="${w}"/>
    ${segs.join('')}
    <text x="100" y="96" text-anchor="middle" class="donut-big">${esc(label)}</text>
    <text x="100" y="118" text-anchor="middle" class="donut-sub">${esc(sub)}</text>
  </svg>`;
}

export function topN(items, n = 7) {
  if (items.length <= n + 1) return items;
  const head = items.slice(0, n);
  const rest = items.slice(n).reduce((t, i) => t + i.total, 0);
  return [...head, { name: 'Everything else', total: rest, rest: true }];
}

// Paired columns: [{label, a, b}] (a = income, b = spending).
export function columns(rows, { height = 180, fmt = (v) => v, aLabel = 'In', bLabel = 'Out' } = {}) {
  const max = Math.max(1, ...rows.flatMap((r) => [r.a, r.b]));
  const nice = niceMax(max);
  const W = 640, H = height, padL = 46, padB = 22, padT = 8;
  const cw = (W - padL) / rows.length;
  const bw = Math.min(16, cw / 3);
  const y = (v) => padT + (H - padT - padB) * (1 - v / nice);
  const ticks = [0, nice / 2, nice];
  const grid = ticks.map((t) => `<line x1="${padL}" x2="${W}" y1="${y(t)}" y2="${y(t)}" class="grid"/><text x="${padL - 6}" y="${y(t) + 4}" text-anchor="end" class="axis">${esc(fmt(t))}</text>`).join('');
  const bars = rows.map((r, i) => {
    const x = padL + i * cw + cw / 2;
    return `<g><title>${esc(r.label)} · ${aLabel} ${esc(fmt(r.a))} · ${bLabel} ${esc(fmt(r.b))}</title>
      <rect x="${x - bw - 1}" y="${y(r.a)}" width="${bw}" height="${Math.max(0, y(0) - y(r.a))}" rx="3" fill="var(--pos)"/>
      <rect x="${x + 1}" y="${y(r.b)}" width="${bw}" height="${Math.max(0, y(0) - y(r.b))}" rx="3" fill="var(--neg)"/>
      <text x="${x}" y="${H - 6}" text-anchor="middle" class="axis">${esc(r.label)}</text></g>`;
  }).join('');
  return `<div class="chart-scroll"><svg viewBox="0 0 ${W} ${H}" class="chart" role="img" aria-label="Income and spending by month">${grid}${bars}</svg></div>`;
}

// Line(s): series [{name, colour, points:[{x(label), v}]}]
export function lines(series, { height = 200, fmt = (v) => v, labels = [] } = {}) {
  const all = series.flatMap((s) => s.points.map((p) => p.v));
  const max = niceMax(Math.max(1, ...all));
  const n = Math.max(...series.map((s) => s.points.length));
  const W = 640, H = height, padL = 56, padB = 22, padT = 10, padR = 10;
  const x = (i) => padL + ((W - padL - padR) * i) / Math.max(1, n - 1);
  const y = (v) => padT + (H - padT - padB) * (1 - v / max);
  const ticks = [0, max / 2, max];
  const grid = ticks.map((t) => `<line x1="${padL}" x2="${W - padR}" y1="${y(t)}" y2="${y(t)}" class="grid"/><text x="${padL - 6}" y="${y(t) + 4}" text-anchor="end" class="axis">${esc(fmt(t))}</text>`).join('');
  const step = Math.ceil(labels.length / 6) || 1;
  const xl = labels.map((l, i) => (i % step === 0 || i === labels.length - 1 ? `<text x="${x(i)}" y="${H - 6}" text-anchor="middle" class="axis">${esc(l)}</text>` : '')).join('');
  const paths = series.map((s) => {
    const pts = s.points.map((p, i) => `${x(i).toFixed(1)},${y(p.v).toFixed(1)}`);
    const area = s.area ? `<path d="M ${x(0)},${y(0)} L ${pts.join(' L ')} L ${x(s.points.length - 1)},${y(0)} Z" fill="${s.colour}" opacity=".12"/>` : '';
    const last = s.points.length - 1;
    return `${area}<polyline points="${pts.join(' ')}" fill="none" stroke="${s.colour}" stroke-width="2.5" stroke-linejoin="round" ${s.dash ? 'stroke-dasharray="5 5"' : ''}/>
      <circle cx="${x(last)}" cy="${y(s.points[last]?.v || 0)}" r="4" fill="${s.colour}"/>`;
  }).join('');
  return `<div class="chart-scroll"><svg viewBox="0 0 ${W} ${H}" class="chart" role="img" aria-label="${esc(series.map((s) => s.name).join(', '))}">${grid}${paths}${xl}</svg></div>`;
}

export function niceMax(v) {
  const p = Math.pow(10, Math.floor(Math.log10(v)));
  for (const m of [1, 1.2, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10]) if (m * p >= v) return m * p;
  return 10 * p;
}

export function meter(frac, cls = '') {
  const f = Math.max(0, Math.min(1, frac || 0));
  return `<div class="meter ${cls}"><span style="width:${(f * 100).toFixed(1)}%"></span></div>`;
}
