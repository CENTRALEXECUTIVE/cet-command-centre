// Insights: "does anything look off?", "what's left before payday?", subscriptions,
// money score, self-employed tax pot, budget suggestions and the on-device question engine.
// Pure functions over the app state — unit-tested in tests/insights.test.mjs.
import * as C from './calc.js';
import { normMerchant } from './importers.js';

const LIABILITY_GROUPS = new Set(['card', 'loan']);
const median = (a) => { if (!a.length) return 0; const s = [...a].sort((x, y) => x - y); const m = s.length >> 1; return s.length % 2 ? s[m] : (s[m - 1] + s[m]) / 2; };
const daysBetween = (a, b) => Math.round((new Date(b + 'T12:00') - new Date(a + 'T12:00')) / 864e5);
const merchantOf = (t) => normMerchant(t.note || t.description || t.bankDescription || '');
const money = (v) => new Intl.NumberFormat('en-GB', { style: 'currency', currency: 'GBP' }).format(C.num(v));
const money0 = (v) => new Intl.NumberFormat('en-GB', { style: 'currency', currency: 'GBP', maximumFractionDigits: 0 }).format(C.num(v));
const MON = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
const nice = (iso) => { const [y, m, d] = iso.split('-').map(Number); return `${d} ${MON[m - 1].slice(0, 3)} ${y}`; };

// ---------- net worth ----------
export function netWorthOf(state) {
  const bal = C.accountBalances(state.accounts, state.transactions);
  let assets = 0, liabilities = 0;
  for (const a of state.accounts) {
    const v = bal[a.id] || 0;
    if (v >= 0) assets += v; else liabilities += -v;
  }
  assets += C.sum(state.networth.assets, (x) => x.value);
  liabilities += C.sum(state.networth.liabilities, (x) => x.value);
  return { assets, liabilities, net: assets - liabilities, bal };
}

// ---------- subscriptions & regular payments ----------
const FREQS = [['weekly', 6, 8, 7], ['fortnightly', 13, 15, 14], ['monthly', 26, 35, 30], ['yearly', 350, 380, 365]];

export function detectSubscriptions(txns, recurring = [], now = C.today()) {
  const from = C.addDays(now, -800);
  const groups = new Map();
  for (const t of txns) {
    if (t.type !== 'expense' || t.date < from || t.date > now) continue;
    const k = merchantOf(t);
    if (k.length < 3) continue;
    if (!groups.has(k)) groups.set(k, []);
    groups.get(k).push(t);
  }
  const listed = recurring.map((r) => normMerchant(r.name)).filter(Boolean);
  const out = [];
  for (const [key, list] of groups) {
    list.sort((a, b) => a.date.localeCompare(b.date));
    // one charge per day is enough to establish a rhythm
    const days = list.filter((t, i) => i === 0 || t.date !== list[i - 1].date);
    if (days.length < 2) continue;
    const gaps = days.slice(1).map((t, i) => daysBetween(days[i].date, t.date));
    const mg = median(gaps);
    const f = FREQS.find(([, lo, hi]) => mg >= lo && mg <= hi);
    if (!f) continue;
    const [freq, lo, hi, period] = f;
    if (days.length < 3) continue;
    const regular = gaps.filter((g) => g >= lo - 2 && g <= hi + 2).length / gaps.length;
    if (regular < 0.6) continue;
    const amounts = days.map((t) => C.num(t.amount));
    const med = median(amounts);
    const spread = (Math.max(...amounts.slice(-4)) - Math.min(...amounts.slice(-4))) / (med || 1);
    if (spread > 0.35) continue; // amounts vary too much to be a fixed bill
    const last = days.at(-1), prev = days.at(-2);
    if (daysBetween(last.date, now) > period * 1.6) continue; // stopped
    const rise = C.num(last.amount) > C.num(prev.amount) * 1.04 && C.num(last.amount) - C.num(prev.amount) >= 0.5;
    out.push({
      key, name: last.note || last.description || key, freq, count: days.length,
      amount: C.round2(last.amount), previous: C.round2(prev.amount), priceRise: rise,
      lastDate: last.date, nextDate: C.nextDate(last.date, freq), category: last.category, subcategory: last.subcategory || '', accountId: last.accountId,
      monthly: C.round2(freq === 'weekly' ? (last.amount * 52) / 12 : freq === 'fortnightly' ? (last.amount * 26) / 12 : freq === 'yearly' ? last.amount / 12 : last.amount),
      listed: listed.some((l) => l === key || l.includes(key) || key.includes(l)), lastId: last.id,
    });
  }
  return out.sort((a, b) => b.monthly - a.monthly);
}

