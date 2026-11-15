// AutoPilot plan, goals, investments, debts, financial freedom, recurring payments.
import * as C from './calc.js';
import { lines, meter } from './charts.js';
import * as I from './insights.js';
import * as H from './halal.js';
import { ctx, esc, gbp, gbp0, gbpK, pct, niceDate, monthYear, duration, field, select, delBtn, addBtn, catsOf, accountName } from './ui.js';

const S = () => ctx.state;
const GROUPS = [['fixed', 'Fixed essentials', 'Bills that are the same every month'], ['variable', 'Variable essentials', 'Needed, but the amount moves'], ['optional', 'Optional lifestyle', 'Nice to have']];

// ---------- PLAN (AutoPilot Money) ----------
export function plan() {
  const s = S(), b = s.budget, sum = C.budgetSummary(b);
  const mk = C.monthKey(C.today());
  const catOpts = [['', '— not linked —'], ...catsOf('expense').map((c) => [c, c])];
  // plan vs actual by linked category
  const planned = new Map();
  b.items.forEach((i) => { if (i.category) planned.set(i.category, (planned.get(i.category) || 0) + C.num(i.amount)); });
  const actual = new Map(C.byCategory(s.transactions, { from: mk + '-01', to: mk + '-31' }).map((c) => [c.name, c.total]));
  const dom = Number(C.today().slice(8)), dim = new Date(+mk.slice(0, 4), +mk.slice(5), 0).getDate();
  const vs = [...new Set([...planned.keys(), ...actual.keys()])].map((c) => ({ c, p: planned.get(c) || 0, a: actual.get(c) || 0 })).sort((x, y) => y.p - x.p || y.a - x.a);

  return `<header class="page-head"><div><p class="eyebrow">AutoPilot plan</p><h1>Monthly budget</h1></div><button class="btn ghost" data-toggle-ui="budgetSuggest">${ctx.ui.budgetSuggest ? 'Close' : 'Build from my real spending'}</button></header>
  ${ctx.ui.budgetSuggest ? suggestCard() : ''}
  <section class="kpis">
    <div class="kpi"><span class="k-label">Monthly income</span><span class="k-val pos">${gbp0(sum.income)}</span></div>
    <div class="kpi"><span class="k-label">Planned outgoings</span><span class="k-val neg">${gbp0(sum.outgoing)}</span><span class="k-foot">${pct(sum.outgoing / (sum.income || 1))} of income</span></div>
    <div class="kpi"><span class="k-label">Money left</span><span class="k-val ${sum.surplus < 0 ? 'neg' : 'pos'}">${gbp0(sum.surplus)}</span><span class="k-foot">${pct(sum.pct.surplus)} of income</span></div>
    <div class="kpi"><span class="k-label">Not yet given a job</span><span class="k-val ${sum.unallocated < 0 ? 'neg' : ''}">${gbp0(sum.unallocated)}</span><span class="k-foot">surplus minus allocations</span></div>
  </section>
  <section class="card"><div class="split-bar">${[['fixed', sum.fixed], ['variable', sum.variable], ['optional', sum.optional], ['surplus', Math.max(0, sum.surplus)]].map(([k, v]) => `<span class="sb-${k}" style="flex:${Math.max(0.0001, v)}" title="${k} ${gbp0(v)}"></span>`).join('')}</div>
    <div class="legend wrap"><span><i class="dot sb-fixed"></i>Fixed ${pct(sum.pct.fixed)}</span><span><i class="dot sb-variable"></i>Variable ${pct(sum.pct.variable)}</span><span><i class="dot sb-optional"></i>Optional ${pct(sum.pct.optional)}</span><span><i class="dot sb-surplus"></i>Surplus ${pct(Math.max(0, sum.pct.surplus))}</span></div></section>

  <section class="card"><div class="card-head"><h2>Income</h2><b class="pos">${gbp(sum.income)}</b></div>
    ${b.income.map((i) => `<div class="line-edit">${field(`budget.income|${i.id}|name`, i.name, { label: 'Income source' })}${field(`budget.income|${i.id}|amount`, i.amount, { type: 'money', label: 'Monthly amount' })}${delBtn('budget.income', i.id)}</div>`).join('')}
    ${addBtn('budget.income', 'Add income', { name: '', amount: 0 })}
    <p class="muted">Use a typical month. Weekly figures: multiply by 52 and divide by 12 (£800 a week ≈ £3,467 a month).</p></section>

  <div class="grid-3">${GROUPS.map(([g, title, hint]) => {
    const items = b.items.filter((i) => i.group === g);
    const sections = [...new Set(items.map((i) => i.section))];
    return `<section class="card"><div class="card-head"><h2>${title}</h2><b>${gbp(C.sum(items, (i) => i.amount))}</b></div><p class="muted">${hint}</p>
      ${sections.map((sec) => `<h3 class="sub-h">${esc(sec)} <span class="muted">${gbp0(C.sum(items.filter((i) => i.section === sec), (i) => i.amount))}</span></h3>
        ${items.filter((i) => i.section === sec).map((i) => `<div class="budget-line">${field(`budget.items|${i.id}|name`, i.name, { label: 'Item' })}${field(`budget.items|${i.id}|amount`, i.amount, { type: 'money', label: 'Monthly cost' })}${select(`budget.items|${i.id}|category`, i.category, catOpts, { label: 'Linked category', cls: 'mini' })}${delBtn('budget.items', i.id)}</div>`).join('')}
        ${addBtn('budget.items', `Add to ${sec}`, { group: g, section: sec, name: '', amount: 0, category: '' })}`).join('')}
      <div class="add-section"><input id="newsec-${g}" class="in" placeholder="New section, e.g. Kids" aria-label="New section name"><button class="btn small" data-add-section="${g}">Add section</button></div></section>`;
  }).join('')}</div>

  <section class="card"><div class="card-head"><h2>Give your surplus a job</h2><span class="muted">${gbp(sum.surplus)} to allocate</span></div>
    ${b.allocation.map((a) => `<div class="line-edit">${field(`budget.allocation|${a.id}|name`, a.name, { label: 'Destination' })}${field(`budget.allocation|${a.id}|amount`, a.amount, { type: 'money', label: 'Monthly amount' })}<span class="muted r">${pct(C.num(a.amount) / (sum.income || 1))} of income</span>${delBtn('budget.allocation', a.id)}</div>`).join('')}
    ${addBtn('budget.allocation', 'Add destination', { name: '', amount: 0 })}</section>

  <section class="card"><div class="card-head"><h2>This month: plan vs actual</h2><span class="muted">Day ${dom} of ${dim} · ${pct(dom / dim)} of the month gone</span></div>
    <p class="muted">Link budget lines to a category (the small dropdown) to compare them with what you actually spent.</p>
    <ul class="vs">${vs.map((r) => { const f = r.p ? r.a / r.p : r.a ? 1.01 : 0; const cls = f > 1 ? 'over' : f > dom / dim + 0.1 ? 'warn' : 'ok'; return `<li><span class="vs-name">${esc(r.c)}</span>${meter(Math.min(1, f), cls)}<span class="vs-num"><b class="${f > 1 ? 'neg' : ''}">${gbp0(r.a)}</b> of ${r.p ? gbp0(r.p) : 'no plan'}</span></li>`; }).join('') || '<li class="muted">No spending this month yet.</li>'}</ul></section>`;
}

