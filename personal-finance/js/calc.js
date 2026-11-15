// Pure money maths. No DOM, no storage — unit-tested in tests/calc.test.mjs.

export const round2 = (n) => Math.round((Number(n) || 0) * 100) / 100;
export const num = (v) => {
  const n = typeof v === 'number' ? v : parseFloat(String(v ?? '').replace(/[£,\s]/g, ''));
  return Number.isFinite(n) ? n : 0;
};
export const sum = (arr, f = (x) => x) => arr.reduce((t, x) => t + num(f(x)), 0);

// ---------- dates (all local wall-clock, YYYY-MM-DD strings) ----------
export const pad = (n) => String(n).padStart(2, '0');
export const isoDate = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
export const today = () => isoDate(new Date());
export const monthKey = (iso) => String(iso).slice(0, 7);
export function addMonths(iso, n) {
  const [y, m, d] = iso.split('-').map(Number);
  const target = new Date(y, m - 1 + n, 1);
  const last = new Date(target.getFullYear(), target.getMonth() + 1, 0).getDate();
  target.setDate(Math.min(d, last));
  return isoDate(target);
}
export function addDays(iso, n) {
  const [y, m, d] = iso.split('-').map(Number);
  return isoDate(new Date(y, m - 1, d + n));
}
export function monthsBetween(fromIso, toIso) {
  const [y1, m1, d1] = fromIso.split('-').map(Number);
  const [y2, m2, d2] = toIso.split('-').map(Number);
  let m = (y2 - y1) * 12 + (m2 - m1);
  if (d2 < d1) m -= 1;
  return m;
}
export function lastNMonths(n, endIso = today()) {
  const out = [];
  for (let i = n - 1; i >= 0; i--) out.push(monthKey(addMonths(endIso.slice(0, 7) + '-01', -i)));
  return out;
}

// UK tax year runs 6 April → 5 April.
export function taxYear(iso = today()) {
  const [y, m, d] = iso.split('-').map(Number);
  const startYear = m > 4 || (m === 4 && d >= 6) ? y : y - 1;
  return { start: `${startYear}-04-06`, end: `${startYear + 1}-04-05`, label: `${startYear}/${String(startYear + 1).slice(2)}` };
}

// ---------- growth ----------
// Future value with monthly compounding and monthly contributions at month end.
export function futureValue(pv, monthly, annualRate, months) {
  const r = num(annualRate) / 12;
  if (r === 0) return num(pv) + num(monthly) * months;
  const g = Math.pow(1 + r, months);
  return num(pv) * g + num(monthly) * ((g - 1) / r);
}

export function monthsToTarget(pv, monthly, annualRate, target, cap = 1200) {
  if (num(pv) >= num(target)) return 0;
  const r = num(annualRate) / 12;
  let bal = num(pv);
  for (let m = 1; m <= cap; m++) {
    bal = bal * (1 + r) + num(monthly);
    if (bal >= target) return m;
  }
  return null; // not reachable within cap
}

// Financial freedom: annual need ÷ safe withdrawal rate (4% → ×25).
export function freedom({ monthlyNeed, current, monthly, rate, withdrawalRate = 0.04 }, now = today()) {
  const goal = (num(monthlyNeed) * 12) / (num(withdrawalRate) || 0.04);
  const months = monthsToTarget(current, monthly, rate, goal);
  return {
    goal,
    months,
    years: months == null ? null : months / 12,
    date: months == null ? null : addMonths(now, months),
    progress: goal ? Math.min(1, num(current) / goal) : 0,
  };
}

// ---------- sinking funds ----------
export function sinkingFund(f, now = today()) {
  const target = num(f.target), saved = num(f.saved), monthly = num(f.monthly);
  const months = f.date ? Math.max(0, monthsBetween(now, f.date)) : 0;
  const byDate = saved + monthly * months;
  const remaining = Math.max(0, target - saved);
  return {
    months,
    pct: target ? Math.min(1, saved / target) : 0,
    byDate,
    byDatePct: target ? Math.min(1, byDate / target) : 0,
    needPerMonth: months > 0 ? remaining / months : remaining,
    onTrack: byDate >= target,
    overdue: f.date ? f.date < now && saved < target : false,
  };
}