// ---------- "does anything look off?" ----------
const FEE_RE = /\b(fees?|bank charges?|late payment|overdraft|interest charged?|interest|penalty|pcn|parking fine|admin charge|surcharge|unpaid|returned dd)\b/i;

export function findIssues(state, now = C.today(), days = 45) {
  const from = C.addDays(now, -days);
  const txns = state.transactions;
  const dismissed = new Set(state.settings?.dismissed || []);
  const issues = [];
  const recent = txns.filter((t) => t.type === 'expense' && t.date >= from && t.date <= now);

  // 1. charged twice: same payee, same amount, within 2 days
  const seen = new Set();
  const byKey = new Map();
  for (const t of recent) {
    if (C.num(t.amount) < 10) continue;
    const k = `${merchantOf(t)}|${C.num(t.amount).toFixed(2)}`;
    if (merchantOf(t).length < 3) continue;
    if (!byKey.has(k)) byKey.set(k, []);
    byKey.get(k).push(t);
  }
  for (const list of byKey.values()) {
    list.sort((a, b) => a.date.localeCompare(b.date));
    for (let i = 1; i < list.length; i++) {
      const a = list[i - 1], b = list[i];
      if (seen.has(a.id) || daysBetween(a.date, b.date) > 2) continue;
      seen.add(a.id); seen.add(b.id);
      issues.push({ id: `dup|${[a.id, b.id].sort().join('|')}`, kind: 'duplicate', severity: 3, title: `${b.note || 'Payment'} charged twice?`, detail: `${money(b.amount)} on ${nice(a.date)}${a.date === b.date ? ' (twice the same day)' : ` and again on ${nice(b.date)}`}. If only one was real, delete the other.`, amount: C.num(b.amount), date: b.date, txIds: [a.id, b.id] });
    }
  }

  // 2. fees and charges
  for (const t of recent) {
    const text = `${t.note} ${t.description || ''} ${t.subcategory || ''}`;
    if (!FEE_RE.test(text)) continue;
    issues.push({ id: `fee|${t.id}`, kind: 'fee', severity: 2, title: `Fee or charge: ${t.note || t.description}`, detail: `${money(t.amount)} on ${nice(t.date)}. Worth checking whether it can be avoided or refunded.`, amount: C.num(t.amount), date: t.date, txIds: [t.id] });
  }

  // 3. much more than usual at the same place
  const hist = new Map();
  for (const t of txns) {
    if (t.type !== 'expense') continue;
    const k = merchantOf(t);
    if (k.length < 3) continue;
    if (!hist.has(k)) hist.set(k, []);
    hist.get(k).push(t);
  }
  for (const t of recent) {
    const prior = (hist.get(merchantOf(t)) || []).filter((x) => x.date < t.date).map((x) => C.num(x.amount));
    if (prior.length < 4) continue;
    const med = median(prior);
    if (C.num(t.amount) > med * 2.5 && C.num(t.amount) - med >= 20) issues.push({ id: `big|${t.id}`, kind: 'unusual', severity: 2, title: `${t.note} was much higher than usual`, detail: `${money(t.amount)} on ${nice(t.date)}. You usually pay about ${money(med)} there.`, amount: C.num(t.amount), date: t.date, txIds: [t.id] });
  }

  // 4. subscriptions: price rises and ones not on the Repeating list
  for (const s of detectSubscriptions(txns, state.recurring, now)) {
    if (s.priceRise && s.lastDate >= from) issues.push({ id: `rise|${s.key}|${s.lastDate}`, kind: 'price', severity: 2, title: `${s.name} went up`, detail: `Now ${money(s.amount)}, was ${money(s.previous)} (${s.freq}). That's ${money((s.amount - s.previous) * (s.freq === 'weekly' ? 52 : s.freq === 'fortnightly' ? 26 : s.freq === 'yearly' ? 1 : 12))} more a year.`, amount: s.amount, date: s.lastDate, txIds: [s.lastId], sub: s });
    if (!s.listed) issues.push({ id: `sub|${s.key}`, kind: 'subscription', severity: 1, title: `${s.name}: regular payment not on your Repeating list`, detail: `${money(s.amount)} ${s.freq}, ${s.count} times so far (about ${money0(s.monthly * 12)} a year). Add it so it logs itself, or cancel it if you'd forgotten about it.`, amount: s.amount, date: s.lastDate, txIds: [s.lastId], sub: s });
  }
  return issues.filter((i) => !dismissed.has(i.id)).sort((a, b) => b.severity - a.severity || b.date.localeCompare(a.date));
}

