// Home, Transactions, Stats, Accounts & net worth.
import * as C from './calc.js';
import { donut, topN, columns, lines, meter, PALETTE } from './charts.js';
import { ctx, esc, gbp, gbp0, gbpK, pct, monthLabel, shortMonth, dayName, niceDate, monthYear, duration, field, select, delBtn, addBtn, accountName, icon, bare } from './ui.js';
import { ACCOUNT_GROUPS } from './store.js';

const S = () => ctx.state;
const tx = () => S().transactions;

// ---------- shared bits ----------
export function netWorth() {
  const s = S();
  const bal = C.accountBalances(s.accounts, s.transactions);
  let assets = 0, liabilities = 0;
  for (const a of s.accounts) {
    const g = ACCOUNT_GROUPS.find((x) => x.id === a.group);
    const v = bal[a.id] || 0;
    if (g?.liability) { if (v < 0) liabilities += -v; else assets += v; }
    else if (v >= 0) assets += v; else liabilities += -v;
  }
  assets += C.sum(s.networth.assets, (x) => x.value);
  liabilities += C.sum(s.networth.liabilities, (x) => x.value);
  return { assets, liabilities, net: assets - liabilities, bal };
}

export function savingsAndInvestments() {
  const s = S();
  const bal = C.accountBalances(s.accounts, s.transactions);
  const accts = C.sum(s.accounts.filter((a) => a.group === 'savings' || a.group === 'investment'), (a) => bal[a.id]);
  const holdings = C.sum(s.holdings, (h) => C.num(h.units) * C.num(h.price));
  const goalsNow = C.sum(s.goals.investments, (g) => g.value);
  const now = Math.max(accts, holdings, goalsNow);
  const in12 = C.sum(s.goals.investments, (g) => C.futureValue(g.value, g.monthly, g.rate, 12)) + C.sum(s.goals.sinking, (f) => C.num(f.saved) + C.num(f.monthly) * 12);
  return { now, in12: Math.max(in12, now) };
}

function txRow(t) {
  const amt = t.type === 'income' ? `<span class="amt pos">${gbp(t.amount)}</span>` : t.type === 'expense' ? `<span class="amt neg">${gbp(t.amount)}</span>` : `<span class="amt">${gbp(t.amount)}</span>`;
  const cat = t.type === 'transfer' ? `<span class="cat-ico">⇄</span><span class="cat-name">Transfer</span>` : `<span class="cat-ico">${esc(icon(t.category)) || '•'}</span><span class="cat-name">${esc(bare(t.category))}${t.subcategory ? `<small>${esc(t.subcategory)}</small>` : ''}</span>`;
  const acct = t.type === 'transfer' ? `${esc(accountName(t.accountId))} → ${esc(accountName(t.toAccountId))}` : esc(accountName(t.accountId));
  const flags = [t.recurringId ? '<span class="tag">repeats</span>' : '', t.source === 'statement' ? '<span class="tag">bank</span>' : ''].join('');
  return `<button class="tx-row" data-edit-tx="${t.id}">
    <span class="tx-cat">${cat}</span>
    <span class="tx-main"><span class="tx-note">${esc(t.note || t.description || (t.type === 'transfer' ? 'Transfer' : bare(t.category)))}</span><span class="tx-sub">${acct}${t.description && t.note ? ` · ${esc(t.description)}` : ''} ${flags}</span></span>
    ${amt}</button>`;
}

function sampleBanner() {
  if (!S().settings.sample) return '';
  return `<div class="banner"><div><strong>You're looking at example data.</strong> None of these figures are yours. Import your Money Manager file to bring in your history, or start with a clean slate.</div>
    <div class="row-btns"><a class="btn primary small" href="#import">Import Money Manager</a><button class="btn ghost small" data-action="start-fresh">Start fresh</button></div></div>`;
}