function suggestCard() {
  const sg = I.suggestBudget(S().transactions);
  if (!sg.months.length) return '<section class="card highlight"><p class="muted">There is no history in the last three full months yet. Log or import some first.</p></section>';
  const list = (rows) => `<ul class="plain">${rows.map((r) => `<li><span>${esc(r.category)}</span><b>${gbp0(r.monthly)}</b></li>`).join('')}</ul>`;
  return `<section class="card highlight"><div class="card-head"><h2>Your real monthly averages</h2><span class="muted">From ${sg.months.length} full month${sg.months.length > 1 ? 's' : ''} of history</span></div>
    <div class="grid-2"><div><h3 class="sub-h">Income <span class="muted">${gbp0(C.sum(sg.income, (r) => r.monthly))}</span></h3>${list(sg.income)}</div><div><h3 class="sub-h">Spending <span class="muted">${gbp0(C.sum(sg.expenses, (r) => r.monthly))}</span></h3>${list(sg.expenses)}</div></div>
    <p class="nudge warn">Using these replaces the income and budget lines in your current plan. Your “give your surplus a job” list is kept.</p>
    <div class="row-btns"><button class="btn primary" data-action="budget-apply">Use these as my plan</button><button class="btn ghost" data-toggle-ui="budgetSuggest">Cancel</button></div></section>`;
}