// ---------- debt payoff (snowball / avalanche) ----------
export function debtPlan(debts, monthlyBudget, method = 'avalanche', start = today(), cap = 600) {
  let ds = debts
    .filter((d) => num(d.balance) > 0)
    .map((d, i) => ({ id: d.id ?? i, name: d.name || `Debt ${i + 1}`, bal: num(d.balance), rate: num(d.rate) / 100, min: num(d.min), paidOff: null, interest: 0 }));
  const minTotal = sum(ds, (d) => d.min);
  const budget = Math.max(num(monthlyBudget), minTotal);
  const order = () =>
    ds.filter((d) => d.bal > 0.005).sort((a, b) => (method === 'snowball' ? a.bal - b.bal || b.rate - a.rate : b.rate - a.rate || a.bal - b.bal));
  const schedule = [];
  let month = 0, totalInterest = 0;
  while (ds.some((d) => d.bal > 0.005) && month < cap) {
    month++;
    ds.forEach((d) => {
      if (d.bal <= 0.005) return;
      const i = d.bal * (d.rate / 12);
      d.bal += i; d.interest += i; totalInterest += i;
    });
    let pot = budget;
    const paid = {};
    // minimums first
    ds.forEach((d) => {
      if (d.bal <= 0.005) return;
      const p = Math.min(d.min, d.bal, pot);
      d.bal -= p; pot -= p; paid[d.id] = (paid[d.id] || 0) + p;
    });
    // everything left goes to the target debt(s) in order
    for (const d of order()) {
      if (pot <= 0.005) break;
      const p = Math.min(d.bal, pot);
      d.bal -= p; pot -= p; paid[d.id] = (paid[d.id] || 0) + p;
    }
    ds.forEach((d) => { if (d.bal <= 0.005 && d.paidOff == null) { d.bal = 0; d.paidOff = month; } });
    schedule.push({ month, date: addMonths(start, month), paid, balances: Object.fromEntries(ds.map((d) => [d.id, round2(d.bal)])), total: round2(sum(ds, (d) => d.bal)) });
  }
  const done = !ds.some((d) => d.bal > 0.005);
  return {
    method, budget, minTotal, months: done ? month : null,
    date: done ? addMonths(start, month) : null,
    totalInterest: round2(totalInterest),
    debts: ds.map((d) => ({ id: d.id, name: d.name, paidOffMonth: d.paidOff, paidOffDate: d.paidOff ? addMonths(start, d.paidOff) : null, interest: round2(d.interest) })),
    schedule,
    stuck: !done,
  };
}

// ---------- transactions ----------
export function monthTotals(txns, mk) {
  let income = 0, expense = 0;
  for (const t of txns) {
    if (monthKey(t.date) !== mk) continue;
    if (t.type === 'income') income += num(t.amount);
    else if (t.type === 'expense') expense += num(t.amount);
  }
  return { income: round2(income), expense: round2(expense), net: round2(income - expense) };
}

export function byCategory(txns, { type = 'expense', from, to } = {}) {
  const map = new Map();
  for (const t of txns) {
    if (t.type !== type) continue;
    if (from && t.date < from) continue;
    if (to && t.date > to) continue;
    const k = t.category || 'Uncategorised';
    map.set(k, (map.get(k) || 0) + num(t.amount));
  }
  return [...map.entries()].map(([name, total]) => ({ name, total: round2(total) })).sort((a, b) => b.total - a.total);
}

export function cashflow(txns, months) {
  return months.map((mk) => ({ month: mk, ...monthTotals(txns, mk) }));
}

export function accountBalances(accounts, txns) {
  const bal = Object.fromEntries(accounts.map((a) => [a.id, num(a.opening)]));
  for (const t of txns) {
    const a = num(t.amount);
    if (t.type === 'income' && t.accountId in bal) bal[t.accountId] += a;
    if (t.type === 'expense' && t.accountId in bal) bal[t.accountId] -= a;
    if (t.type === 'transfer') {
      if (t.accountId in bal) bal[t.accountId] -= a;
      if (t.toAccountId in bal) bal[t.toAccountId] += a;
    }
  }
  return Object.fromEntries(Object.entries(bal).map(([k, v]) => [k, round2(v)]));
}

// Most-used payee+category pairs, for one-tap quick entries.
export function frequentEntries(txns, limit = 8, sinceIso) {
  const map = new Map();
  for (const t of txns) {
    if (t.type === 'transfer' || !t.note) continue;
    if (sinceIso && t.date < sinceIso) continue;
    const key = `${t.type}|${t.category}|${t.note.trim().toLowerCase()}`;
    const e = map.get(key) || { type: t.type, category: t.category, subcategory: t.subcategory || '', note: t.note.trim(), accountId: t.accountId, count: 0, last: 0 };
    e.count++; e.last = num(t.amount);
    map.set(key, e);
  }
  return [...map.values()].sort((a, b) => b.count - a.count).slice(0, limit);
}

// ---------- recurring ----------
export function nextDate(iso, freq) {
  if (freq === 'weekly') return addDays(iso, 7);
  if (freq === 'fortnightly') return addDays(iso, 14);
  if (freq === 'yearly') return addMonths(iso, 12);
  return addMonths(iso, 1);
}
// Returns the occurrence dates of a rule that are due up to and including `upTo`.
export function dueOccurrences(rule, upTo = today(), max = 60) {
  const out = [];
  let d = rule.next;
  while (d && d <= upTo && out.length < max) { out.push(d); d = nextDate(d, rule.freq); }
  return { dates: out, next: d };
}

// ---------- budget ----------
export function budgetSummary(budget) {
  const income = sum(budget.income, (i) => i.amount);
  const g = (k) => sum(budget.items.filter((i) => i.group === k), (i) => i.amount);
  const fixed = g('fixed'), variable = g('variable'), optional = g('optional');
  const outgoing = fixed + variable + optional;
  const surplus = income - outgoing;
  const allocated = sum(budget.allocation || [], (a) => a.amount);
  const pct = (v) => (income ? v / income : 0);
  return { income, fixed, variable, optional, outgoing, surplus, allocated, unallocated: surplus - allocated, pct: { fixed: pct(fixed), variable: pct(variable), optional: pct(optional), surplus: pct(surplus) } };
}

export const ISA_ALLOWANCE = 20000;
export const LISA_ALLOWANCE = 4000;
