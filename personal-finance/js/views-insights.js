// Insights: ask a question, safe-to-spend before payday, things to check,
// regular payments, money score and the self-employed tax pot.
import * as C from './calc.js';
import * as I from './insights.js';
import { ring, meter } from './charts.js';
import { ctx, esc, gbp, gbp0, pct, niceDate, field, select, catsOf } from './ui.js';

const S = () => ctx.state;
const DAYS = [[1, 'Monday'], [2, 'Tuesday'], [3, 'Wednesday'], [4, 'Thursday'], [5, 'Friday'], [6, 'Saturday'], [0, 'Sunday']];
const KIND = { duplicate: ['Charged twice', 'over'], fee: ['Fee', 'warn'], unusual: ['Unusual', 'warn'], price: ['Price rise', 'warn'], subscription: ['Regular payment', ''] };

// ---------- ask bar (Home + Insights) ----------
export function askBar(compact = false) {
  const a = ctx.ui.ask || {};
  const r = a.result;
  return `<section class="card ask ${compact ? 'compact' : ''}">
    <form id="ask-form" class="ask-form" autocomplete="off">
      <span class="ask-spark" aria-hidden="true"></span>
      <input id="ask-q" class="ask-in" name="q" value="${esc(a.q || '')}" placeholder="Ask your money anything…" aria-label="Ask a question about your money">
      <button class="btn primary" type="submit">Ask</button>
    </form>
    ${r ? `<div class="answer" aria-live="polite">
        <p class="answer-text">${esc(r.text)}</p>
        ${r.rows?.length ? `<ul class="answer-rows">${r.rows.map((x) => `<li><span class="ar-label">${esc(x.label)}</span>${x.frac != null ? `<span class="ar-bar"><span style="width:${Math.max(2, x.frac * 100).toFixed(1)}%"></span></span>` : '<span></span>'}<b>${esc(x.value)}</b></li>`).join('')}</ul>` : ''}
        <div class="row-btns">
          ${r.link ? `<a class="btn ghost small" href="#${r.link}">Open ${{ stats: 'Stats', insights: 'Insights', accounts: 'Accounts', debts: 'Debts', freedom: 'Freedom' }[r.link] || r.link}</a>` : ''}
          ${ctx.ai && !r.ai ? `<button class="btn ghost small" data-action="ask-ai" ${a.loading ? 'disabled' : ''}>${a.loading ? 'Claude is thinking…' : 'Ask Claude for a deeper answer'}</button>` : ''}
          <button class="link" data-action="ask-clear">Clear</button>
        </div>
        ${r.ai ? '<p class="muted small-print">Answered by Claude from a summary of your figures. General information, not financial advice.</p>' : ctx.ai ? '<p class="muted small-print">Answers are worked out on this device. “Ask Claude” sends a summary of your figures (totals, bills and your 60 latest entries) to Claude.</p>' : ''}
      </div>` : ''}
    ${!r || r.suggestions ? `<div class="chips small">${(r?.suggestions || I.SUGGESTED_QUESTIONS.slice(0, compact ? 3 : 6)).map((q) => `<button class="chip" data-ask="${esc(q)}">${esc(q)}</button>`).join('')}</div>` : ''}
  </section>`;
}