// ---------- GOALS ----------
export function goals() {
  const s = S(), g = s.goals;
  const sinking = g.sinking.map((f) => ({ f, r: C.sinkingFund(f) }));
  const invNow = C.sum(g.investments, (i) => i.value), inv12 = C.sum(g.investments, (i) => C.futureValue(i.value, i.monthly, i.rate, 12));
  const saved = C.sum(g.sinking, (f) => f.saved), target = C.sum(g.sinking, (f) => f.target);
  const years = 10;
  const labels = Array.from({ length: years + 1 }, (_, i) => `Y${i}`);
  const proj = Array.from({ length: years + 1 }, (_, y) => ({ v: C.sum(g.investments, (i) => C.futureValue(i.value, i.monthly, i.rate, y * 12)) }));
  const paid = Array.from({ length: years + 1 }, (_, y) => ({ v: C.sum(g.investments, (i) => C.num(i.value) + C.num(i.monthly) * 12 * y) }));
  return `<header class="page-head"><div><p class="eyebrow">Goals</p><h1>Savings & investment goals</h1></div></header>
  <section class="kpis">
    <div class="kpi"><span class="k-label">Saved towards goals</span><span class="k-val">${gbp0(saved)}</span><span class="k-foot">of ${gbp0(target)} (${pct(target ? saved / target : 0)})</span></div>
    <div class="kpi"><span class="k-label">Going into goals monthly</span><span class="k-val">${gbp0(C.sum(g.sinking, (f) => f.monthly) + C.sum(g.investments, (i) => i.monthly))}</span></div>
    <div class="kpi"><span class="k-label">Investments now</span><span class="k-val">${gbp0(invNow)}</span></div>
    <div class="kpi"><span class="k-label">Investments in 12 months</span><span class="k-val pos">${gbp0(inv12)}</span></div>
  </section>
  <section class="card"><div class="card-head"><h2>Sinking funds</h2><span class="muted">Save for known costs: Eid, car, insurance, holidays</span></div>
    <div class="table-wrap"><table class="table edit"><thead><tr><th>Goal</th><th>Target</th><th>By</th><th>Saved</th><th>Monthly</th><th>Progress</th><th class="r">Need / month</th><th></th></tr></thead><tbody>
    ${sinking.map(({ f, r }) => `<tr><td>${field(`goals.sinking|${f.id}|name`, f.name, { label: 'Goal' })}</td><td>${field(`goals.sinking|${f.id}|target`, f.target, { type: 'money', label: 'Target' })}</td><td>${field(`goals.sinking|${f.id}|date`, f.date, { type: 'date', label: 'Target date' })}</td><td>${field(`goals.sinking|${f.id}|saved`, f.saved, { type: 'money', label: 'Saved so far' })}</td><td>${field(`goals.sinking|${f.id}|monthly`, f.monthly, { type: 'money', label: 'Monthly saving' })}</td>
      <td class="prog">${meter(r.pct, r.onTrack ? 'ok' : r.overdue ? 'over' : 'warn')}<small>${pct(r.pct)} now · ${pct(r.byDatePct)} by ${monthYear(f.date)} ${r.onTrack ? '<span class="pill ok">on track</span>' : r.overdue ? '<span class="pill over">date passed</span>' : '<span class="pill warn">short</span>'}</small></td>
      <td class="r"><b>${gbp0(r.needPerMonth)}</b><small class="muted">${r.months} months left</small></td><td>${delBtn('goals.sinking', f.id)}</td></tr>`).join('')}
    </tbody></table></div>${addBtn('goals.sinking', 'Add sinking fund', { name: '', target: 1000, date: C.addMonths(C.today(), 12), saved: 0, monthly: 0 })}</section>

  <section class="card"><div class="card-head"><h2>Savings & investment pots</h2><span class="muted">Growth is an assumption, not a promise</span></div>
    <div class="table-wrap"><table class="table edit"><thead><tr><th>Pot</th><th>Value now</th><th>Monthly in</th><th>Growth / yr</th><th class="r">12 months</th><th class="r">10 years</th><th class="r">+10% contribution</th><th></th></tr></thead><tbody>
    ${g.investments.map((i) => `<tr><td>${field(`goals.investments|${i.id}|name`, i.name, { label: 'Pot' })}</td><td>${field(`goals.investments|${i.id}|value`, i.value, { type: 'money', label: 'Value' })}</td><td>${field(`goals.investments|${i.id}|monthly`, i.monthly, { type: 'money', label: 'Monthly' })}</td><td>${field(`goals.investments|${i.id}|rate`, i.rate, { type: 'pct', label: 'Growth %' })}</td>
      <td class="r">${gbp0(C.futureValue(i.value, i.monthly, i.rate, 12))}</td><td class="r"><b>${gbp0(C.futureValue(i.value, i.monthly, i.rate, 120))}</b></td><td class="r muted">${gbp0(C.futureValue(i.value, C.num(i.monthly) * 1.1, i.rate, 120))}</td><td>${delBtn('goals.investments', i.id)}</td></tr>`).join('')}
    </tbody></table></div>${addBtn('goals.investments', 'Add pot', { name: '', value: 0, monthly: 0, rate: 0.05 })}
    ${g.investments.length ? `<h3 class="sub-h">Next 10 years</h3><div class="legend"><span><i class="dot acc"></i>With growth</span><span><i class="dot mut"></i>Just what you put in</span></div>${lines([{ name: 'Paid in', colour: 'var(--muted)', dash: true, points: paid }, { name: 'With growth', colour: 'var(--accent)', area: true, points: proj }], { fmt: gbpK, labels })}` : ''}</section>`;
}