// ---------- HOME ----------
export function home() {
  const s = S(), today = C.today(), mk = C.monthKey(today);
  const m = C.monthTotals(tx(), mk);
  const prev = C.monthTotals(tx(), C.monthKey(C.addMonths(today, -1)));
  const bud = C.budgetSummary(s.budget);
  const nw = netWorth();
  const si = savingsAndInvestments();
  const fr = C.freedom(s.freedom);
  const debt = s.debts.items.length ? C.debtPlan(s.debts.items, s.debts.budget, s.debts.method) : null;
  const dom = Number(today.slice(8)), dim = new Date(+today.slice(0, 4), +today.slice(5, 7), 0).getDate();
  const leftToSpend = bud.outgoing - m.expense;
  const perDay = leftToSpend / Math.max(1, dim - dom + 1);
  const cats = C.byCategory(tx(), { from: mk + '-01', to: mk + '-31' }).slice(0, 5);
  const catMax = cats[0]?.total || 1;
  const due = dueList();
  const last = tx().reduce((a, t) => (t.date > a ? t.date : a), '');
  const gap = last ? Math.round((new Date(today) - new Date(last)) / 864e5) : null;
  const backupDays = s.settings.lastBackup ? Math.round((new Date(today) - new Date(s.settings.lastBackup)) / 864e5) : null;
  const recent = [...tx()].sort((a, b) => (b.date + (b.time || '')).localeCompare(a.date + (a.time || ''))).slice(0, 6);
  const flow = C.cashflow(tx(), C.lastNMonths(6));
  const quick = C.frequentEntries(tx(), 6, C.addMonths(today, -3));

  const nudges = [];
  if (due.length) nudges.push(`<div class="nudge warn"><strong>${due.length} repeating payment${due.length > 1 ? 's' : ''} due.</strong> Log ${due.length > 1 ? 'them' : 'it'} in one tap. <a href="#recurring">Review</a></div>`);
  if (gap != null && gap >= 2) nudges.push(`<div class="nudge">Your last entry was ${gap} days ago. Anything to add? <button class="link" data-action="quick-add">Add now</button></div>`);
  if (!s.settings.sample && (backupDays == null || backupDays > 30) && tx().length) nudges.push(`<div class="nudge">${backupDays == null ? 'You have no backup yet.' : `Last backup was ${backupDays} days ago.`} Your data only lives on this device. <a href="#prefs">Back up now</a></div>`);

  return `${sampleBanner()}
  <header class="page-head"><div><p class="eyebrow">${esc(monthLabel(mk, true))}</p><h1>${s.settings.name ? `Hi ${esc(s.settings.name)}` : 'Your money'}</h1></div>
    <button class="btn primary" data-action="quick-add">+ Add</button></header>
  ${nudges.join('')}
  <section class="kpis">
    <div class="kpi"><span class="k-label">Income this month</span><span class="k-val pos">${gbp0(m.income)}</span><span class="k-foot">${trend(m.income, prev.income, true)} vs last month</span></div>
    <div class="kpi"><span class="k-label">Spent this month</span><span class="k-val neg">${gbp0(m.expense)}</span><span class="k-foot">${trend(m.expense, prev.expense, false)} vs last month</span></div>
    <div class="kpi"><span class="k-label">Left in your plan</span><span class="k-val ${leftToSpend < 0 ? 'neg' : ''}">${gbp0(leftToSpend)}</span><span class="k-foot">${leftToSpend > 0 ? `about ${gbp0(perDay)} a day to month end` : 'over this month’s plan'}</span></div>
    <div class="kpi"><span class="k-label">Net this month</span><span class="k-val ${m.net < 0 ? 'neg' : 'pos'}">${gbp0(m.net)}</span><span class="k-foot">savings rate ${m.income ? pct(Math.max(0, m.net) / m.income) : '—'}</span></div>
  </section>

  ${quick.length ? `<section class="card"><div class="card-head"><h2>Quick add</h2><span class="muted">Your most common entries. Tap, check the amount, save.</span></div>
    <div class="chips">${quick.map((q, i) => `<button class="chip" data-quick="${i}">${esc(icon(q.category))} ${esc(q.note)} <small>${gbp0(q.last)}</small></button>`).join('')}</div></section>` : ''}

  <section class="grid-2">
    <div class="card"><div class="card-head"><h2>Cash flow</h2><span class="legend"><i class="dot pos"></i>In <i class="dot neg"></i>Out</span></div>
      ${columns(flow.map((f) => ({ label: shortMonth(f.month), a: f.income, b: f.expense })), { fmt: gbpK })}</div>
    <div class="card"><div class="card-head"><h2>Where it went</h2><a href="#stats">Stats</a></div>
      ${cats.length ? `<ul class="bars">${cats.map((c, i) => `<li><span class="b-name">${esc(c.name)}</span><span class="b-track"><span style="width:${(c.total / catMax) * 100}%;background:${PALETTE[i]}"></span></span><span class="b-val">${gbp0(c.total)}</span></li>`).join('')}</ul>` : '<p class="muted">No spending logged this month.</p>'}</div>
  </section>

  <section class="card"><div class="card-head"><h2>Your financial dashboard</h2><span class="muted">From your plan, goals and accounts</span></div>
    <div class="dash">
      <a class="dash-item" href="#goals"><span class="k-label">Savings & investments</span><span class="k-val">${gbp0(si.now)}</span><span class="k-foot">${gbp0(si.in12)} in 12 months</span></a>
      <a class="dash-item" href="#accounts"><span class="k-label">Net worth</span><span class="k-val ${nw.net < 0 ? 'neg' : ''}">${gbp0(nw.net)}</span><span class="k-foot">${gbp0(nw.assets)} assets · ${gbp0(nw.liabilities)} owed</span></a>
      <a class="dash-item" href="#freedom"><span class="k-label">Financially free</span><span class="k-val">${fr.date ? monthYear(fr.date) : '—'}</span><span class="k-foot">target ${gbpK(fr.goal)} · ${pct(fr.progress)} there</span></a>
      <a class="dash-item" href="#debts"><span class="k-label">Debt free</span><span class="k-val">${debt ? (debt.date ? monthYear(debt.date) : 'Not yet') : 'No debts'}</span><span class="k-foot">${debt ? `${duration(debt.months)} · ${gbp0(debt.totalInterest)} interest` : 'add debts to plan payoff'}</span></a>
      <a class="dash-item" href="#plan"><span class="k-label">Monthly surplus (plan)</span><span class="k-val ${bud.surplus < 0 ? 'neg' : 'pos'}">${gbp0(bud.surplus)}</span><span class="k-foot">${pct(bud.pct.surplus)} of income</span></a>
    </div></section>

  <section class="card"><div class="card-head"><h2>Recent</h2><a href="#trans">All transactions</a></div>
    <div class="tx-list">${recent.map(txRow).join('') || '<p class="muted">No transactions yet. Tap + Add, or import your history.</p>'}</div></section>`;
}
function trend(now, before, upIsGood) {
  if (!before) return '<span class="muted">—</span>';
  const d = (now - before) / before;
  const good = upIsGood ? d >= 0 : d <= 0;
  return `<span class="${good ? 'pos' : 'neg'}">${d >= 0 ? '▲' : '▼'} ${pct(Math.abs(d))}</span>`;
}