// ---------- "what's left before payday?" ----------
export function nextPayday(cfg = {}, now = C.today()) {
  const mode = cfg.mode || 'weekly';
  if (mode === 'monthly') {
    const day = Math.min(31, Math.max(1, Number(cfg.day) || 1));
    const mk = (y, m) => { const last = new Date(y, m + 1, 0).getDate(); return C.isoDate(new Date(y, m, Math.min(day, last))); };
    const [y, m] = now.split('-').map(Number);
    const thisMonth = mk(y, m - 1);
    return thisMonth > now ? thisMonth : mk(y, m);
  }
  const want = Number(cfg.weekday ?? 1); // 0 Sun … 6 Sat
  const step = mode === 'fortnightly' ? 14 : 7;
  if (mode === 'fortnightly' && cfg.anchor) {
    let d = cfg.anchor;
    while (d <= now) d = C.addDays(d, 14);
    while (C.addDays(d, -14) > now) d = C.addDays(d, -14);
    return d;
  }
  for (let i = 1; i <= step; i++) { const d = C.addDays(now, i); if (new Date(d + 'T12:00').getDay() === want) return d; }
  return C.addDays(now, step);
}

export function payday(state, now = C.today()) {
  const cfg = state.settings?.payday || {};
  const date = nextPayday(cfg, now);
  const days = Math.max(1, daysBetween(now, date));
  const bal = C.accountBalances(state.accounts, state.transactions);
  const picked = cfg.accounts?.length ? state.accounts.filter((a) => cfg.accounts.includes(a.id)) : state.accounts.filter((a) => a.group === 'cash' || a.group === 'bank');
  const available = C.sum(picked, (a) => bal[a.id]);
  const bills = [], incoming = [];
  for (const r of state.recurring) {
    let d = r.next, n = 0;
    while (d && d < date && n++ < 40) { (r.type === 'income' ? incoming : bills).push({ id: r.id, name: r.name, date: d, amount: C.num(r.amount), overdue: d < now }); d = C.nextDate(d, r.freq); }
  }
  bills.sort((a, b) => a.date.localeCompare(b.date));
  const billsTotal = C.sum(bills, (b) => b.amount), incomingTotal = C.sum(incoming, (b) => b.amount);
  const safe = available - billsTotal;
  return { date, days, available: C.round2(available), accounts: picked.map((a) => a.name), bills, billsTotal: C.round2(billsTotal), incoming, incomingTotal: C.round2(incomingTotal), safe: C.round2(safe), perDay: C.round2(safe / days) };
}

// ---------- money score ----------
export function healthScore(state, now = C.today()) {
  const months = C.lastNMonths(4, now).slice(0, 3); // last 3 full months
  const flow = C.cashflow(state.transactions, months).filter((f) => f.income || f.expense);
  const inc = flow.length ? C.sum(flow, (f) => f.income) / flow.length : 0;
  const exp = flow.length ? C.sum(flow, (f) => f.expense) / flow.length : 0;
  const clamp = (v) => Math.max(0, Math.min(1, v));
  const parts = [];
  const rate = inc ? (inc - exp) / inc : 0;
  parts.push({ name: 'Savings rate', score: Math.round(25 * clamp(rate / 0.2)), max: 25, note: inc ? `You keep ${Math.round(rate * 100)}% of what comes in (3-month average). 20% scores full marks.` : 'Log a few months of income and spending to score this.' });
  const bal = C.accountBalances(state.accounts, state.transactions);
  const cushion = Math.max(0, C.sum(state.accounts.filter((a) => a.group === 'savings' || a.group === 'cash' || a.group === 'bank'), (a) => bal[a.id]));
  const monthsCover = exp ? cushion / exp : 0;
  parts.push({ name: 'Emergency cushion', score: Math.round(25 * clamp(monthsCover / 6)), max: 25, note: exp ? `Your cash and savings cover ${monthsCover.toFixed(1)} months of spending. 6 months scores full marks.` : 'Needs spending history.' });
  const nw = netWorthOf(state);
  const owed = Math.max(C.sum(state.debts.items, (d) => d.balance), C.sum(state.accounts.filter((a) => LIABILITY_GROUPS.has(a.group)), (a) => Math.max(0, -(bal[a.id] || 0))));
  const debtRatio = inc ? owed / (inc * 12) : owed ? 1 : 0;
  parts.push({ name: 'Debt load', score: Math.round(25 * (1 - clamp(debtRatio / 0.5))), max: 25, note: owed ? `You owe ${money0(owed)}, about ${Math.round(debtRatio * 100)}% of a year's income. Mortgage-type debts added as "other liabilities" aren't counted.` : 'No debts tracked.' });
  const bud = C.budgetSummary(state.budget);
  const mk = C.monthKey(now), dom = Number(now.slice(8)), dim = new Date(+now.slice(0, 4), +now.slice(5, 7), 0).getDate();
  const spent = C.monthTotals(state.transactions, mk).expense;
  let bScore = 12, bNote = 'Set up your budget plan to score this.';
  if (bud.outgoing > 0) {
    const pace = spent / (bud.outgoing * (dom / dim));
    bScore = Math.round(25 * clamp(1 - Math.max(0, pace - 1)));
    bNote = pace <= 1 ? `Spending is within plan for day ${dom} of the month.` : `Spending is running ${Math.round((pace - 1) * 100)}% ahead of plan for day ${dom}.`;
  }
  parts.push({ name: 'Sticking to plan', score: bScore, max: 25, note: bNote });
  const score = C.sum(parts, (p) => p.score);
  return { score, label: score >= 80 ? 'Strong' : score >= 60 ? 'Good' : score >= 40 ? 'Building' : 'Needs attention', parts, netWorth: nw.net };
}