// ---------- INVESTMENTS ----------
export function invest() {
  const s = S(), ty = C.taxYear();
  const hs = s.holdings.map((h) => ({ h, value: C.num(h.units) * C.num(h.price) }));
  const total = C.sum(hs, (x) => x.value), cost = C.sum(s.holdings, (h) => h.cost);
  const byWrap = new Map();
  hs.forEach(({ h, value }) => byWrap.set(h.wrapper || 'Other', (byWrap.get(h.wrapper || 'Other') || 0) + value));
  const isaIn = C.sum(s.holdings.filter((h) => /ISA/i.test(h.wrapper) && !/LISA/i.test(h.wrapper)), (h) => h.contributedThisYear) + C.sum(s.holdings.filter((h) => /LISA/i.test(h.wrapper)), (h) => h.contributedThisYear);
  const lisaIn = C.sum(s.holdings.filter((h) => /LISA/i.test(h.wrapper)), (h) => h.contributedThisYear);
  const wrappers = ['S&S ISA', 'Cash ISA', 'LISA', 'SIPP', 'Workplace pension', 'GIA', 'Crypto', 'Other'];
  return `<header class="page-head"><div><p class="eyebrow">Investments</p><h1>Portfolio ${gbp0(total)}</h1></div></header>
  <section class="kpis">
    <div class="kpi"><span class="k-label">Value</span><span class="k-val">${gbp0(total)}</span></div>
    <div class="kpi"><span class="k-label">Paid in</span><span class="k-val">${gbp0(cost)}</span></div>
    <div class="kpi"><span class="k-label">Gain / loss</span><span class="k-val ${total - cost < 0 ? 'neg' : 'pos'}">${gbp0(total - cost)}</span><span class="k-foot">${cost ? pct((total - cost) / cost, 1) : '—'}</span></div>
    <div class="kpi"><span class="k-label">ISA allowance ${ty.label}</span><span class="k-val">${gbp0(Math.max(0, C.ISA_ALLOWANCE - isaIn))} left</span>${meter(isaIn / C.ISA_ALLOWANCE, isaIn > C.ISA_ALLOWANCE ? 'over' : 'ok')}<span class="k-foot">${gbp0(isaIn)} of ${gbp0(C.ISA_ALLOWANCE)} used · resets 6 April</span></div>
  </section>
  ${lisaIn ? `<p class="muted">Lifetime ISA: ${gbp0(lisaIn)} of ${gbp0(C.LISA_ALLOWANCE)} paid in this tax year (counts towards the £20,000).</p>` : ''}
  <section class="card"><div class="card-head"><h2>Holdings</h2><span class="muted">Update prices from your platform app; live prices can be added later</span></div>
    <div class="table-wrap"><table class="table edit"><thead><tr><th>Holding</th><th>Ticker</th><th>Account</th><th>Units</th><th>Price £</th><th>Paid in £</th><th>Added this tax year £</th><th>Shariah status</th><th>Dividends this year £</th><th>Non-compliant income %</th><th class="r">Value</th><th class="r">Gain</th><th></th></tr></thead><tbody>
    ${hs.map(({ h, value }) => `<tr><td>${field(`holdings|${h.id}|name`, h.name, { label: 'Holding' })}</td><td>${field(`holdings|${h.id}|ticker`, h.ticker, { cls: 'short', label: 'Ticker' })}</td><td>${select(`holdings|${h.id}|wrapper`, h.wrapper, wrappers, { label: 'Account type' })}</td>
      <td>${field(`holdings|${h.id}|units`, h.units, { type: 'number', step: 'any', label: 'Units' })}</td><td>${field(`holdings|${h.id}|price`, h.price, { type: 'money', step: 'any', label: 'Price' })}</td><td>${field(`holdings|${h.id}|cost`, h.cost, { type: 'money', label: 'Paid in' })}</td><td>${field(`holdings|${h.id}|contributedThisYear`, h.contributedThisYear, { type: 'money', label: 'Added this tax year' })}</td>
      <td>${select(`holdings|${h.id}|halal`, h.halal || 'unchecked', [['unchecked', 'Not checked'], ['compliant', 'Compliant'], ['doubtful', 'Doubtful'], ['not', 'Not compliant']], { label: 'Shariah status' })}</td><td>${field(`holdings|${h.id}|dividends`, h.dividends || 0, { type: 'money', label: 'Dividends this year' })}</td><td>${field(`holdings|${h.id}|nonCompliantPct`, h.nonCompliantPct || 0, { type: 'number', step: '0.1', label: 'Non-compliant income %' })}</td>
      <td class="r"><b>${gbp0(value)}</b></td><td class="r ${value - C.num(h.cost) < 0 ? 'neg' : 'pos'}">${gbp0(value - C.num(h.cost))}</td><td>${delBtn('holdings', h.id)}</td></tr>`).join('') || '<tr><td colspan="13" class="muted">No holdings yet. Add your first one below.</td></tr>'}
    </tbody></table></div>${addBtn('holdings', 'Add holding', { name: '', ticker: '', wrapper: 'S&S ISA', units: 0, price: 0, cost: 0, contributedThisYear: 0, halal: 'unchecked', dividends: 0, nonCompliantPct: 0 })}</section>
  <section class="grid-2">
    <div class="card"><div class="card-head"><h2>By account type</h2></div><ul class="plain">${[...byWrap].map(([k, v]) => `<li><span>${esc(k)}</span><b>${gbp0(v)} <small class="muted">${pct(total ? v / total : 0)}</small></b></li>`).join('') || '<li class="muted">—</li>'}</ul></div>
    <div class="card"><div class="card-head"><h2>Getting started again</h2></div><ul class="ticks">
      <li>Emergency fund first: 3 to 6 months of essentials (${gbp0(C.budgetSummary(s.budget).fixed * 3)} to ${gbp0(C.budgetSummary(s.budget).fixed * 6)} on your plan).</li>
      <li>Clear high-interest debt before investing. Card interest usually beats market returns.</li>
      <li>Use the ISA allowance first: no tax on growth or withdrawals.</li>
      <li>Pension (SIPP) gets tax relief: £80 in becomes £100.</li>
      <li>Low-cost global index funds keep fees down. Check the platform fee too.</li></ul>
      <p class="muted">General information, not financial advice.</p></div>
  </section>
  ${halalSections(total)}`;
}