export function dueList() {
  const t = C.today();
  return S().recurring.filter((r) => !r.auto && r.next && r.next <= t);
}

// ---------- TRANSACTIONS ----------
export function trans() {
  const ui = ctx.ui;
  ui.month ||= C.monthKey(C.today());
  ui.ttab ||= 'daily';
  const q = (ui.q || '').toLowerCase();
  const filt = (t) => (!ui.facct || t.accountId === ui.facct || t.toAccountId === ui.facct) && (!ui.fcat || t.category === ui.fcat) && (!ui.ftype || t.type === ui.ftype) &&
    (!q || `${t.note} ${t.description} ${t.category} ${t.subcategory} ${t.amount}`.toLowerCase().includes(q));
  const year = ui.month.slice(0, 4);
  const scope = ui.ttab === 'monthly' ? tx().filter((t) => t.date.startsWith(year)) : tx().filter((t) => C.monthKey(t.date) === ui.month);
  const list = scope.filter(filt).sort((a, b) => (b.date + (b.time || '')).localeCompare(a.date + (a.time || '')));
  const inc = C.sum(list.filter((t) => t.type === 'income'), (t) => t.amount), exp = C.sum(list.filter((t) => t.type === 'expense'), (t) => t.amount);
  const label = ui.ttab === 'monthly' ? year : monthLabel(ui.month);
  const s = S();
  let body = '';
  if (ui.ttab === 'daily') {
    const days = new Map();
    list.forEach((t) => { if (!days.has(t.date)) days.set(t.date, []); days.get(t.date).push(t); });
    body = [...days].map(([d, ts]) => {
      const di = C.sum(ts.filter((t) => t.type === 'income'), (t) => t.amount), de = C.sum(ts.filter((t) => t.type === 'expense'), (t) => t.amount);
      const dn = dayName(d);
      return `<div class="day"><div class="day-head"><span class="day-num">${d.slice(8)}</span><span class="day-name ${dn === 'Sun' ? 'sun' : dn === 'Sat' ? 'sat' : ''}">${dn}</span><span class="muted">${d.slice(5, 7)}.${d.slice(0, 4)}</span><span class="day-in pos">${di ? gbp(di) : ''}</span><span class="day-out neg">${de ? gbp(de) : ''}</span></div>${ts.map(txRow).join('')}</div>`;
    }).join('') || `<p class="empty">Nothing logged in ${esc(label)}${q || ui.fcat || ui.facct || ui.ftype ? ' that matches your filters' : ''}.</p>`;
  } else if (ui.ttab === 'calendar') {
    const [y, m] = ui.month.split('-').map(Number);
    const first = new Date(y, m - 1, 1).getDay(), dim = new Date(y, m, 0).getDate();
    const lead = (first + 6) % 7; // Monday first
    const cells = [];
    for (let i = 0; i < lead; i++) cells.push('<div class="cal-cell blank"></div>');
    for (let d = 1; d <= dim; d++) {
      const iso = `${ui.month}-${String(d).padStart(2, '0')}`;
      const ts = list.filter((t) => t.date === iso);
      const di = C.sum(ts.filter((t) => t.type === 'income'), (t) => t.amount), de = C.sum(ts.filter((t) => t.type === 'expense'), (t) => t.amount);
      cells.push(`<button class="cal-cell ${iso === C.today() ? 'today' : ''}" data-cal-day="${iso}"><span class="cal-d">${d}</span>${di ? `<span class="pos">${gbpK(di)}</span>` : ''}${de ? `<span class="neg">${gbpK(de)}</span>` : ''}</button>`);
    }
    const sel = ui.calDay && ui.calDay.startsWith(ui.month) ? list.filter((t) => t.date === ui.calDay) : [];
    body = `<div class="cal">${['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].map((d) => `<div class="cal-h">${d}</div>`).join('')}${cells.join('')}</div>
      ${ui.calDay && ui.calDay.startsWith(ui.month) ? `<h3 class="sub-h">${niceDate(ui.calDay)}</h3><div class="tx-list">${sel.map(txRow).join('') || '<p class="muted">Nothing on this day.</p>'}</div>` : '<p class="muted center">Tap a day to see its entries.</p>'}`;
  } else {
    const months = Array.from({ length: 12 }, (_, i) => `${year}-${String(12 - i).padStart(2, '0')}`);
    body = `<table class="table"><thead><tr><th>Month</th><th class="r">Income</th><th class="r">Expenses</th><th class="r">Total</th></tr></thead><tbody>${months.map((mk) => {
      const ts = list.filter((t) => C.monthKey(t.date) === mk);
      const a = C.sum(ts.filter((t) => t.type === 'income'), (t) => t.amount), b = C.sum(ts.filter((t) => t.type === 'expense'), (t) => t.amount);
      if (!a && !b) return '';
      return `<tr class="click" data-goto-month="${mk}"><td>${monthLabel(mk, true)}</td><td class="r pos">${gbp(a)}</td><td class="r neg">${gbp(b)}</td><td class="r ${a - b < 0 ? 'neg' : ''}">${gbp(a - b)}</td></tr>`;
    }).join('')}</tbody></table>`;
  }
  return `<header class="page-head"><div class="month-nav"><button class="icon-btn" data-month-step="-1" aria-label="Previous">‹</button><h1>${esc(label)}</h1><button class="icon-btn" data-month-step="1" aria-label="Next">›</button></div>
      <button class="btn primary" data-action="quick-add">+ Add</button></header>
    <div class="tabs" role="tablist">${['daily', 'calendar', 'monthly'].map((t) => `<button role="tab" class="tab ${ui.ttab === t ? 'on' : ''}" data-ttab="${t}">${t[0].toUpperCase() + t.slice(1)}</button>`).join('')}</div>
    <div class="totals"><div><span>Income</span><b class="pos">${gbp(inc)}</b></div><div><span>Expenses</span><b class="neg">${gbp(exp)}</b></div><div><span>Total</span><b>${gbp(inc - exp)}</b></div></div>
    <div class="filters">
      <input id="tx-search" class="in" type="search" placeholder="Search notes, payees, amounts" value="${esc(ui.q || '')}" data-ui="q" aria-label="Search">
      <select id="tx-ftype" class="in" data-ui="ftype" aria-label="Type"><option value="">All types</option>${['expense', 'income', 'transfer'].map((v) => `<option ${ui.ftype === v ? 'selected' : ''} value="${v}">${v[0].toUpperCase() + v.slice(1)}</option>`).join('')}</select>
      <select id="tx-facct" class="in" data-ui="facct" aria-label="Account"><option value="">All accounts</option>${s.accounts.map((a) => `<option ${ui.facct === a.id ? 'selected' : ''} value="${a.id}">${esc(a.name)}</option>`).join('')}</select>
      <select id="tx-fcat" class="in" data-ui="fcat" aria-label="Category"><option value="">All categories</option>${s.categories.map((c) => `<option ${ui.fcat === c.name ? 'selected' : ''} value="${esc(c.name)}">${esc(c.name)}</option>`).join('')}</select>
    </div>
    <section class="card flush">${body}</section>`;
}