// ---------- self-employed tax pot (estimate) ----------
// England/Wales/NI rates for 2024/25 onward: thresholds are frozen. Scotland differs.
export const TAX = { allowance: 12570, basicLimit: 50270, higherLimit: 125140, taperStart: 100000, class4Main: 0.06, class4Upper: 0.02 };

export function taxOn(profit) {
  const p = Math.max(0, C.num(profit));
  const allowance = Math.max(0, TAX.allowance - Math.max(0, (p - TAX.taperStart) / 2));
  const taxable = Math.max(0, p - allowance);
  const basicBand = TAX.basicLimit - TAX.allowance;
  const higherBand = TAX.higherLimit - TAX.allowance - basicBand;
  const basic = Math.min(taxable, basicBand);
  const higher = Math.min(Math.max(0, taxable - basicBand), higherBand + (TAX.allowance - allowance));
  const additional = Math.max(0, taxable - basic - higher);
  const tax = basic * 0.2 + higher * 0.4 + additional * 0.45;
  const ni = Math.max(0, Math.min(p, TAX.basicLimit) - TAX.allowance) * TAX.class4Main + Math.max(0, p - TAX.basicLimit) * TAX.class4Upper;
  return { tax: C.round2(tax), ni: C.round2(ni), total: C.round2(tax + ni) };
}

export function taxEstimate(state, now = C.today()) {
  const cfg = state.settings?.tax || {};
  const incCats = new Set(cfg.income || []), costCats = new Set(cfg.costs || []);
  const ty = C.taxYear(now);
  const inYear = state.transactions.filter((t) => t.date >= ty.start && t.date <= ty.end && t.date <= now);
  const income = C.sum(inYear.filter((t) => t.type === 'income' && (incCats.has(t.category) || t.business)), (t) => t.amount);
  const costs = C.sum(inYear.filter((t) => t.type === 'expense' && (costCats.has(t.category) || t.business)), (t) => t.amount);
  const profit = Math.max(0, income - costs);
  const elapsed = Math.max(1, daysBetween(ty.start, now) + 1), total = daysBetween(ty.start, ty.end) + 1;
  const projectedProfit = (profit / elapsed) * total;
  const proj = taxOn(projectedProfit);
  const soFar = C.round2(proj.total * (elapsed / total));
  return { configured: incCats.size > 0 || inYear.some((t) => t.business), label: ty.label, income: C.round2(income), costs: C.round2(costs), profit: C.round2(profit), projectedProfit: C.round2(projectedProfit), projected: proj, dueSoFar: soFar, setAsidePct: profit ? soFar / profit : 0, fractionOfYear: elapsed / total };
}

// ---------- budget suggestions from real spending ----------
export function suggestBudget(txns, now = C.today(), n = 3) {
  const months = C.lastNMonths(n + 1, now).slice(0, n);
  const used = months.filter((mk) => txns.some((t) => C.monthKey(t.date) === mk));
  const div = used.length || 1;
  const agg = (type) => {
    const m = new Map();
    for (const t of txns) { if (t.type !== type || !used.includes(C.monthKey(t.date))) continue; m.set(t.category, (m.get(t.category) || 0) + C.num(t.amount)); }
    return [...m].map(([category, total]) => ({ category, monthly: Math.round(total / div) })).filter((x) => x.monthly > 0).sort((a, b) => b.monthly - a.monthly);
  };
  return { months: used, income: agg('income'), expenses: agg('expense') };
}