// ---------- HALAL INVESTING ----------
function halalSections(total) {
  const s = S(), z = s.zakat;
  const bal = C.accountBalances(s.accounts, s.transactions);
  const cash = C.sum(s.accounts.filter((a) => ['cash', 'bank', 'savings'].includes(a.group)), (a) => Math.max(0, bal[a.id] || 0));
  const zk = H.zakat(z, { cash, investments: total });
  const pur = H.purification(s.holdings);
  const comp = H.compliance(s.holdings);
  const sc = ctx.ui.screen || {};
  const res = H.screen(sc);
  const riba = s.debts.items.filter((d) => C.num(d.rate) > 0);
  return `<header class="page-head sub-page"><div><p class="eyebrow">Halal investing</p><h2 class="h1-like">Invest the halal way</h2></div></header>
  <section class="kpis">
    <div class="kpi"><span class="k-label">Portfolio checked as compliant</span><span class="k-val">${pct(comp.pct)}</span><span class="k-foot">${gbp0(comp.by.compliant)} of ${gbp0(comp.total)}${comp.by.unchecked ? ` · ${gbp0(comp.by.unchecked)} not checked yet` : ''}</span></div>
    <div class="kpi"><span class="k-label">Zakat due</span><span class="k-val">${zk.priced ? gbp(zk.amount) : '—'}</span><span class="k-foot">${zk.priced ? (zk.due ? `2.5% of ${gbp0(zk.net)}` : `below the nisab of ${gbp0(zk.nisab)}`) : 'enter today\'s price per gram below'}</span></div>
    <div class="kpi"><span class="k-label">To purify (give away)</span><span class="k-val">${gbp(pur.total)}</span><span class="k-foot">non-compliant share of dividends</span></div>
    <div class="kpi"><span class="k-label">Interest-bearing debts</span><span class="k-val ${riba.length ? 'neg' : ''}">${riba.length}</span><span class="k-foot">${riba.length ? 'clearing riba comes before investing' : 'none in the debt planner'}</span></div>
  </section>
  <section class="grid-2">
    <div class="card"><div class="card-head"><h2>Your halal roadmap</h2></div><ul class="ticks">
      <li>Get rid of interest (riba): clear credit cards and loans with interest first. ${riba.length ? `You have ${riba.length} in the <a href="#debts">debt planner</a>.` : ''}</li>
      <li>Keep an emergency fund in a non-interest account or an Islamic savings account (expected profit rate instead of interest).</li>
      <li>Use your £20,000 Stocks &amp; Shares ISA allowance with Shariah-screened funds, so growth is tax-free.</li>
      <li>Prefer broad Islamic index funds over picking single shares: screening is done for you and risk is spread.</li>
      <li>For a pension, check whether your provider offers a Shariah fund or use a halal SIPP.</li>
      <li>Each year: work out zakat on your wealth and purify any non-compliant dividend income.</li></ul>
      <p class="muted small-print">Scholars differ on details (for example how much of a fund counts for zakat). Check with a scholar you trust. Not financial or religious advice.</p></div>
    <div class="card"><div class="card-head"><h2>Halal shortlist</h2><span class="muted">Options to research</span></div>
      ${s.halalShortlist.map((x) => `<div class="short-row"><div><b>${esc(x.name)}</b>${x.ticker ? ` <span class="pill">${esc(x.ticker)}</span>` : ''}<small class="muted">${esc(x.kind)} · ${esc(x.note)}</small></div>${select(`halalShortlist|${x.id}|status`, x.status, [['researching', 'Researching'], ['chosen', 'Chosen'], ['own', 'I own this'], ['no', 'Not for me']], { label: 'Status', cls: 'mini' })}${delBtn('halalShortlist', x.id)}</div>`).join('')}
      ${addBtn('halalShortlist', 'Add option', { name: 'New option', ticker: '', kind: '', note: '', status: 'researching' })}
      <p class="muted small-print">Fees, tickers and which platforms carry them change. Confirm on the fund's factsheet before buying.</p></div>
  </section>
  <section class="card"><div class="card-head"><h2>Zakat calculator</h2><span class="muted">2.5% of zakatable wealth held for a lunar year, if it is above the nisab</span></div>
    <div class="form-grid">
      <label class="lbl">Nisab measured by ${select('zakat||metal', z.metal, [['silver', 'Silver (612.36 g) · lower threshold'], ['gold', 'Gold (87.48 g)']], { label: 'Nisab metal' })}</label>
      <label class="lbl">Today's ${z.metal} price per gram £ ${field('zakat||gramPrice', z.gramPrice, { type: 'money', step: '0.01', label: 'Price per gram' })}</label>
      <label class="lbl">Gold you own (value £) ${field('zakat||gold', z.gold, { type: 'money', label: 'Gold value' })}</label>
      <label class="lbl">Silver you own (value £) ${field('zakat||silver', z.silver, { type: 'money', label: 'Silver value' })}</label>
      <label class="lbl">Cash not in the app £ ${field('zakat||extraCash', z.extraCash, { type: 'money', label: 'Other cash' })}</label>
      <label class="lbl">Share of investments counted % ${field('zakat||investPct', z.investPct, { type: 'pct', label: 'Investment share counted' })}</label>
      <label class="lbl">Money owed to you £ ${field('zakat||owedToYou', z.owedToYou, { type: 'money', label: 'Owed to you' })}</label>
      <label class="lbl">Debts due now £ ${field('zakat||debtsDue', z.debtsDue, { type: 'money', label: 'Debts due' })}</label>
      <label class="lbl">Your zakat date ${field('zakat||dueDate', z.dueDate, { type: 'date', label: 'Zakat date' })}</label>
    </div>
    <ul class="plain">${zk.parts.map((p) => `<li><span>${esc(p.label)}</span><b class="${p.value < 0 ? 'neg' : ''}">${p.value < 0 ? '−' + gbp(-p.value) : gbp(p.value)}</b></li>`).join('')}<li><span><b>Zakatable wealth</b></span><b>${gbp(zk.net)}</b></li></ul>
    <p>${zk.priced ? (zk.due ? `Your wealth is above the nisab of <b>${gbp0(zk.nisab)}</b>. Zakat due: <b>${gbp(zk.amount)}</b>.` : `Your wealth is below the nisab of <b>${gbp0(zk.nisab)}</b>, so no zakat is due on these figures.`) : 'Enter today\'s price per gram to see the nisab.'}</p>
    <p class="muted small-print">Cash counts from your Cash, Accounts and Savings balances in the app (${gbp0(cash)}). For long-term index funds many scholars count about 25% (the companies' zakatable assets); count 100% for shares you trade. Your investments: ${gbp0(total)}.</p></section>
  <section class="grid-2">
    <div class="card"><div class="card-head"><h2>Check a company (AAOIFI ratios)</h2></div>
      <p class="muted">Copy figures from the company's latest accounts or a screening app such as Zoya or Musaffa.</p>
      <div class="form-grid">
        <label class="lbl">Market value £ <input id="sc-cap" class="in" type="number" inputmode="decimal" data-ui-num="screen.marketCap" value="${esc(sc.marketCap ?? '')}"></label>
        <label class="lbl">Interest-bearing debt £ <input id="sc-debt" class="in" type="number" inputmode="decimal" data-ui-num="screen.debt" value="${esc(sc.debt ?? '')}"></label>
        <label class="lbl">Cash + interest deposits £ <input id="sc-cash" class="in" type="number" inputmode="decimal" data-ui-num="screen.cashAndInterest" value="${esc(sc.cashAndInterest ?? '')}"></label>
        <label class="lbl">Non-compliant income % <input id="sc-inc" class="in" type="number" inputmode="decimal" step="0.1" data-ui-num="screen.nonCompliantIncomePct" value="${esc(sc.nonCompliantIncomePct ?? '')}"></label>
      </div>
      <ul class="plain">${res.tests.map((t) => `<li><span>${esc(t.name)}</span><b class="${t.pass === false ? 'neg' : t.pass ? 'pos' : 'muted'}">${t.value == null ? '—' : `${(t.value * 100).toFixed(1)}% ${t.pass ? '✓' : '✗'}`}</b></li>`).join('')}</ul>
      <p>${res.verdict === 'pass' ? '<span class="pill ok">Passes the financial screens</span> Also check the business itself is permissible (no alcohol, gambling, conventional banking, etc.).' : res.verdict === 'fail' ? '<span class="pill over">Fails a screen</span> Not compliant on these figures.' : '<span class="muted">Fill in all four figures to get a result.</span>'}</p></div>
    <div class="card"><div class="card-head"><h2>Dividend purification</h2></div>
      <p class="muted">Set each holding's dividends and its non-compliant income % in the holdings table above. That share of the dividends is given to charity (not counted as sadaqah).</p>
      ${pur.rows.length ? `<ul class="plain">${pur.rows.map((r) => `<li><span>${esc(r.name)}</span><b>${gbp(r.amount)}</b></li>`).join('')}<li><span><b>Total to purify</b></span><b>${gbp(pur.total)}</b></li></ul>` : '<p class="muted">Nothing to purify yet.</p>'}</div>
  </section>`;
}