// ---------- payday card (Home + Insights) ----------
export function paydayCard(full = false) {
  const s = S(), p = I.payday(s), cfg = s.settings.payday || {};
  const tight = p.safe < 0;
  return `<section class="card payday ${tight ? 'tight' : ''}">
    <div class="card-head"><h2>Left before payday</h2><span class="muted">${niceDate(p.date)} · ${p.days} day${p.days > 1 ? 's' : ''} away</span></div>
    <div class="payday-main">
      <div><span class="k-label">Safe to spend</span><span class="hero-num ${tight ? 'neg' : ''}">${gbp0(p.safe)}</span><span class="k-foot">${tight ? 'Bills due are more than your balance' : `about ${gbp(p.perDay)} a day`}</span></div>
      <div class="payday-sum"><div><span>In ${esc(p.accounts.join(' + ') || 'accounts')}</span><b>${gbp(p.available)}</b></div><div><span>Bills still to come</span><b class="neg">−${gbp(p.billsTotal)}</b></div>${p.incomingTotal ? `<div><span>Expected in (not counted)</span><b class="pos">+${gbp(p.incomingTotal)}</b></div>` : ''}</div>
    </div>
    ${full ? `${p.bills.length ? `<ul class="plain">${p.bills.map((b) => `<li><span>${esc(b.name)} <small class="muted">${niceDate(b.date)}${b.overdue ? ' · overdue' : ''}</small></span><b>${gbp(b.amount)}</b></li>`).join('')}</ul>` : '<p class="muted">No bills on your Repeating list fall before payday. Add your regular bills there so they are counted.</p>'}
      <div class="form-grid">
        <label class="lbl">I get paid ${select('settings.payday||mode', cfg.mode || 'weekly', [['weekly', 'Every week'], ['monthly', 'Every month']], { label: 'Pay frequency' })}</label>
        ${(cfg.mode || 'weekly') === 'monthly' ? `<label class="lbl">On day of the month ${field('settings.payday||day', cfg.day ?? 28, { type: 'number', step: '1', label: 'Pay day', attrs: 'min="1" max="31"' })}</label>` : `<label class="lbl">On ${select('settings.payday||weekday', cfg.weekday ?? 2, DAYS, { label: 'Pay weekday' })}</label>`}
      </div>
      <p class="muted small-print">Uses your Cash and Accounts balances. If they look wrong, set today's real balance on the <a href="#accounts">Accounts</a> page.</p>` : `<a class="link" href="#insights">See the bills and change payday</a>`}
  </section>`;
}

// ---------- page ----------
export function insights() {
  const s = S();
  const issues = I.findIssues(s);
  const subs = I.detectSubscriptions(s.transactions, s.recurring);
  const h = I.healthScore(s);
  const t = I.taxEstimate(s);
  const tcfg = s.settings.tax || { income: [], costs: [] };
  const dismissed = (s.settings.dismissed || []).length;
  return `<header class="page-head"><div><p class="eyebrow">Insights</p><h1>${issues.length ? `${issues.length} thing${issues.length > 1 ? 's' : ''} to check` : 'All clear'}</h1></div></header>
  ${askBar()}
  <section class="grid-2">
    ${paydayCard(true)}
    <div class="card"><div class="card-head"><h2>Money score</h2><span class="pill ${h.score >= 60 ? 'ok' : h.score >= 40 ? 'warn' : 'over'}">${h.label}</span></div>
      <div class="score-wrap">${ring(h.score / 100, String(h.score), 'out of 100')}
        <ul class="score-parts">${h.parts.map((p) => `<li><div class="sp-head"><span>${esc(p.name)}</span><b>${p.score}/${p.max}</b></div>${meter(p.score / p.max, p.score / p.max >= 0.7 ? 'ok' : p.score / p.max >= 0.4 ? 'warn' : 'over')}<small class="muted">${esc(p.note)}</small></li>`).join('')}</ul></div></div>
  </section>

  <section class="card"><div class="card-head"><h2>Does anything look off?</h2><span class="muted">Last 45 days · double charges, fees, price rises, forgotten subscriptions</span></div>
    ${issues.length ? `<ul class="issues">${issues.map((i) => `<li class="issue sev-${i.severity}"><span class="pill ${KIND[i.kind][1]}">${KIND[i.kind][0]}</span>
        <div class="issue-body"><b>${esc(i.title)}</b><span class="muted">${esc(i.detail)}</span></div>
        <div class="row-btns">${i.kind === 'subscription' ? `<button class="btn primary small" data-sub-add="${esc(i.sub.key)}">Add to Repeating</button>` : ''}<button class="btn ghost small" data-edit-tx="${i.txIds.at(-1)}">Review</button><button class="btn ghost small" data-dismiss="${esc(i.id)}">It's fine</button></div></li>`).join('')}</ul>`
      : '<p class="all-clear"><span class="tick" aria-hidden="true">✓</span> Nothing looks off. No double charges, surprise fees, price rises or unlisted regular payments.</p>'}
    ${dismissed ? `<button class="link" data-action="undismiss">Show ${dismissed} item${dismissed > 1 ? 's' : ''} you marked as fine</button>` : ''}</section>

  <section class="card"><div class="card-head"><h2>Regular payments found</h2><span class="muted">${subs.length ? `≈ ${gbp0(C.sum(subs, (x) => x.monthly))} a month · ${gbp0(C.sum(subs, (x) => x.monthly) * 12)} a year` : 'Found from your history'}</span></div>
    ${subs.length ? `<div class="table-wrap"><table class="table"><thead><tr><th>Payee</th><th>Every</th><th class="r">Amount</th><th class="r">Per year</th><th>Next due</th><th></th></tr></thead><tbody>
      ${subs.map((x) => `<tr><td><b>${esc(x.name)}</b><small class="muted">${esc(x.category || '')} · paid ${x.count} times</small></td><td>${{ weekly: 'Week', fortnightly: '2 weeks', monthly: 'Month', yearly: 'Year' }[x.freq]}</td><td class="r">${gbp(x.amount)}${x.priceRise ? ` <span class="pill warn">was ${gbp(x.previous)}</span>` : ''}</td><td class="r">${gbp0(x.monthly * 12)}</td><td class="nowrap">${niceDate(x.nextDate)}</td>
        <td class="r">${x.listed ? '<span class="pill ok">On Repeating list</span>' : `<button class="btn ghost small" data-sub-add="${esc(x.key)}">Add to Repeating</button>`}</td></tr>`).join('')}</tbody></table></div>`
      : '<p class="muted">Nothing yet. A payee shows here once it has been paid three times at a steady weekly or monthly rhythm.</p>'}</section>

  <section class="card"><div class="card-head"><h2>Tax pot <span class="muted">self-employed estimate, ${t.label}</span></h2>${t.configured ? `<b class="hero-num small">${gbp0(t.dueSoFar)}</b>` : ''}</div>
    <p class="muted">Tick what counts as self-employed income and as business costs. Anything you tag “Business” when adding it is counted too. The estimate covers Income Tax and Class 4 National Insurance for England, Wales and Northern Ireland.</p>
    <div class="grid-2">
      <div><p class="lbl">Self-employed income</p><div class="chips small">${catsOf('income').map((c) => `<button class="chip ${tcfg.income.includes(c) ? 'on' : ''}" data-tax-toggle="income|${esc(c)}">${esc(c)}</button>`).join('')}</div></div>
      <div><p class="lbl">Business costs</p><div class="chips small">${catsOf('expense').map((c) => `<button class="chip ${tcfg.costs.includes(c) ? 'on' : ''}" data-tax-toggle="costs|${esc(c)}">${esc(c)}</button>`).join('')}</div></div>
    </div>
    ${t.configured ? `<div class="totals four"><div><span>Income so far</span><b>${gbp0(t.income)}</b></div><div><span>Costs so far</span><b>${gbp0(t.costs)}</b></div><div><span>Profit so far</span><b>${gbp0(t.profit)}</b></div><div><span>Set aside</span><b class="neg">${pct(t.setAsidePct)} of profit</b></div></div>
      <p>At this pace your profit for the full tax year is about <b>${gbp0(t.projectedProfit)}</b>, with a bill of about <b>${gbp0(t.projected.total)}</b> (${gbp0(t.projected.tax)} tax + ${gbp0(t.projected.ni)} National Insurance). You should have <b>${gbp0(t.dueSoFar)}</b> put aside by today.</p>
      ${meter(t.fractionOfYear, 'ok')}<p class="muted small-print">${pct(t.fractionOfYear)} of the tax year gone. An estimate only: it ignores other income, mileage allowance, pension relief, student loan and payments on account. Check with your accountant.</p>` : ''}
  </section>`;
}