// ---------- on-device question engine ----------
export function parsePeriod(q, now = C.today()) {
  const s = q.toLowerCase();
  const y = +now.slice(0, 4);
  const mk = C.monthKey(now);
  const weekStart = (d) => C.addDays(d, -((new Date(d + 'T12:00').getDay() + 6) % 7));
  if (/\btoday\b/.test(s)) return { from: now, to: now, label: 'today' };
  if (/\byesterday\b/.test(s)) { const d = C.addDays(now, -1); return { from: d, to: d, label: 'yesterday' }; }
  if (/last week/.test(s)) { const f = C.addDays(weekStart(now), -7); return { from: f, to: C.addDays(f, 6), label: 'last week' }; }
  if (/this week/.test(s)) { const f = weekStart(now); return { from: f, to: C.addDays(f, 6), label: 'this week' }; }
  if (/last month/.test(s)) { const p = C.monthKey(C.addMonths(mk + '-01', -1)); return { from: p + '-01', to: p + '-31', label: `in ${MON[+p.slice(5) - 1]}` }; }
  if (/tax year/.test(s)) { const t = C.taxYear(/last tax year/.test(s) ? C.addMonths(now, -12) : now); return { from: t.start, to: t.end, label: `in tax year ${t.label}` }; }
  if (/last year/.test(s)) return { from: `${y - 1}-01-01`, to: `${y - 1}-12-31`, label: `in ${y - 1}` };
  if (/this year|so far this year|\bytd\b/.test(s)) return { from: `${y}-01-01`, to: `${y}-12-31`, label: `in ${y}` };
  const ym = s.match(/\b(20\d\d)\b/);
  const mi = MON.findIndex((m) => new RegExp(`\\b${m.toLowerCase()}\\b|\\b${m.slice(0, 3).toLowerCase()}\\b`).test(s.replace(/\bmay i\b/g, '')));
  if (mi >= 0) {
    let yy = ym ? +ym[1] : y;
    if (!ym && `${yy}-${C.pad(mi + 1)}` > mk) yy -= 1;
    const p = `${yy}-${C.pad(mi + 1)}`;
    return { from: p + '-01', to: p + '-31', label: `in ${MON[mi]} ${yy}` };
  }
  if (ym) return { from: `${ym[1]}-01-01`, to: `${ym[1]}-12-31`, label: `in ${ym[1]}` };
  const ld = s.match(/last (\d+) (day|week|month)s?/);
  if (ld) { const n = +ld[1]; const f = ld[2] === 'day' ? C.addDays(now, -n) : ld[2] === 'week' ? C.addDays(now, -7 * n) : C.addMonths(now, -n); return { from: f, to: now, label: `in the last ${n} ${ld[2]}${n > 1 ? 's' : ''}` }; }
  if (/all time|ever|in total|overall/.test(s)) return { from: '0000-01-01', to: '9999-12-31', label: 'in total' };
  return { from: mk + '-01', to: mk + '-31', label: 'this month', isDefault: true };
}

const bareCat = (name) => String(name || '').replace(/^[^\p{L}\p{N}]+/u, '').trim().toLowerCase();

function findEntity(q, state, type) {
  const s = ` ${q.toLowerCase().replace(/[^a-z0-9& ]/g, ' ')} `;
  const cats = state.categories.filter((c) => !type || c.kind === type);
  const cat = cats.map((c) => ({ c, b: bareCat(c.name) })).filter((x) => x.b.length >= 3 && x.b !== 'other').sort((a, b) => b.b.length - a.b.length).find((x) => s.includes(` ${x.b}`) || x.b.split(' ').some((w) => w.length > 3 && s.includes(` ${w} `)));
  // payee words: the most-used notes that appear in the question
  const counts = new Map();
  for (const t of state.transactions) { if (type && t.type !== type) continue; const n = (t.note || '').trim().toLowerCase(); if (n.length >= 3) counts.set(n, (counts.get(n) || 0) + 1); }
  const note = [...counts].filter(([n]) => s.includes(` ${n} `) || s.includes(` ${n}s `)).sort((a, b) => b[0].length - a[0].length || b[1] - a[1])[0];
  const sub = [...new Set(state.transactions.map((t) => (t.subcategory || '').toLowerCase()).filter((x) => x.length >= 3))].find((x) => s.includes(` ${x} `));
  if (note && (!cat || note[0].length > cat.b.length)) return { kind: 'note', value: note[0] };
  if (cat) return { kind: 'category', value: cat.c.name };
  if (note) return { kind: 'note', value: note[0] };
  if (sub) return { kind: 'sub', value: sub };
  return null;
}