// ---------- STATS ----------
export function periodRange() {
  const ui = ctx.ui;
  ui.speriod ||= 'monthly';
  ui.sanchor ||= C.today();
  const a = ui.sanchor;
  if (ui.speriod === 'weekly') {
    const dow = (new Date(a + 'T12:00').getDay() + 6) % 7;
    const from = C.addDays(a, -dow);
    return { from, to: C.addDays(from, 6), label: `${niceDate(from)} – ${niceDate(C.addDays(from, 6))}` };
  }
  if (ui.speriod === 'annually') return { from: `${a.slice(0, 4)}-01-01`, to: `${a.slice(0, 4)}-12-31`, label: a.slice(0, 4) };
  if (ui.speriod === 'period') return { from: ui.sfrom || C.addMonths(C.today(), -3), to: ui.sto || C.today(), label: 'Custom period' };
  if (ui.speriod === 'taxyear') { const t = C.taxYear(a); return { from: t.start, to: t.end, label: `Tax year ${t.label}` }; }
  const mk = C.monthKey(a);
  return { from: mk + '-01', to: mk + '-31', label: monthLabel(mk) };
}

export function stats() {
  const ui = ctx.ui;
  ui.stype ||= 'expense';
  const r = periodRange();
  const inRange = tx().filter((t) => t.date >= r.from && t.date <= r.to);
  const inc = C.sum(inRange.filter((t) => t.type === 'income'), (t) => t.amount);
  const exp = C.sum(inRange.filter((t) => t.type === 'expense'), (t) => t.amount);
  const cats = C.byCategory(tx(), { type: ui.stype, from: r.from, to: r.to });
  const total = C.sum(cats, (c) => c.total);
  const shown = topN(cats, 7);
  let drill = '';
  if (ui.scat) {
    const ts = inRange.filter((t) => t.type === ui.stype && t.category === ui.scat);
    const subs = new Map();
    ts.forEach((t) => { const k = t.subcategory || t.note || '(no note)'; subs.set(k, (subs.get(k) || 0) + C.num(t.amount)); });
    const rows = [...subs].sort((a, b) => b[1] - a[1]);
    const trend = C.lastNMonths(12, r.to).map((mk) => ({ mk, v: C.sum(tx().filter((t) => t.type === ui.stype && t.category === ui.scat && C.monthKey(t.date) === mk), (t) => t.amount) }));
    drill = `<section class="card"><div class="card-head"><h2>${esc(ui.scat)}</h2><button class="link" data-scat="">Close</button></div>
      <div class="grid-2"><div><h3 class="sub-h">Breakdown</h3><ul class="plain">${rows.slice(0, 15).map(([k, v]) => `<li><span>${esc(k)}</span><b>${gbp(v)}</b></li>`).join('')}</ul></div>
      <div><h3 class="sub-h">Last 12 months</h3>${lines([{ name: ui.scat, colour: 'var(--accent)', area: true, points: trend.map((x) => ({ v: x.v })) }], { fmt: gbpK, labels: trend.map((x) => shortMonth(x.mk)), height: 170 })}</div></div></section>`;
  }
  const flow = C.cashflow(tx(), C.lastNMonths(12, r.to));
  return `<header class="page-head"><div class="month-nav">${ui.speriod === 'period' ? '' : '<button class="icon-btn" data-period-step="-1" aria-label="Previous">‹</button>'}<h1>${esc(r.label)}</h1>${ui.speriod === 'period' ? '' : '<button class="icon-btn" data-period-step="1" aria-label="Next">›</button>'}</div>
    <select id="stats-period" class="in round" data-ui="speriod" aria-label="Period">${[['weekly', 'Weekly'], ['monthly', 'Monthly'], ['annually', 'Annually'], ['taxyear', 'Tax year'], ['period', 'Period']].map(([v, l]) => `<option value="${v}" ${ui.speriod === v ? 'selected' : ''}>${l}</option>`).join('')}</select></header>
    ${ui.speriod === 'period' ? `<div class="filters"><label class="lbl">From <input id="s-from" class="in" type="date" data-ui="sfrom" value="${r.from}"></label><label class="lbl">To <input id="s-to" class="in" type="date" data-ui="sto" value="${r.to}"></label></div>` : ''}
    <div class="tabs big"><button class="tab ${ui.stype === 'income' ? 'on' : ''}" data-stype="income">Income <b>${gbp(inc)}</b></button><button class="tab ${ui.stype === 'expense' ? 'on' : ''}" data-stype="expense">Expenses <b>${gbp(exp)}</b></button></div>
    <section class="card stats-card"><div class="donut-wrap">${donut(shown, { label: gbp0(total), sub: ui.stype === 'income' ? 'income' : 'spent' })}</div>
      <ul class="cat-list">${shown.map((c, i) => `<li><button class="cat-line" ${c.rest ? 'disabled' : `data-scat="${esc(c.name)}"`}><span class="badge" style="background:${PALETTE[i % PALETTE.length]}">${total ? Math.round((c.total / total) * 100) : 0}%</span><span class="cl-name">${esc(c.name)}</span><span class="cl-val">${gbp(c.total)}</span></button></li>`).join('') || '<li class="muted">Nothing in this period.</li>'}</ul></section>
    ${drill}
    <section class="card"><div class="card-head"><h2>12-month cash flow</h2><span class="legend"><i class="dot pos"></i>In <i class="dot neg"></i>Out</span></div>${columns(flow.map((f) => ({ label: shortMonth(f.month), a: f.income, b: f.expense })), { fmt: gbpK })}
      <p class="muted">Average month: ${gbp0(C.sum(flow, (f) => f.income) / 12)} in, ${gbp0(C.sum(flow, (f) => f.expense) / 12)} out.</p></section>`;
}

