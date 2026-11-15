// Shared UI helpers + the app context (state, ui state, actions) used by every view.
import { num } from './calc.js';

export const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const gbpF = new Intl.NumberFormat('en-GB', { style: 'currency', currency: 'GBP' });
const gbp0F = new Intl.NumberFormat('en-GB', { style: 'currency', currency: 'GBP', maximumFractionDigits: 0 });
export const gbp = (v) => gbpF.format(num(v));
export const gbp0 = (v) => gbp0F.format(num(v));
export const gbpK = (v) => { v = num(v); const a = Math.abs(v); return a >= 1e6 ? `£${(v / 1e6).toFixed(a >= 1e7 ? 0 : 1)}m` : a >= 1e4 ? `£${Math.round(v / 1000)}k` : a >= 1000 ? `£${(v / 1000).toFixed(1)}k` : `£${Math.round(v)}`; };
export const pct = (f, dp = 0) => `${(num(f) * 100).toFixed(dp)}%`;

const MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const DAY = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
export const monthLabel = (mk, long = false) => { const [y, m] = mk.split('-').map(Number); return long ? new Date(y, m - 1, 1).toLocaleString('en-GB', { month: 'long', year: 'numeric' }) : `${MON[m - 1]} ${y}`; };
export const shortMonth = (mk) => MON[Number(mk.slice(5, 7)) - 1];
export const dayName = (iso) => DAY[new Date(iso + 'T12:00').getDay()];
export const niceDate = (iso) => { if (!iso) return '—'; const [y, m, d] = iso.split('-').map(Number); return `${d} ${MON[m - 1]} ${y}`; };
export const monthYear = (iso) => { if (!iso) return '—'; const [y, m] = iso.split('-').map(Number); return `${MON[m - 1]} ${y}`; };
export const duration = (months) => { if (months == null) return 'never at this rate'; const y = Math.floor(months / 12), m = months % 12; return [y && `${y} yr${y > 1 ? 's' : ''}`, m && `${m} mo`].filter(Boolean).join(' ') || 'now'; };

// Bound inputs: data-bind="collectionPath|id|field" or "objectPath||field".
export function field(bind, value, { type = 'text', cls = '', placeholder = '', step, list, label, attrs = '' } = {}) {
  const id = 'f-' + bind.replace(/[^a-z0-9]/gi, '-');
  const inputType = type === 'money' || type === 'pct' ? 'number' : type;
  const v = type === 'pct' ? +(num(value) * 100).toFixed(3) : value ?? '';
  const st = step ?? (type === 'money' ? '0.01' : type === 'pct' ? '0.1' : undefined);
  return `<input id="${id}" class="in ${cls}" type="${inputType}" ${inputType === 'number' ? 'inputmode="decimal"' : ''} ${st ? `step="${st}"` : ''} data-bind="${esc(bind)}" data-kind="${type}" value="${esc(v)}" placeholder="${esc(placeholder)}" ${list ? `list="${list}"` : ''} ${label ? `aria-label="${esc(label)}"` : ''} ${attrs}>`;
}
export function select(bind, value, options, { cls = '', label = '' } = {}) {
  const id = 'f-' + bind.replace(/[^a-z0-9]/gi, '-');
  return `<select id="${id}" class="in ${cls}" data-bind="${esc(bind)}" data-kind="text" ${label ? `aria-label="${esc(label)}"` : ''}>${options.map((o) => { const [val, text] = Array.isArray(o) ? o : [o, o]; return `<option value="${esc(val)}" ${String(val) === String(value ?? '') ? 'selected' : ''}>${esc(text)}</option>`; }).join('')}</select>`;
}
export const delBtn = (path, id, what = 'row') => `<button class="icon-btn danger" data-del="${esc(path)}|${esc(id)}" aria-label="Remove ${esc(what)}" title="Remove">✕</button>`;
export const addBtn = (path, text, tpl = {}) => `<button class="btn ghost small" data-add="${esc(path)}" data-tpl="${esc(JSON.stringify(tpl))}">+ ${esc(text)}</button>`;

export function getPath(obj, path) { return path.split('.').reduce((o, k) => (o == null ? o : o[k]), obj); }

export const ctx = { state: null, ui: {}, render: () => {}, commit: () => {}, toast: () => {}, openTx: () => {}, modal: () => {}, closeModal: () => {} };

export function subsOf(category, kind) {
  const c = ctx.state.categories.find((x) => x.name === category && (!kind || x.kind === kind));
  const count = new Map();
  for (const t of ctx.state.transactions) if (t.category === category && t.subcategory) count.set(t.subcategory, (count.get(t.subcategory) || 0) + 1);
  for (const sname of c?.subs || []) if (!count.has(sname)) count.set(sname, 0);
  return [...count].sort((a, b) => b[1] - a[1]).map(([name, n]) => ({ name, n }));
}
export const catsOf = (kind) => ctx.state.categories.filter((c) => c.kind === kind).map((c) => c.name);
export const accountName = (id) => ctx.state.accounts.find((a) => a.id === id)?.name || '—';
export const icon = (name) => { const m = String(name || '').match(/^\p{Extended_Pictographic}[\u{FE0F}\u{1F3FB}-\u{1F3FF}‍\p{Extended_Pictographic}]*/u); return m ? m[0] : ''; };
export const bare = (name) => String(name || '').replace(/^\p{Extended_Pictographic}[\u{FE0F}\u{1F3FB}-\u{1F3FF}‍\p{Extended_Pictographic}]*\s*/u, '') || name;