export const SUGGESTED_QUESTIONS = ['Where did all my money go this month?', 'Does anything look off?', "What's left before payday?", 'How much did I earn this year?', 'What are my subscriptions?', 'What is my net worth?'];

export function answer(question, state, now = C.today()) {
  const q = String(question || '').trim();
  const s = q.toLowerCase();
  if (!q) return { text: 'Ask about your spending, income, bills, payday or goals.', rows: [] };
  const tx = state.transactions;

  if (/look(s)? off|anything (wrong|odd|off|unusual|strange)|fraud|charged twice|double charg|duplicate|mistake|suspicious/.test(s)) {
    const issues = findIssues(state, now);
    if (!issues.length) return { text: 'Nothing looks off in the last 45 days: no double charges, surprise fees, price rises or unlisted regular payments.', rows: [], link: 'insights' };
    return { text: `${issues.length} thing${issues.length > 1 ? 's' : ''} worth a look in the last 45 days:`, rows: issues.slice(0, 8).map((i) => ({ label: i.title, value: money(i.amount) })), link: 'insights' };
  }
  if (/payday|pay day|get paid|safe to spend|can i (afford|spend)|left to spend|what'?s left|how much (is |do i have )?left/.test(s)) {
    const p = payday(state, now);
    return { text: `You have ${money(p.safe)} safe to spend before payday on ${nice(p.date)} (${p.days} day${p.days > 1 ? 's' : ''}): about ${money(p.perDay)} a day. That's ${money(p.available)} in ${p.accounts.join(' + ') || 'your accounts'} minus ${money(p.billsTotal)} of bills still to come.`, rows: p.bills.slice(0, 8).map((b) => ({ label: `${b.name} · ${nice(b.date)}`, value: money(b.amount) })), link: 'insights' };
  }
  if (/subscription|regular payment|direct debit|standing order/.test(s)) {
    const subs = detectSubscriptions(tx, state.recurring, now);
    if (!subs.length) return { text: 'No regular payments detected yet. They show up after the same payee has been paid three times at a steady rhythm.', rows: [] };
    return { text: `${subs.length} regular payment${subs.length > 1 ? 's' : ''}, about ${money0(C.sum(subs, (x) => x.monthly))} a month (${money0(C.sum(subs, (x) => x.monthly) * 12)} a year):`, rows: subs.slice(0, 12).map((x) => ({ label: `${x.name} · ${x.freq}`, value: money(x.amount) })), link: 'insights' };
  }
  if (/net ?worth|what am i worth|how much am i worth/.test(s)) {
    const nw = netWorthOf(state);
    return { text: `Your net worth is ${money(nw.net)}: ${money(nw.assets)} in assets minus ${money(nw.liabilities)} owed.`, rows: state.accounts.map((a) => ({ label: a.name, value: money(nw.bal[a.id]) })), link: 'accounts' };
  }
  if (/debt.?free|pay off (my )?debt|clear (my )?debt/.test(s)) {
    if (!state.debts.items.length) return { text: 'No debts are set up in the debt planner yet.', rows: [], link: 'debts' };
    const p = C.debtPlan(state.debts.items, state.debts.budget, state.debts.method, now);
    return { text: p.date ? `At ${money0(p.budget)} a month you'll be debt free in ${nice(p.date).slice(nice(p.date).indexOf(' ') + 1)}, paying ${money0(p.totalInterest)} in interest.` : "At the current payment your debts never clear. Increase the monthly amount in the debt planner.", rows: p.debts.map((d) => ({ label: d.name, value: d.paidOffDate ? nice(d.paidOffDate) : 'not cleared' })), link: 'debts' };
  }
  if (/financial(ly)? free|freedom|retire/.test(s)) {
    const f = C.freedom(state.freedom, now);
    return { text: f.date ? `On your current plan you reach your freedom number of ${money0(f.goal)} around ${MON[+f.date.slice(5, 7) - 1]} ${f.date.slice(0, 4)}. You're ${(f.progress * 100).toFixed(1)}% of the way.` : 'With nothing invested monthly the freedom number is never reached. Set your numbers on the Freedom page.', rows: [], link: 'freedom' };
  }
  if (/\btax\b/.test(s) && /owe|set aside|pot|bill|how much|estimate/.test(s)) {
    const t = taxEstimate(state, now);
    if (!t.configured) return { text: 'Choose which income counts as self-employment on the Insights page and I can estimate your tax pot.', rows: [], link: 'insights' };
    return { text: `Estimated tax and National Insurance for ${t.label} so far: ${money0(t.dueSoFar)} on ${money0(t.profit)} profit. On track for about ${money0(t.projected.total)} for the full year. This is an estimate, not tax advice.`, rows: [{ label: 'Self-employed income', value: money(t.income) }, { label: 'Business costs', value: money(t.costs) }, { label: 'Profit so far', value: money(t.profit) }], link: 'insights' };
  }

  const per = parsePeriod(q, now);
  const inP = tx.filter((t) => t.date >= per.from && t.date <= per.to);
  const wantsIncome = /\b(earn|earned|earnings|income|made|make|paid me|got paid|revenue|takings)\b/.test(s);
  const wantsSaving = /\b(save|saved|saving|savings rate|left over|net)\b/.test(s) && !wantsIncome;
  const type = wantsIncome ? 'income' : 'expense';

  if (/where (did|does|has|is) (all )?(my|the) money|where.*money go|breakdown|biggest|top (spend|categor|expens)|most on|what (did|do) i spend/.test(s) && !findEntity(q, state, 'expense')) {
    const cats = C.byCategory(tx, { type: 'expense', from: per.from, to: per.to });
    const total = C.sum(cats, (c) => c.total);
    if (!total) return { text: `No spending logged ${per.label}.`, rows: [] };
    return { text: `You spent ${money(total)} ${per.label}. ${cats[0].name.replace(/^[^\p{L}]+/u, '')} was the biggest at ${money(cats[0].total)} (${Math.round((cats[0].total / total) * 100)}%).`, rows: cats.slice(0, 8).map((c) => ({ label: c.name, value: money(c.total), frac: c.total / total })), link: 'stats' };
  }
  if (wantsSaving && !findEntity(q, state)) {
    const inc = C.sum(inP.filter((t) => t.type === 'income'), (t) => t.amount), exp = C.sum(inP.filter((t) => t.type === 'expense'), (t) => t.amount);
    return { text: `${per.label[0].toUpperCase() + per.label.slice(1)} you brought in ${money(inc)} and spent ${money(exp)}, leaving ${money(inc - exp)}${inc ? ` (${Math.round(((inc - exp) / inc) * 100)}% of income)` : ''}.`, rows: [], link: 'stats' };
  }
  const ent = findEntity(q, state, type) || (wantsIncome ? null : findEntity(q, state));
  let list = inP.filter((t) => t.type === type);
  let what = type === 'income' ? 'earned' : 'spent';
  let on = '';
  if (ent) {
    if (ent.kind === 'category') { list = list.filter((t) => t.category === ent.value); on = ` ${type === 'income' ? 'from' : 'on'} ${ent.value.replace(/^[^\p{L}]+/u, '')}`; }
    else if (ent.kind === 'note') { list = list.filter((t) => (t.note || '').trim().toLowerCase() === ent.value); on = ` ${type === 'income' ? 'from' : 'at'} ${ent.value.replace(/\b\w/g, (c) => c.toUpperCase())}`; }
    else { list = list.filter((t) => (t.subcategory || '').toLowerCase() === ent.value); on = ` on ${ent.value}`; }
  } else if (!wantsIncome && !/\b(spend|spent|spending|cost|pay|paid|expenses?|outgoings?)\b/.test(s)) {
    return { text: "I couldn't match that to your data. Try one of these:", rows: [], suggestions: SUGGESTED_QUESTIONS, unknown: true };
  }
  const total = C.sum(list, (t) => t.amount);
  if (/average|typical|per month|a month/.test(s)) {
    const months = C.lastNMonths(6, now).slice(0, 5);
    const all = tx.filter((t) => t.type === type && months.includes(C.monthKey(t.date)) && (!ent || (ent.kind === 'category' ? t.category === ent.value : ent.kind === 'note' ? (t.note || '').trim().toLowerCase() === ent.value : (t.subcategory || '').toLowerCase() === ent.value)));
    const rows = months.map((mk) => ({ label: `${MON[+mk.slice(5) - 1]} ${mk.slice(0, 4)}`, value: money(C.sum(all.filter((t) => C.monthKey(t.date) === mk), (t) => t.amount)) }));
    return { text: `On average you ${what} ${money(C.sum(all, (t) => t.amount) / months.length)} a month${on} over the last ${months.length} full months.`, rows, link: 'stats' };
  }
  if (!list.length) return { text: `Nothing ${what}${on} ${per.label}.`, rows: [], link: 'stats' };
  const group = new Map();
  for (const t of list) { const k = ent?.kind === 'note' ? nice(t.date) : ent ? t.note || t.subcategory || '(no note)' : t.category; group.set(k, (group.get(k) || 0) + C.num(t.amount)); }
  const rows = [...group].sort((a, b) => b[1] - a[1]).slice(0, 8).map(([label, v]) => ({ label, value: money(v), frac: v / (total || 1) }));
  return { text: `You ${what} ${money(total)}${on} ${per.label}, across ${list.length} entr${list.length > 1 ? 'ies' : 'y'}.`, rows, link: 'stats' };
}