// ---------- DEBTS ----------
export function debts() {
  const s = S(), d = s.debts;
  const av = C.debtPlan(d.items, d.budget, 'avalanche'), sn = C.debtPlan(d.items, d.budget, 'snowball');
  const p = d.method === 'snowball' ? sn : av, other = d.method === 'snowball' ? av : sn;
  const totalDebt = C.sum(d.items, (x) => x.balance);
  const step = Math.max(1, Math.ceil(p.schedule.length / 24));
  const pts = [{ v: totalDebt }, ...p.schedule.filter((_, i) => i % step === 0 || i === p.schedule.length - 1).map((r) => ({ v: r.total }))];
  const lbl = ['Now', ...p.schedule.filter((_, i) => i % step === 0 || i === p.schedule.length - 1).map((r) => monthYear(r.date))];
  const showAll = ctx.ui.debtAll;
  return `<header class="page-head"><div><p class="eyebrow">Debt payoff</p><h1>${!totalDebt ? (d.items.length ? 'Add your balances' : 'Debt planner') : p.date ? `Debt free by ${monthYear(p.date)}` : 'Not paying off at this rate'}</h1></div></header>
  <section class="kpis">
    <div class="kpi"><span class="k-label">Total owed</span><span class="k-val neg">${gbp0(totalDebt)}</span></div>
    <div class="kpi"><span class="k-label">Minimum payments</span><span class="k-val">${gbp0(p.minTotal)}</span>${C.num(d.budget) < p.minTotal ? '<span class="k-foot neg">Your budget is below the minimums, so the minimums are used</span>' : ''}</div>
    <div class="kpi"><span class="k-label">Time to debt free</span><span class="k-val">${duration(p.months)}</span></div>
    <div class="kpi"><span class="k-label">Interest you'll pay</span><span class="k-val neg">${gbp0(p.totalInterest)}</span><span class="k-foot">${other.months != null && p.months != null ? `${d.method === 'avalanche' ? 'Snowball' : 'Avalanche'}: ${gbp0(other.totalInterest)}, ${duration(other.months)}` : ''}</span></div>
  </section>
  <section class="card"><div class="card-head"><h2>Your debts</h2></div>
    <div class="table-wrap"><table class="table edit"><thead><tr><th>Debt</th><th>Balance</th><th>Interest % (APR)</th><th>Minimum / month</th><th class="r">Paid off</th><th class="r">Interest</th><th></th></tr></thead><tbody>
    ${d.items.map((x) => { const r = p.debts.find((y) => y.id === x.id); return `<tr><td>${field(`debts.items|${x.id}|name`, x.name, { label: 'Debt' })}</td><td>${field(`debts.items|${x.id}|balance`, x.balance, { type: 'money', label: 'Balance' })}</td><td>${field(`debts.items|${x.id}|rate`, x.rate, { type: 'number', step: '0.1', label: 'APR %' })}</td><td>${field(`debts.items|${x.id}|min`, x.min, { type: 'money', label: 'Minimum' })}</td>
      <td class="r">${r?.paidOffDate ? monthYear(r.paidOffDate) : '—'}</td><td class="r">${r ? gbp0(r.interest) : '—'}</td><td>${delBtn('debts.items', x.id)}</td></tr>`; }).join('') || '<tr><td colspan="7" class="muted">No debts added.</td></tr>'}
    </tbody></table></div>${addBtn('debts.items', 'Add debt', { name: '', balance: 0, rate: 0, min: 0 })}
    <p class="muted">Enter the interest as a percentage, e.g. 7.3 for 7.3%. (Your old spreadsheet showed the uni loan as 730% because 7.3 was typed into a % cell.)</p></section>
  <section class="card"><div class="card-head"><h2>Your plan</h2></div>
    <div class="form-row"><label class="lbl">I can pay each month ${field('debts||budget', d.budget, { type: 'money', label: 'Monthly debt budget' })}</label>
      <div class="seg" role="radiogroup" aria-label="Method">${[['avalanche', 'Avalanche', 'highest interest first, cheapest overall'], ['snowball', 'Snowball', 'smallest balance first, quick wins']].map(([v, l, h]) => `<button class="seg-btn ${d.method === v ? 'on' : ''}" data-set="debts||method" data-value="${v}" title="${h}">${l}<small>${h}</small></button>`).join('')}</div></div>
    ${p.stuck && d.items.length ? '<p class="nudge warn">At this payment the interest grows faster than you pay it off. Increase the monthly amount.</p>' : ''}
    ${p.schedule.length ? lines([{ name: 'Total owed', colour: 'var(--neg)', area: true, points: pts }], { fmt: gbpK, labels: lbl }) : ''}
    ${p.schedule.length ? `<h3 class="sub-h">Payment schedule</h3><div class="table-wrap"><table class="table"><thead><tr><th>Month</th>${p.debts.map((x) => `<th class="r">${esc(x.name)}</th>`).join('')}<th class="r">Total left</th></tr></thead><tbody>
      ${(showAll ? p.schedule : p.schedule.slice(0, 12)).map((r) => `<tr><td>${monthYear(r.date)}</td>${p.debts.map((x) => `<td class="r">${r.paid[x.id] ? gbp0(r.paid[x.id]) : '—'}</td>`).join('')}<td class="r">${gbp0(r.total)}</td></tr>`).join('')}</tbody></table></div>
      ${p.schedule.length > 12 ? `<button class="btn ghost small" data-toggle-ui="debtAll">${showAll ? 'Show first year only' : `Show all ${p.schedule.length} months`}</button>` : ''}` : ''}</section>`;
}