// ---------- ACCOUNTS & NET WORTH ----------
export function accounts() {
  const s = S();
  const nw = netWorth();
  const groups = ACCOUNT_GROUPS.map((g) => ({ ...g, list: s.accounts.filter((a) => a.group === g.id) })).filter((g) => g.list.length);
  const hist = s.networth.history.slice(-24);
  const edit = ctx.ui.editAccounts;
  return `<header class="page-head"><div><p class="eyebrow">Accounts</p><h1>Net worth ${gbp0(nw.net)}</h1></div>
    <button class="btn ghost" data-toggle-ui="editAccounts">${edit ? 'Done' : 'Edit accounts'}</button></header>
  <div class="totals"><div><span>Assets</span><b class="pos">${gbp(nw.assets)}</b></div><div><span>Liabilities</span><b class="neg">${gbp(nw.liabilities)}</b></div><div><span>Total</span><b>${gbp(nw.net)}</b></div></div>
  ${groups.map((g) => {
    const tot = C.sum(g.list, (a) => nw.bal[a.id]);
    return `<section class="card flush"><div class="group-head"><span>${esc(g.name)}</span><b class="${tot < 0 ? 'neg' : 'pos'}">${gbp(g.liability && tot < 0 ? -tot : tot)}${g.liability && tot < 0 ? ' owed' : ''}</b></div>
      ${g.list.map((a) => edit ? `<div class="acct-edit">
          ${field(`accounts|${a.id}|name`, a.name, { label: 'Account name' })}
          ${select(`accounts|${a.id}|group`, a.group, ACCOUNT_GROUPS.map((x) => [x.id, x.name]), { label: 'Group' })}
          <label class="lbl">Opening ${field(`accounts|${a.id}|opening`, a.opening, { type: 'money', label: 'Opening balance' })}</label>
          <label class="lbl">Set today's balance <input id="setbal-${a.id}" class="in" type="number" step="0.01" inputmode="decimal" data-setbal="${a.id}" placeholder="${C.round2(nw.bal[a.id])}"></label>
          ${s.transactions.some((t) => t.accountId === a.id || t.toAccountId === a.id) ? '' : delBtn('accounts', a.id, 'account')}</div>`
        : `<a class="acct-row" href="#trans" data-filter-acct="${a.id}"><span>${esc(a.name)}</span><b class="${nw.bal[a.id] < 0 ? 'neg' : 'pos'}">${gbp(nw.bal[a.id])}</b></a>`).join('')}</section>`;
  }).join('')}
  ${edit ? `<div class="row-btns">${addBtn('accounts', 'Add account', { name: 'New account', group: 'bank', opening: 0 })}<span class="muted">Tip: type the balance your banking app shows into “Set today's balance” and the opening balance is worked out for you.</span></div>` : ''}

  <section class="grid-2">
    <div class="card"><div class="card-head"><h2>Other assets</h2><span class="muted">House, car, jewellery…</span></div>
      ${s.networth.assets.map((x) => `<div class="line-edit">${field(`networth.assets|${x.id}|name`, x.name, { label: 'Asset' })}${field(`networth.assets|${x.id}|value`, x.value, { type: 'money', label: 'Value' })}${delBtn('networth.assets', x.id)}</div>`).join('')}
      ${addBtn('networth.assets', 'Add asset', { name: '', value: 0 })}</div>
    <div class="card"><div class="card-head"><h2>Other liabilities</h2><span class="muted">Mortgage, loans not tracked above</span></div>
      ${s.networth.liabilities.map((x) => `<div class="line-edit">${field(`networth.liabilities|${x.id}|name`, x.name, { label: 'Liability' })}${field(`networth.liabilities|${x.id}|value`, x.value, { type: 'money', label: 'Balance' })}${delBtn('networth.liabilities', x.id)}</div>`).join('')}
      ${addBtn('networth.liabilities', 'Add liability', { name: '', value: 0 })}</div>
  </section>
  <section class="card"><div class="card-head"><h2>Net worth over time</h2><button class="btn small" data-action="snapshot">Save this month</button></div>
    ${hist.length >= 2 ? lines([{ name: 'Net worth', colour: 'var(--accent)', area: true, points: hist.map((h) => ({ v: Math.max(0, h.net) })) }], { fmt: gbpK, labels: hist.map((h) => monthYear(h.date)) }) : `<p class="muted">Tap “Save this month” once a month to build your net worth history${hist.length ? ` (1 saved: ${monthYear(hist[0].date)}, ${gbp0(hist[0].net)})` : ''}.</p>`}</section>`;
}