// Compact, private-by-default summary sent to Claude only when the user turns "Ask Claude" on.
export function contextForAI(state, now = C.today()) {
  const months = C.lastNMonths(6, now);
  const p = payday(state, now), nw = netWorthOf(state), bud = C.budgetSummary(state.budget);
  return {
    today: now, currency: 'GBP',
    monthly: C.cashflow(state.transactions, months),
    spendingByCategoryThisMonth: C.byCategory(state.transactions, { from: C.monthKey(now) + '-01', to: C.monthKey(now) + '-31' }),
    spendingByCategoryLast3Months: C.byCategory(state.transactions, { from: C.addMonths(now, -3), to: now }),
    incomeBySourceLast3Months: C.byCategory(state.transactions, { type: 'income', from: C.addMonths(now, -3), to: now }),
    budgetPlan: { income: bud.income, fixed: bud.fixed, variable: bud.variable, optional: bud.optional, surplus: bud.surplus },
    payday: { date: p.date, safeToSpend: p.safe, billsBeforeThen: p.bills.map((b) => ({ name: b.name, date: b.date, amount: b.amount })) },
    netWorth: { assets: C.round2(nw.assets), liabilities: C.round2(nw.liabilities), net: C.round2(nw.net) },
    regularPayments: detectSubscriptions(state.transactions, state.recurring, now).slice(0, 20).map((x) => ({ name: x.name, amount: x.amount, every: x.freq })),
    thingsToCheck: findIssues(state, now).slice(0, 10).map((i) => ({ title: i.title, detail: i.detail })),
    goals: state.goals.sinking.map((g) => ({ name: g.name, target: g.target, saved: g.saved, by: g.date })),
    debts: state.debts.items.map((d) => ({ name: d.name, balance: d.balance, apr: d.rate })),
    recentTransactions: [...state.transactions].sort((a, b) => b.date.localeCompare(a.date)).slice(0, 60).map((t) => ({ date: t.date, type: t.type, amount: t.amount, category: t.category, note: t.note })),
  };
}