// ---------- FREEDOM ----------
export function freedom() {
  const f = S().freedom, r = C.freedom(f);
  const extra = C.freedom({ ...f, monthly: C.num(f.monthly) + 100 });
  const yrs = Math.min(50, Math.max(10, Math.ceil((r.years || 40) + 2)));
  const pts = Array.from({ length: yrs + 1 }, (_, y) => ({ v: C.futureValue(f.current, f.monthly, f.rate, y * 12) }));
  const goal = Array.from({ length: yrs + 1 }, () => ({ v: r.goal }));
  const thisYear = new Date().getFullYear();
  return `<header class="page-head"><div><p class="eyebrow">Financial freedom</p><h1>${r.date ? `Free in ${monthYear(r.date)}` : 'Set your numbers'}</h1></div></header>
  <section class="kpis">
    <div class="kpi"><span class="k-label">Your freedom number</span><span class="k-val">${gbp0(r.goal)}</span><span class="k-foot">${gbp0(f.monthlyNeed)}/month ÷ ${pct(f.withdrawalRate, 1)} withdrawal rate</span></div>
    <div class="kpi"><span class="k-label">How long</span><span class="k-val">${duration(r.months)}</span></div>
    <div class="kpi"><span class="k-label">Progress</span><span class="k-val">${pct(r.progress, 1)}</span>${meter(r.progress, 'ok')}</div>
    <div class="kpi"><span class="k-label">Add £100 a month</span><span class="k-val pos">${extra.date ? monthYear(extra.date) : '—'}</span><span class="k-foot">${r.months != null && extra.months != null ? `${duration(r.months - extra.months)} sooner` : ''}</span></div>
  </section>
  <section class="card"><div class="form-grid">
    <label class="lbl">Monthly income you'd need ${field('freedom||monthlyNeed', f.monthlyNeed, { type: 'money', label: 'Monthly income needed' })}</label>
    <label class="lbl">Savings & investments now ${field('freedom||current', f.current, { type: 'money', label: 'Current savings' })}</label>
    <label class="lbl">Invested per month ${field('freedom||monthly', f.monthly, { type: 'money', label: 'Monthly investing' })}</label>
    <label class="lbl">Average growth per year % ${field('freedom||rate', f.rate, { type: 'pct', label: 'Growth' })}</label>
    <label class="lbl">Safe withdrawal rate % ${field('freedom||withdrawalRate', f.withdrawalRate, { type: 'pct', label: 'Withdrawal rate' })}</label></div>
    <button class="btn ghost small" data-action="freedom-sync">Fill in from my goals</button></section>
  <section class="card"><div class="card-head"><h2>The path</h2><span class="legend"><span><i class="dot acc"></i>Your money</span><span><i class="dot mut"></i>Freedom number</span></span></div>
    ${lines([{ name: 'Goal', colour: 'var(--muted)', dash: true, points: goal }, { name: 'Projected', colour: 'var(--accent)', area: true, points: pts }], { fmt: gbpK, labels: pts.map((_, y) => String(thisYear + y)) })}
    <p class="muted">Growth figures are before inflation. 7% a year is a long-run stock market assumption; use 4 to 5% for a cautious view.</p></section>`;
}