// Apply a list of simple, named changes to the state. Returns plain-English notes of what changed.
export function applyUpdates(state, ops = []) {
  const notes = [];
  const byName = (list, name) => list.find((x) => String(x.name).trim().toLowerCase() === String(name).trim().toLowerCase());
  for (const op of ops) {
    if (op.op === 'setBalance') {
      const a = byName(state.accounts, op.account);
      if (!a) continue;
      const now = C.accountBalances([a], state.transactions)[a.id];
      a.opening = C.round2(C.num(a.opening) + C.num(op.balance) - now);
      notes.push(`${a.name} balance set to £${C.num(op.balance).toLocaleString('en-GB', { minimumFractionDigits: 2 })}`);
    } else if (op.op === 'setGoal') {
      const g = byName(state.goals.sinking, op.goal);
      if (!g) continue;
      for (const k of ['target', 'saved', 'monthly', 'date']) if (op[k] != null) g[k] = op[k];
      notes.push(`${g.name} goal updated`);
    } else if (op.op === 'setDebt') {
      const d = byName(state.debts.items, op.debt);
      if (!d) continue;
      for (const k of ['balance', 'rate', 'min']) if (op[k] != null) d[k] = op[k];
      notes.push(`${d.name} updated`);
    } else if (op.op === 'setAsset') {
      const x = byName(state.networth.assets, op.asset);
      if (x) x.value = op.value; else state.networth.assets.push({ id: Math.random().toString(36).slice(2, 10), name: op.asset, value: op.value });
      notes.push(`${op.asset} set to £${C.num(op.value).toLocaleString('en-GB')}`);
    }
  }
  return notes;
}