// ---------- RECURRING ----------
export function recurring() {
  const s = S(), t = C.today();
  const accOpts = s.accounts.map((a) => [a.id, a.name]);
  const due = s.recurring.filter((r) => r.next && r.next <= t);
  const monthlyOut = C.sum(s.recurring.filter((r) => r.type === 'expense'), (r) => (r.freq === 'weekly' ? (C.num(r.amount) * 52) / 12 : r.freq === 'fortnightly' ? (C.num(r.amount) * 26) / 12 : r.freq === 'yearly' ? C.num(r.amount) / 12 : C.num(r.amount)));
  return `<header class="page-head"><div><p class="eyebrow">Repeating</p><h1>Bills & regular payments</h1></div></header>
  <p class="muted">Regular payments get logged for you, so you don't forget them. Ticked “Log automatically” posts on the due date; otherwise it waits here for one tap.</p>
  ${due.length ? `<section class="card"><div class="card-head"><h2>Due now</h2></div>${due.map((r) => `<div class="due-row"><span><b>${esc(r.name)}</b> <span class="muted">${niceDate(r.next)} · ${esc(accountName(r.accountId))}</span></span><b class="${r.type === 'income' ? 'pos' : 'neg'}">${gbp(r.amount)}</b><button class="btn primary small" data-rec-log="${r.id}">Log it</button><button class="btn ghost small" data-rec-skip="${r.id}">Skip</button></div>`).join('')}</section>` : ''}
  <section class="card"><div class="card-head"><h2>All repeating</h2><span class="muted">≈ ${gbp0(monthlyOut)} a month out</span></div>
    <div class="table-wrap"><table class="table edit"><thead><tr><th>Name</th><th>Type</th><th>Amount</th><th>Category</th><th>Account</th><th>Every</th><th>Next</th><th>Log automatically</th><th></th></tr></thead><tbody>
    ${s.recurring.map((r) => `<tr><td>${field(`recurring|${r.id}|name`, r.name, { label: 'Name' })}</td><td>${select(`recurring|${r.id}|type`, r.type, [['expense', 'Expense'], ['income', 'Income']], { label: 'Type' })}</td><td>${field(`recurring|${r.id}|amount`, r.amount, { type: 'money', label: 'Amount' })}</td>
      <td>${select(`recurring|${r.id}|category`, r.category, catsOf(r.type), { label: 'Category' })}</td><td>${select(`recurring|${r.id}|accountId`, r.accountId, accOpts, { label: 'Account' })}</td>
      <td>${select(`recurring|${r.id}|freq`, r.freq, [['weekly', 'Week'], ['fortnightly', '2 weeks'], ['monthly', 'Month'], ['yearly', 'Year']], { label: 'Frequency' })}</td><td>${field(`recurring|${r.id}|next`, r.next, { type: 'date', label: 'Next date' })}</td>
      <td class="c"><input id="auto-${r.id}" type="checkbox" class="check" data-bind="recurring|${r.id}|auto" data-kind="bool" ${r.auto ? 'checked' : ''} aria-label="Log automatically"></td><td>${delBtn('recurring', r.id)}</td></tr>`).join('') || '<tr><td colspan="9" class="muted">Nothing yet. Add one below, or tick “Repeat” when you add a transaction.</td></tr>'}
    </tbody></table></div>${addBtn('recurring', 'Add repeating payment', { name: '', type: 'expense', amount: 0, category: catsOf('expense')[0] || 'Other', subcategory: '', accountId: s.accounts[0]?.id, freq: 'monthly', next: t, auto: false })}</section>`;
}
