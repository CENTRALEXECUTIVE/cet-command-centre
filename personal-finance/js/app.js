// App shell: routing, event wiring, transaction editor, import flows, lock screen.
import * as C from './calc.js';
import * as IM from './importers.js';
import * as Store from './store.js';
import * as Sync from './sync.js';
import { ctx, esc, gbp, getPath, catsOf, accountName, niceDate } from './ui.js';
import { home, trans, stats, accounts, netWorth } from './views-core.js';
import { plan, goals, invest, debts, freedom, recurring } from './views-plan.js';
import { importView, prefs, prepareBank, prepareMM, guessGroup } from './views-import.js';
import { insights } from './views-insights.js';
import * as I from './insights.js';
import { bare } from './ui.js';

const ROUTES = {
  home: ['Home', 'home', home], trans: ['Trans.', 'list', trans], stats: ['Stats', 'pie', stats], accounts: ['Accounts', 'wallet', accounts], insights: ['Insights', 'spark', insights],
  plan: ['Budget plan', 'grid', plan], goals: ['Goals', 'target', goals], invest: ['Investments', 'up', invest], debts: ['Debts', 'down', debts],
  freedom: ['Freedom', 'flag', freedom], recurring: ['Repeating', 'repeat', recurring], import: ['Import & bank', 'bank', importView], prefs: ['Settings', 'gear', prefs],
};
// 24px line icons, drawn with currentColor so they follow the theme.
const ICON_PATHS = {
  home: 'M3 11l9-7 9 7v9a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z',
  list: 'M8 6h13M8 12h13M8 18h13M3.5 6h.01M3.5 12h.01M3.5 18h.01',
  pie: 'M21 12A9 9 0 1 1 12 3v9zM15 2.5A9 9 0 0 1 21.5 9H15z',
  wallet: 'M3 7a2 2 0 0 1 2-2h13v4M3 7v11a2 2 0 0 0 2 2h15V9H5a2 2 0 0 1-2-2zM16 14.5h.01',
  grid: 'M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z',
  target: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM12 16a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM12 12h.01',
  up: 'M3 17l6-6 4 4 8-8M15 7h6v6',
  down: 'M3 7l6 6 4-4 8 8M15 17h6v-6',
  flag: 'M5 21V4M5 4h11l-2 4 2 4H5',
  repeat: 'M17 2l4 4-4 4M3 11V9a3 3 0 0 1 3-3h15M7 22l-4-4 4-4M21 13v2a3 3 0 0 1-3 3H3',
  bank: 'M3 10l9-6 9 6M5 10v8M9.5 10v8M14.5 10v8M19 10v8M3 21h18',
  gear: 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z',
  more: 'M5 12h.01M12 12h.01M19 12h.01',
  spark: 'M12 3l1.9 5.6L19.5 10l-5.6 1.9L12 17.5l-1.9-5.6L4.5 10l5.6-1.4zM19 16l.8 2.2L22 19l-2.2.8L19 22l-.8-2.2L16 19l2.2-.8z',
};
const ico = (k) => `<svg class="ni-svg" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="${k === 'more' ? 3.5 : 1.8}" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="${ICON_PATHS[k]}"/></svg>`;
const NAV_GROUPS = [['Track', ['home', 'insights', 'trans', 'stats', 'accounts']], ['Plan', ['plan', 'goals', 'invest', 'debts', 'freedom']], ['Tools', ['recurring', 'import', 'prefs']]];
const MOBILE = ['home', 'trans', 'stats', 'accounts'];

const $ = (sel, root = document) => root.querySelector(sel);
const app = $('#app');
let route = 'home';

// ---------- state ----------
function commit(opts = {}) {
  ctx.state.settings.updatedAt = new Date().toISOString();
  Store.save(ctx.state);
  queueSync();
  if (opts.render !== false) render();
}
ctx.sync = Sync.state;

// ---------- sync ----------
let syncTimer = null, syncing = false;
function queueSync(delay = 3000) {
  if (!Sync.state.enabled) return;
  clearTimeout(syncTimer);
  syncTimer = setTimeout(syncNow, delay);
}
async function syncNow({ quiet = true } = {}) {
  if (!Sync.state.enabled || syncing) return;
  syncing = true;
  try {
    const got = await Sync.pullIfNewer();
    if (got) { ctx.state = Store.migrate(Sync.merge(ctx.state, got.data)); await Store.save(ctx.state, { now: true }); }
    await Sync.push(ctx.state, deviceName());
    await Store.kvPut('sync', Sync.snapshot());
    Sync.state.error = '';
    if (got) { render(); toast('Updated from your other device'); } else if (!quiet) toast('Synced');
  } catch (err) {
    Sync.state.status = 'error'; Sync.state.error = err?.code === 'quota_exceeded' ? 'The sync storage is full.' : err?.message || 'Sync failed';
    if (!quiet) toast(`Sync failed: ${Sync.state.error}`);
  }
  syncing = false;
  if (route === 'prefs') render();
}
const deviceName = () => (/iphone|android|mobile/i.test(navigator.userAgent) ? 'phone' : 'computer');
async function startSync() {
  if (!(await Sync.init())) { if (route === 'prefs') render(); return; }
  const saved = await Store.kvGet('sync');
  if (Sync.resume(saved)) syncNow();
  if (route === 'prefs') render();
}
document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') syncNow(); });
ctx.commit = commit;
ctx.render = () => render();

function toast(msg) {
  const t = $('#toast');
  t.textContent = msg; t.hidden = false;
  clearTimeout(toast.t); toast.t = setTimeout(() => (t.hidden = true), 2600);
}
ctx.toast = toast;

function applyTheme() {
  const th = ctx.state?.settings.theme;
  if (th === 'dark' || th === 'light') document.documentElement.setAttribute('data-theme', th);
  else if (ctx.themeSet) document.documentElement.removeAttribute('data-theme');
  ctx.themeSet = th === 'dark' || th === 'light';
}

// ---------- render ----------
function shell() {
  const navItem = (k, cls = '') => `<a href="#${k}" class="nav-item ${cls} ${route === k ? 'on' : ''}" ${route === k ? 'aria-current="page"' : ''}><span class="ni-ico">${ico(ROUTES[k][1])}</span><span>${ROUTES[k][0]}</span></a>`;
  return `<aside class="sidebar"><a class="brand" href="#home"><span class="brand-mark" aria-hidden="true">£</span><span>Money HQ</span></a>
      ${NAV_GROUPS.map(([g, ks]) => `<div class="nav-group"><span class="nav-label">${g}</span>${ks.map((k) => navItem(k)).join('')}</div>`).join('')}
      <p class="side-foot">Private: stored only on this device${Store.isLocked() ? ' · encrypted' : ''}</p></aside>
    <main class="main" id="main">${ROUTES[route][2]()}</main>
    <nav class="tabbar" aria-label="Main">${MOBILE.map((k) => navItem(k, 'tb')).join('')}<button class="nav-item tb ${MOBILE.includes(route) ? '' : 'on'}" data-action="more"><span class="ni-ico">${ico('more')}</span><span>More</span></button></nav>
    <button class="fab" data-action="quick-add" aria-label="Add transaction">+</button>`;
}

function render() {
  const focusId = document.activeElement?.id;
  const sel = document.activeElement && 'selectionStart' in document.activeElement ? [document.activeElement.selectionStart, document.activeElement.selectionEnd] : null;
  const y = window.scrollY;
  app.innerHTML = shell();
  if (focusId) {
    const el = document.getElementById(focusId);
    if (el && !el.closest('.modal')) { el.focus({ preventScroll: true }); try { if (sel && el.type !== 'number' && el.type !== 'date') el.setSelectionRange(...sel); } catch {} }
  }
  window.scrollTo(0, y);
}

function go() {
  const h = location.hash.replace('#', '');
  const next = ROUTES[h] ? h : 'home';
  const changed = next !== route;
  route = next;
  render();
  if (changed) { window.scrollTo(0, 0); const m = document.getElementById('main'); m?.classList.add('enter'); setTimeout(() => m?.classList.remove('enter'), 600); }
}

// ---------- generic bindings ----------
function resolveBind(bind) {
  const [path, id, key] = bind.split('|');
  if (path === 'ui') return { obj: ctx.ui, key, ui: true };
  if (path === 'ui.imp') return { obj: ctx.ui.imp, key, ui: true, imp: true };
  const base = getPath(ctx.state, path);
  const obj = id ? (Array.isArray(base) ? base.find((x) => x.id === id) : null) : base;
  return { obj, key };
}
function readInput(el) {
  const k = el.dataset.kind;
  if (k === 'bool') return el.checked;
  if (k === 'money' || k === 'number') return el.value === '' ? 0 : C.num(el.value);
  if (k === 'pct') return C.num(el.value) / 100;
  return el.value;
}

app.addEventListener('change', async (e) => {
  const el = e.target;
  if (el.dataset.bind) {
    const { obj, key, ui } = resolveBind(el.dataset.bind);
    if (!obj) return;
    obj[key] = readInput(el);
    if (el.dataset.bind === 'settings||theme') applyTheme();
    if (ui) { if (ctx.ui.imp?.kind === 'bank') { if (el.dataset.bind === 'ui||impAccount') { ctx.ui.imp.accountId = ctx.ui.impAccount; ctx.ui.imp.after = undefined; } ctx.ui.imp.lines = []; prepareBank(ctx.ui.imp); } render(); }
    else commit();
    return;
  }
  if (el.dataset.uiNum) { const [o, k] = el.dataset.uiNum.split('.'); (ctx.ui[o] ||= {})[k] = el.value === '' ? '' : C.num(el.value); render(); return; }
  if (el.dataset.ui != null) { ctx.ui[el.dataset.ui] = el.value; if (el.dataset.ui === 'speriod') ctx.ui.sanchor = C.today(); render(); return; }
  if (el.dataset.setbal) {
    const a = ctx.state.accounts.find((x) => x.id === el.dataset.setbal);
    const cur = netWorth().bal[a.id];
    a.opening = C.round2(C.num(a.opening) + C.num(el.value) - cur);
    toast(`${a.name} set to ${gbp(el.value)}`); el.value = '';
    commit(); return;
  }
  if (el.dataset.renameCat) return renameCategory(el.dataset.renameCat, el.value.trim());
  if (el.dataset.import) return handleImportFile(el.dataset.import, el.dataset.import === 'bank' ? [...el.files] : el.files[0]);
  if (el.dataset.restore != null) return restore(el.files[0]);
  const imp = ctx.ui.imp;
  if (imp) {
    if (el.dataset.map) {
      if (el.dataset.map === 'sign') imp.sign = el.value; else if (el.dataset.map === 'dateOrder') imp.dateOrder = el.value;
      else { if (el.value === '') delete imp.mapping[el.dataset.map]; else imp.mapping[el.dataset.map] = Number(el.value); if (el.dataset.map === 'amount' && el.value !== '') { delete imp.mapping.debit; delete imp.mapping.credit; } if ((el.dataset.map === 'debit' || el.dataset.map === 'credit') && el.value !== '') delete imp.mapping.amount; }
      imp.lines = []; prepareBank(imp); render(); return;
    }
    if (el.dataset.impPick != null) { imp.lines[+el.dataset.impPick].pick = el.checked; render(); return; }
    if (el.dataset.impAll != null) { imp.lines.forEach((l) => (l.pick = el.checked)); render(); return; }
    if (el.dataset.impCat != null) { const l = imp.lines[+el.dataset.impCat]; l.category = el.value; l.changed = l.category !== l.autoCat; return; }
    if (el.dataset.impSub != null) { imp.lines[+el.dataset.impSub].subcategory = el.value; return; }
    if (el.dataset.impRemember != null) { imp.remember = el.checked; return; }
    if (el.dataset.impSetbal != null) { imp.setBal = el.checked; return; }
  }
});

app.addEventListener('input', (e) => {
  if (e.target.id === 'tx-search') { ctx.ui.q = e.target.value; clearTimeout(app.t); app.t = setTimeout(render, 200); }
});

app.addEventListener('click', (e) => {
  const b = e.target.closest('button, a, [data-edit-tx], [data-cal-day], [data-goto-month]');
  if (!b) return;
  const d = b.dataset;
  if (d.add) {
    const list = getPath(ctx.state, d.add);
    list.push({ id: Store.uid(), ...JSON.parse(d.tpl || '{}') });
    commit();
    requestAnimationFrame(() => { const rows = app.querySelectorAll(`[data-bind^="${CSS.escape(d.add)}|${list.at(-1).id}|"]`); rows[0]?.focus(); });
    return;
  }
  if (d.del) {
    const [path, id] = d.del.split('|');
    const list = getPath(ctx.state, path);
    const i = list.findIndex((x) => x.id === id);
    if (i >= 0) list.splice(i, 1);
    commit(); return;
  }
  if (d.set) { const { obj, key } = resolveBind(d.set); obj[key] = d.value; commit(); return; }
  if (d.addSection) {
    const inp = document.getElementById(`newsec-${d.addSection}`);
    const name = inp.value.trim(); if (!name) return inp.focus();
    ctx.state.budget.items.push({ id: Store.uid(), group: d.addSection, section: name, name: '', amount: 0, category: '' });
    commit(); return;
  }
  if (d.toggleUi) { ctx.ui[d.toggleUi] = !ctx.ui[d.toggleUi]; render(); return; }
  if (d.editTx) return openTx(ctx.state.transactions.find((t) => t.id === d.editTx));
  if (d.quick != null) { const q = C.frequentEntries(ctx.state.transactions, 6, C.addMonths(C.today(), -3))[+d.quick]; return openTx(null, { type: q.type, category: q.category, subcategory: q.subcategory, note: q.note, accountId: q.accountId, amount: q.last }); }
  if (d.fscope != null) { ctx.ui.fscope = d.fscope; render(); return; }
  if (d.ttab) { ctx.ui.ttab = d.ttab; render(); return; }
  if (d.monthStep) { ctx.ui.month = C.monthKey(C.addMonths(ctx.ui.month + '-01', ctx.ui.ttab === 'monthly' ? 12 * +d.monthStep : +d.monthStep)); render(); return; }
  if (d.calDay) { ctx.ui.calDay = d.calDay; render(); return; }
  if (d.gotoMonth) { ctx.ui.month = d.gotoMonth; ctx.ui.ttab = 'daily'; render(); return; }
  if (d.stype) { ctx.ui.stype = d.stype; ctx.ui.scat = ''; render(); return; }
  if (d.scat != null) { ctx.ui.scat = d.scat; render(); if (d.scat) requestAnimationFrame(() => document.querySelector('.stats-card + .card')?.scrollIntoView({ behavior: 'smooth', block: 'start' })); return; }
  if (d.periodStep) {
    const n = +d.periodStep, p = ctx.ui.speriod, a = ctx.ui.sanchor;
    ctx.ui.sanchor = p === 'weekly' ? C.addDays(a, 7 * n) : p === 'annually' || p === 'taxyear' ? C.addMonths(a, 12 * n) : C.addMonths(a, n);
    render(); return;
  }
  if (d.filterAcct) { ctx.ui.facct = d.filterAcct; return; }
  if (d.recLog) return logRecurring(d.recLog);
  if (d.recSkip) { const r = ctx.state.recurring.find((x) => x.id === d.recSkip); r.next = C.nextDate(r.next, r.freq); commit(); toast(`Skipped. Next ${r.name}: ${niceDate(r.next)}`); return; }
  if (d.ask) { ctx.ui.ask = { q: d.ask, result: I.answer(d.ask, ctx.state) }; render(); return; }
  if (d.dismiss) { ctx.state.settings.dismissed.push(d.dismiss); commit(); toast('Marked as fine'); return; }
  if (d.subAdd) return addSubscription(d.subAdd);
  if (d.taxToggle) {
    const [which, cat] = [d.taxToggle.slice(0, d.taxToggle.indexOf('|')), d.taxToggle.slice(d.taxToggle.indexOf('|') + 1)];
    const list = ctx.state.settings.tax[which];
    const i = list.indexOf(cat); if (i >= 0) list.splice(i, 1); else list.push(cat);
    commit(); return;
  }
  if (d.action) return action(d.action, b);
});

async function action(a, btn) {
  const s = ctx.state;
  switch (a) {
    case 'quick-add': return openTx(null);
    case 'more': return openMore();
    case 'start-fresh': ctx.state = Store.blankState(); commit(); toast('Example data cleared. Add your first entry or import your history.'); return;
    case 'snapshot': {
      const nw = netWorth(), mk = C.monthKey(C.today());
      s.networth.history = s.networth.history.filter((h) => C.monthKey(h.date) !== mk);
      s.networth.history.push({ date: C.today(), assets: C.round2(nw.assets), liabilities: C.round2(nw.liabilities), net: C.round2(nw.net) });
      s.networth.history.sort((x, y) => x.date.localeCompare(y.date));
      commit(); toast('Net worth saved for this month'); return;
    }
    case 'freedom-sync': {
      const inv = C.sum(s.goals.investments, (g) => g.value), mon = C.sum(s.goals.investments, (g) => g.monthly);
      if (inv || mon) { s.freedom.current = inv; s.freedom.monthly = mon; commit(); toast('Filled from your savings & investment pots'); } else toast('Add savings & investment pots on the Goals page first');
      return;
    }
    case 'imp-cancel': ctx.ui.imp = null; render(); return;
    case 'imp-mm-commit': return commitMM();
    case 'imp-bank-commit': return commitBank();
    case 'backup': return backup(false);
    case 'backup-copy': return backup(true);
    case 'set-pass': {
      const p1 = $('#pass1').value, p2 = $('#pass2').value;
      if (p1.length < 4) return toast('Use at least 4 characters');
      if (p1 !== p2) return toast("The two passcodes don't match");
      await Store.setPasscode(p1); ctx.ui.lockOn = true; await Store.save(s, { now: true }); render(); toast('Passcode on. Your data is encrypted.'); return;
    }
    case 'clear-pass': await Store.setPasscode(null); ctx.ui.lockOn = false; await Store.save(s, { now: true }); render(); toast('Passcode removed'); return;
    case 'week-done': s.settings.weekDone = C.today(); commit(); toast('Nice. See you next week.'); return;
    case 'ask-clear': ctx.ui.ask = null; render(); return;
    case 'ask-ai': return askClaude();
    case 'undismiss': s.settings.dismissed = []; commit(); return;
    case 'budget-apply': {
      const sg = I.suggestBudget(s.transactions);
      const group = (c) => (/household|bills|rent|mortgage|tax|insur|hagbad/i.test(c) ? 'fixed' : /food|transport|health|business|fuel/i.test(c) ? 'variable' : 'optional');
      s.budget.income = sg.income.map((r) => ({ id: Store.uid(), name: bare(r.category), amount: r.monthly }));
      s.budget.items = sg.expenses.map((r) => ({ id: Store.uid(), group: group(r.category), section: bare(r.category), name: 'Average month', amount: r.monthly, category: r.category }));
      ctx.ui.budgetSuggest = false; commit(); toast('Plan rebuilt from your real averages. Adjust any line.'); return;
    }
    case 'sync-on': {
      const p1 = $('#sync1').value, p2 = $('#sync2')?.value;
      if (p1.length < 6) return toast('Use at least 6 characters for the sync passcode');
      if (p2 != null && p1 !== p2) return toast("The two passcodes don't match");
      try {
        const r = await Sync.enable(p1);
        if (r.result === 'pulled') {
          const local = ctx.state;
          ctx.state = Store.migrate(local.settings.sample ? r.data : Sync.merge(local, r.data));
          await Store.save(ctx.state, { now: true });
          toast(`Sync on. Loaded ${ctx.state.transactions.length.toLocaleString()} entries from your ${r.meta.device || 'other device'}.`);
        } else toast('Sync on. Open this page on your other device and turn sync on with the same passcode.');
        await Store.kvPut('sync', Sync.snapshot());
        if (r.result === 'new' || !ctx.state.settings.sample) await syncNow();
        applyTheme(); render();
      } catch (err) { toast(err?.name === 'OperationError' ? "That passcode doesn't open the synced copy. Use the one you chose on your other device." : `Sync couldn't start: ${err?.message || err}`); }
      return;
    }
    case 'sync-now': return syncNow({ quiet: false });
    case 'sync-off': Sync.disable(); await Store.kvPut('sync', undefined); render(); toast('Sync turned off on this device'); return;
    case 'sync-forget': if (!ctx.ui.confirmForget) { ctx.ui.confirmForget = true; render(); return; } await Sync.forget(); await Store.kvPut('sync', undefined); ctx.ui.confirmForget = false; render(); toast('Synced copy deleted. Data on this device is untouched.'); return;
    case 'wipe': await Store.wipe(); await Store.setPasscode(null); ctx.state = Store.blankState(); ctx.ui = {}; commit(); toast('All data deleted'); return;
  }
}

// ---------- insights actions ----------
app.addEventListener('submit', (e) => {
  if (e.target.id !== 'ask-form') return;
  e.preventDefault();
  const q = new FormData(e.target).get('q').trim();
  if (!q) return;
  ctx.ui.ask = { q, result: I.answer(q, ctx.state) };
  render();
});

function addSubscription(key) {
  const s = ctx.state, sub = I.detectSubscriptions(s.transactions, s.recurring).find((x) => x.key === key);
  if (!sub) return;
  let next = sub.nextDate;
  while (next < C.today()) next = C.nextDate(next, sub.freq);
  s.recurring.push({ id: Store.uid(), name: sub.name, type: 'expense', amount: sub.amount, category: sub.category, subcategory: sub.subcategory, accountId: sub.accountId, freq: sub.freq, next, auto: false });
  commit(); toast(`${sub.name} added to Repeating. Next due ${niceDate(next)}.`);
}

async function askClaude() {
  const a = ctx.ui.ask;
  if (!ctx.ai || !a?.q || a.loading) return;
  a.loading = true; render();
  const prompt = `You are the assistant inside a private UK personal finance app. Answer the user's question using ONLY the data below. Amounts are in GBP (£). Be direct and specific with figures, 2 to 6 short sentences, plain text with no markdown. If the data cannot answer it, say what is missing. Do not give regulated financial advice; you may point out facts, patterns and options.\n\nQuestion: ${a.q}\n\nThe app's own on-device answer was: ${a.result?.text || 'none'}\n\nData (JSON):\n${JSON.stringify(I.contextForAI(ctx.state))}`;
  try {
    const res = await ctx.ai(prompt, { cache: false });
    if (ctx.ui.ask === a) a.result = { text: res.text.trim(), rows: [], ai: true };
  } catch (err) {
    if (err?.code === 'not_granted') ctx.ai = null;
    toast(err?.code === 'not_granted' ? 'Claude was not given permission, so answers stay on this device.' : err?.code === 'rate_limited' ? 'Claude is busy. Try again in a minute.' : "Claude couldn't answer just now.");
  }
  a.loading = false; render();
}

// ---------- transaction editor ----------
function openTx(t, preset = {}) {
  const s = ctx.state;
  const editing = !!t;
  const v = t ? { ...t } : { type: 'expense', date: C.today(), accountId: s.accounts.find((a) => a.group === 'card')?.id || s.accounts[0]?.id, category: '', subcategory: '', note: '', description: '', amount: '', ...preset };
  const notes = [...new Set(s.transactions.map((x) => x.note).filter(Boolean))].slice(0, 400);
  const subs = [...new Set(s.transactions.map((x) => x.subcategory).filter(Boolean))].slice(0, 200);
  const quick = C.frequentEntries(s.transactions, 8, C.addMonths(C.today(), -3));
  const modal = $('#modal');
  const draw = () => {
    const cats = catsOf(v.type === 'income' ? 'income' : 'expense');
    if (v.type !== 'transfer' && !cats.includes(v.category)) v.category = '';
    modal.innerHTML = `<div class="sheet" role="dialog" aria-modal="true" aria-labelledby="tx-title">
      <div class="sheet-head"><h2 id="tx-title">${editing ? 'Edit entry' : 'New entry'}</h2><button class="icon-btn" data-close aria-label="Close">✕</button></div>
      <div class="seg wide">${['income', 'expense', 'transfer'].map((k) => `<button type="button" class="seg-btn ${v.type === k ? 'on ' + k : ''}" data-ttype="${k}">${k[0].toUpperCase() + k.slice(1)}</button>`).join('')}</div>
      ${!editing && quick.length ? `<div class="chips small">${quick.filter((q) => v.type === 'transfer' || q.type === v.type).slice(0, 6).map((q, i) => `<button type="button" class="chip" data-tq="${quick.indexOf(q)}">${esc(q.note)}</button>`).join('')}</div>` : ''}
      <form id="tx-form" autocomplete="off">
        <label class="amount-in"><span>£</span><input id="tx-amount" name="amount" type="number" inputmode="decimal" step="0.01" min="0" required value="${esc(v.amount)}" placeholder="0.00" aria-label="Amount"></label>
        <div class="form-grid">
          <label class="lbl">Date<input id="tx-date" class="in" name="date" type="date" required value="${esc(v.date)}"></label>
          <label class="lbl">${v.type === 'transfer' ? 'From' : 'Account'}<select id="tx-acct" class="in" name="accountId">${s.accounts.map((a) => `<option value="${a.id}" ${a.id === v.accountId ? 'selected' : ''}>${esc(a.name)}</option>`).join('')}</select></label>
          ${v.type === 'transfer' ? `<label class="lbl">To<select id="tx-to" class="in" name="toAccountId">${s.accounts.map((a) => `<option value="${a.id}" ${a.id === v.toAccountId ? 'selected' : ''}>${esc(a.name)}</option>`).join('')}</select></label>` : ''}
        </div>
        ${v.type === 'transfer' ? '' : `<p class="lbl">Category</p><div class="chips cats">${cats.map((c) => `<button type="button" class="chip ${c === v.category ? 'on' : ''}" data-tcat="${esc(c)}">${esc(c)}</button>`).join('')}</div>`}
        <div class="form-grid">
          <label class="lbl">${v.type === 'income' ? 'From (payer)' : 'Note (where / what)'}<input id="tx-note" class="in" name="note" list="dl-notes" value="${esc(v.note)}" placeholder="${v.type === 'income' ? 'Uber, Bolt, Central job…' : 'Costa, Fuel, EE SIM…'}"></label>
          ${v.type === 'transfer' ? '' : `<label class="lbl">Subcategory<input id="tx-sub" class="in" name="subcategory" list="dl-subs" value="${esc(v.subcategory)}"></label>`}
          <label class="lbl">Description<input id="tx-desc" class="in" name="description" value="${esc(v.description)}" placeholder="Optional detail"></label>
          ${v.type === 'transfer' ? '' : `<label class="lbl inline biz"><input id="tx-biz" type="checkbox" class="check" name="business" ${v.business ? 'checked' : ''}> Business (work / CET)</label>`}
          ${editing ? '' : `<label class="lbl">Repeat<select id="tx-repeat" class="in" name="repeat"><option value="">Doesn't repeat</option><option value="weekly">Every week</option><option value="monthly">Every month</option><option value="yearly">Every year</option></select></label>`}
        </div>
        ${v.type === 'expense' && !editing ? (v.splits ? `<div class="split-box"><p class="lbl">Split ${gbp(C.num(v.amount))} across categories</p>${v.splits.map((sp, i) => `<div class="split-row"><select id="sp-cat-${i}" class="in" name="sp-cat-${i}" aria-label="Part ${i + 1} category">${cats.map((c) => `<option ${c === sp.category ? 'selected' : ''}>${esc(c)}</option>`).join('')}</select><input id="sp-amt-${i}" class="in" name="sp-amt-${i}" type="number" step="0.01" inputmode="decimal" value="${esc(sp.amount)}" placeholder="0.00" aria-label="Part ${i + 1} amount"><button type="button" class="icon-btn danger" data-sp-del="${i}" aria-label="Remove part">✕</button></div>`).join('')}
          <div class="row-btns"><button type="button" class="btn ghost small" data-sp-add>+ Add part</button><span class="muted">${(() => { const left = C.round2(C.num(v.amount) - v.splits.reduce((t, x) => t + C.num(x.amount), 0)); return left === 0 ? 'Parts add up' : `${gbp(Math.abs(left))} ${left > 0 ? 'still to assign' : 'too much'}`; })()}</span><button type="button" class="link" data-sp-off>Don't split</button></div></div>` : '<button type="button" class="link" data-sp-on>Split across categories</button>') : ''}
        <datalist id="dl-notes">${notes.map((n) => `<option value="${esc(n)}">`).join('')}</datalist><datalist id="dl-subs">${subs.map((n) => `<option value="${esc(n)}">`).join('')}</datalist>
        <p class="form-err neg" id="tx-err" hidden></p>
        <div class="row-btns end">
          ${editing ? `<button type="button" class="btn ghost danger" data-tdel>${v._confirmDel ? 'Tap again to delete' : 'Delete'}</button>` : '<button type="submit" class="btn ghost" data-again="1">Save & add another</button>'}
          <button type="submit" class="btn primary">Save</button></div>
      </form></div>`;
    modal.hidden = false;
    document.body.classList.add('modal-open');
  };
  const collect = () => {
    const f = $('#tx-form', modal); if (!f) return;
    const fd = new FormData(f);
    if (f.querySelector('[name=business]')) v.business = false;
    for (const [k, val] of fd) {
      const m = k.match(/^sp-(cat|amt)-(\d+)$/);
      if (m) { if (v.splits?.[+m[2]]) v.splits[+m[2]][m[1] === 'cat' ? 'category' : 'amount'] = val; }
      else if (k === 'business') v.business = true;
      else v[k] = val;
    }
  };
  draw();
  setTimeout(() => $('#tx-amount', modal)?.focus(), 50);
  modal.onclick = (e) => {
    if (e.target === modal || e.target.closest('[data-close]')) return closeModal();
    const b = e.target.closest('button'); if (!b) return;
    if (b.dataset.ttype) { collect(); v.type = b.dataset.ttype; draw(); return; }
    if (b.dataset.tcat) { collect(); v.category = b.dataset.tcat; draw(); return; }
    if (b.dataset.spOn != null) { collect(); v.splits = [{ category: v.category || catsOf('expense')[0], amount: v.amount || '' }, { category: catsOf('expense').find((c) => c !== v.category) || catsOf('expense')[0], amount: '' }]; draw(); return; }
    if (b.dataset.spAdd != null) { collect(); v.splits.push({ category: catsOf('expense')[0], amount: '' }); draw(); return; }
    if (b.dataset.spDel != null) { collect(); v.splits.splice(+b.dataset.spDel, 1); if (v.splits.length < 2) v.splits = null; draw(); return; }
    if (b.dataset.spOff != null) { collect(); v.splits = null; draw(); return; }
    if (b.dataset.tq != null) { const q = quick[+b.dataset.tq]; collect(); Object.assign(v, { type: q.type, category: q.category, subcategory: q.subcategory, note: q.note, accountId: q.accountId, amount: q.last }); draw(); $('#tx-amount', modal).select(); return; }
    if (b.dataset.tdel != null) {
      if (!v._confirmDel) { collect(); v._confirmDel = true; draw(); return; }
      ctx.state.transactions = ctx.state.transactions.filter((x) => x.id !== t.id);
      (ctx.state.tombstones ||= []).push(t.id);
      closeModal(); commit(); toast('Deleted'); return;
    }
  };
  modal.onsubmit = (e) => {
    e.preventDefault();
    collect();
    const err = (m) => { const p = $('#tx-err', modal); p.textContent = m; p.hidden = false; };
    const amount = C.round2(C.num(v.amount));
    if (!(amount > 0)) return err('Enter an amount above £0.');
    if (v.type !== 'transfer' && !v.category && !v.splits) return err('Pick a category.');
    if (v.splits) {
      const parts = v.splits.map((x) => ({ category: x.category, amount: C.round2(C.num(x.amount)) })).filter((x) => x.amount > 0);
      if (C.round2(parts.reduce((t, x) => t + x.amount, 0)) !== amount) return err(`The parts must add up to ${gbp(amount)}.`);
      const splitId = Store.uid(), time = new Date().toTimeString().slice(0, 5);
      parts.forEach((x, i) => ctx.state.transactions.push({ id: Store.uid(), date: v.date, time, type: 'expense', amount: x.amount, accountId: v.accountId, category: x.category, subcategory: '', note: (v.note || '').trim(), description: `Part ${i + 1} of ${parts.length} · ${gbp(amount)} total${v.description ? ' · ' + v.description.trim() : ''}`, source: 'manual', splitId, business: !!v.business }));
      ctx.state.settings.lastLog = C.today();
      commit(); toast(`Saved ${parts.length} parts: ${gbp(amount)}`); closeModal(); return;
    }
    if (v.type === 'transfer' && v.accountId === v.toAccountId) return err('Choose two different accounts.');
    const rec = { id: t?.id || Store.uid(), date: v.date, time: t?.time || new Date().toTimeString().slice(0, 5), type: v.type, amount, accountId: v.accountId, toAccountId: v.type === 'transfer' ? v.toAccountId : undefined, category: v.type === 'transfer' ? '' : v.category, subcategory: v.subcategory || '', note: (v.note || '').trim(), description: (v.description || '').trim(), source: t?.source || 'manual', importKey: t?.importKey, recurringId: t?.recurringId, splitId: t?.splitId, business: v.type === 'transfer' ? false : !!v.business };
    if (editing) ctx.state.transactions = ctx.state.transactions.map((x) => (x.id === t.id ? rec : x));
    else ctx.state.transactions.push(rec);
    if (!editing && v.repeat) {
      const r = { id: Store.uid(), name: rec.note || rec.category, type: rec.type === 'transfer' ? 'expense' : rec.type, amount, category: rec.category, subcategory: rec.subcategory, accountId: rec.accountId, freq: v.repeat, next: C.nextDate(rec.date, v.repeat), auto: false };
      ctx.state.recurring.push(r); rec.recurringId = r.id;
    }
    ctx.state.settings.lastLog = C.today();
    commit();
    toast(`${editing ? 'Updated' : 'Saved'}: ${rec.note || rec.category || 'Transfer'} ${gbp(amount)}`);
    if (e.submitter?.dataset.again) { const keep = { type: v.type, date: v.date, accountId: v.accountId }; closeModal(); openTx(null, keep); }
    else closeModal();
  };
}
ctx.openTx = openTx;

function closeModal() { const m = $('#modal'); m.hidden = true; m.innerHTML = ''; document.body.classList.remove('modal-open'); }
document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !$('#modal').hidden) closeModal(); });

function openMore() {
  const modal = $('#modal');
  modal.innerHTML = `<div class="sheet" role="dialog" aria-modal="true" aria-label="More"><div class="sheet-head"><h2>More</h2><button class="icon-btn" data-close aria-label="Close">✕</button></div>
    <div class="more-grid">${Object.entries(ROUTES).filter(([k]) => !MOBILE.includes(k)).map(([k, [name, icon]]) => `<a class="more-item" href="#${k}"><span class="ni-ico">${ico(icon)}</span>${name}</a>`).join('')}</div></div>`;
  modal.hidden = false; document.body.classList.add('modal-open');
  modal.onclick = (e) => { if (e.target === modal || e.target.closest('[data-close], a')) closeModal(); };
  modal.onsubmit = null;
}

// ---------- recurring ----------
function logRecurring(id, date) {
  const r = ctx.state.recurring.find((x) => x.id === id);
  const d = date || r.next;
  ctx.state.transactions.push({ id: Store.uid(), date: d, time: '', type: r.type, amount: C.round2(r.amount), accountId: r.accountId, category: r.category, subcategory: r.subcategory || '', note: r.name, description: '', source: 'recurring', recurringId: r.id });
  r.next = C.nextDate(d, r.freq);
  commit(); toast(`Logged ${r.name} ${gbp(r.amount)}`);
}
function autoPostRecurring() {
  let n = 0;
  for (const r of ctx.state.recurring) {
    if (!r.auto || !r.next) continue;
    const { dates, next } = C.dueOccurrences(r);
    dates.forEach((d) => { ctx.state.transactions.push({ id: Store.uid(), date: d, time: '', type: r.type, amount: C.round2(r.amount), accountId: r.accountId, category: r.category, subcategory: r.subcategory || '', note: r.name, description: '', source: 'recurring', recurringId: r.id }); n++; });
    r.next = next;
  }
  if (n) { Store.save(ctx.state); setTimeout(() => toast(`${n} repeating payment${n > 1 ? 's' : ''} logged automatically`), 400); }
}

// ---------- categories ----------
function renameCategory(id, name) {
  const s = ctx.state, c = s.categories.find((x) => x.id === id);
  if (!c || !name || name === c.name) return render();
  const old = c.name;
  const existing = s.categories.find((x) => x.id !== id && x.kind === c.kind && x.name === name);
  if (existing) s.categories = s.categories.filter((x) => x.id !== id); else c.name = name;
  s.transactions.forEach((t) => { if (t.category === old && (t.type === c.kind)) t.category = name; });
  s.budget.items.forEach((i) => { if (i.category === old) i.category = name; });
  s.recurring.forEach((r) => { if (r.category === old) r.category = name; });
  s.rules.forEach((r) => { if (r.category === old) r.category = name; });
  commit(); toast(existing ? `Merged ${old} into ${name}` : `Renamed to ${name}`);
}

// ---------- imports ----------
let xlsxLoading = null;
function loadXLSX() {
  if (window.XLSX) return Promise.resolve(window.XLSX);
  xlsxLoading ||= new Promise((res, rej) => {
    const sc = document.createElement('script');
    sc.src = 'https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js';
    sc.integrity = 'sha384-vtjasyidUo0kW94K5MXDXntzOJpQgBKXmE7e2Ga4LG0skTTLeBi97eFAXsqewJjw'; // pinned: refuses a tampered copy
    sc.crossOrigin = 'anonymous';
    sc.onload = () => res(window.XLSX); sc.onerror = () => { xlsxLoading = null; rej(new Error('Could not load the spreadsheet reader. Check your connection, or export as CSV instead.')); };
    document.head.appendChild(sc);
  });
  return xlsxLoading;
}
async function fileRows(file) {
  if (/\.xlsx?$/i.test(file.name)) {
    const X = await loadXLSX();
    const wb = X.read(await file.arrayBuffer(), { type: 'array', cellDates: true });
    return X.utils.sheet_to_json(wb.Sheets[wb.SheetNames[0]], { header: 1, raw: true, defval: '' });
  }
  return IM.parseCSV(await file.text());
}

// PDF reader (pdf.js), loaded only when a PDF is chosen, pinned with integrity hashes.
// The worker script runs on the main thread (no separate worker needed).
let pdfLoading = null;
function loadPDFJS() {
  if (window.pdfjsLib && window.pdfjsWorker) return Promise.resolve(window.pdfjsLib);
  const add = (src, integrity) => new Promise((res, rej) => { const sc = document.createElement('script'); sc.src = src; sc.integrity = integrity; sc.crossOrigin = 'anonymous'; sc.onload = res; sc.onerror = () => rej(new Error('Could not load the PDF reader. Check your connection, or use a CSV download instead.')); document.head.appendChild(sc); });
  pdfLoading ||= add('https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.worker.min.js', 'sha384-SnzOobpRMLXZ52iJvZm/C0fYw0OQemTXzTjIsdsfMcrCtCEe9qgzxTd3RSklO5x2')
    .then(() => add('https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.min.js', 'sha384-/1qUCSGwTur9vjf/z9lmu/eCUYbpOTgSjmpbMQZ1/CtX2v/WcAIKqRv+U1DUCG6e'))
    .then(() => window.pdfjsLib).catch((e) => { pdfLoading = null; throw e; });
  return pdfLoading;
}
// Rebuild the printed lines from the PDF's positioned text pieces.
async function pdfTextLines(file) {
  const lib = await loadPDFJS();
  const doc = await lib.getDocument({ data: new Uint8Array(await file.arrayBuffer()), isEvalSupported: false }).promise;
  const lines = [];
  for (let n = 1; n <= doc.numPages; n++) {
    const page = await doc.getPage(n);
    const tc = await page.getTextContent();
    const rows = [];
    for (const it of tc.items) {
      if (!it.str || !it.str.trim()) continue;
      const x = it.transform[4], y = it.transform[5];
      let row = rows.find((r) => Math.abs(r.y - y) < 2.5);
      if (!row) rows.push((row = { y, items: [] }));
      row.items.push({ x, w: it.width || 0, s: it.str });
    }
    rows.sort((a, b) => b.y - a.y);
    for (const r of rows) {
      r.items.sort((a, b) => a.x - b.x);
      let out = '', end = null;
      for (const it of r.items) { if (end != null) out += it.x - end > 2 ? '   ' : ''; out += it.s; end = it.x + it.w; }
      lines.push(out);
    }
  }
  return lines;
}

async function handleImportFile(kind, files) {
  const file = Array.isArray(files) ? files[0] : files;
  if (!file) return;
  try {
    if (kind === 'mm') {
      const rows = await fileRows(file);
      ctx.ui.imp = { kind: 'mm', fileName: file.name, mm: prepareMM(rows) };
    } else {
      const imp = { kind: 'bank', fileName: file.name, accountId: ctx.ui.impAccount || ctx.state.accounts[0]?.id, sign: 'normal', dateOrder: 'dmy', lines: [] };
      const pdfs = (Array.isArray(files) ? files : [file]).filter((f) => /\.pdf$/i.test(f.name));
      if (pdfs.length) {
        const parsed = [];
        for (const f of pdfs) { const ls = await pdfTextLines(f); parsed.push({ ...IM.parseStatementText(ls), holder: IM.statementHolder(ls) }); }
        const lines = parsed.flatMap((p) => p.lines).sort((a, b) => a.date.localeCompare(b.date));
        if (!lines.length) throw new Error("No transactions found in that PDF. If it's a scanned image, or a bank layout this reader doesn't know, use a CSV download instead.");
        const last = parsed.filter((p) => p.lines.length).sort((a, b) => a.lines.at(-1).date.localeCompare(b.lines.at(-1).date)).at(-1);
        imp.pdf = { files: pdfs.length, lines, balanced: parsed.every((p) => p.balanced !== false), closing: last.closing, closingDate: last.lines.at(-1).date, holder: parsed[0].holder };
        imp.fileName = pdfs.length > 1 ? `${pdfs.length} statements · ${niceDate(lines[0].date)} to ${niceDate(lines.at(-1).date)}` : file.name;
      } else if (/\.(ofx|qfx)$/i.test(file.name)) { imp.ofx = true; imp.text = await file.text(); }
      else {
        const all = await fileRows(file);
        const hi = IM.findHeaderRow(all);
        imp.header = all[hi].map(String);
        imp.rows = all.slice(hi + 1);
        imp.preset = IM.detectPreset(imp.header);
        imp.mapping = imp.preset ? IM.resolveMapping(imp.header, imp.preset.map) : IM.guessMapping(imp.header);
        if (imp.preset?.sign) imp.sign = imp.preset.sign;
      }
      prepareBank(imp);
      if (!imp.lines.length && !imp.header) throw new Error('No transactions found in that file.');
      ctx.ui.imp = imp;
    }
  } catch (err) {
    ctx.ui.imp = { kind, fileName: file.name, error: err.message || String(err) };
  }
  route = 'import'; if (location.hash !== '#import') location.hash = 'import'; else render();
  window.scrollTo(0, 0);
}

function commitMM() {
  const imp = ctx.ui.imp;
  if (ctx.state.settings.sample) { const keepName = ctx.state.settings.name; ctx.state = Store.blankState(); ctx.state.settings.name = keepName; }
  const s = ctx.state;
  const r = imp.mm;
  const accByName = (name) => {
    const n = name.trim();
    let a = s.accounts.find((x) => x.name.trim().toLowerCase() === n.toLowerCase());
    if (!a) { a = { id: Store.uid(), name: n, group: guessGroup(n), opening: 0 }; s.accounts.push(a); }
    return a.id;
  };
  const catSet = new Set(s.categories.map((c) => `${c.kind}|${c.name}`));
  for (const t of r.fresh) {
    if (t.type !== 'transfer' && !catSet.has(`${t.type}|${t.category}`)) { s.categories.push({ id: Store.uid(), name: t.category, kind: t.type }); catSet.add(`${t.type}|${t.category}`); }
    s.transactions.push({ id: Store.uid(), date: t.date, time: t.time, type: t.type, amount: t.amount, accountId: accByName(t.account), toAccountId: t.type === 'transfer' ? accByName(t.toAccount || 'Other') : undefined, category: t.category || '', subcategory: t.subcategory || '', note: t.note, description: t.description, source: 'moneymanager', importKey: t.importKey });
  }
  ctx.ui.imp = null;
  commit();
  toast(`Imported ${r.fresh.length.toLocaleString()} entries. Set each account's real balance on the Accounts page.`);
  location.hash = 'accounts'; ctx.ui.editAccounts = true;
}

function commitBank() {
  const imp = ctx.ui.imp;
  if (ctx.state.settings.sample) { const keepName = ctx.state.settings.name; ctx.state = Store.blankState(); ctx.state.settings.name = keepName; }
  const s = ctx.state;
  if (!s.accounts.some((a) => a.id === imp.accountId)) imp.accountId = s.accounts[0].id;
  const picked = imp.lines.filter((l) => l.pick);
  for (const l of picked) {
    s.transactions.push({ id: Store.uid(), date: l.date, time: '', type: l.type, amount: l.amount, accountId: imp.accountId, category: l.category, subcategory: l.subcategory || '', note: tidy(l.description), description: l.notes || '', bankDescription: l.description, source: 'statement', importKey: l.key });
    if (imp.remember !== false && l.changed) {
      const match = IM.normMerchant(l.description);
      if (match.length >= 3) { s.rules = s.rules.filter((r) => r.match !== match); s.rules.push({ id: Store.uid(), match, type: l.type, category: l.category, subcategory: l.subcategory || '' }); }
    }
  }
  if (imp.pdf?.closing != null && imp.setBal !== false) {
    const a = s.accounts.find((x) => x.id === imp.accountId);
    const upTo = s.transactions.filter((t) => t.date <= imp.pdf.closingDate);
    const balThen = C.accountBalances([a], upTo)[a.id];
    a.opening = C.round2(C.num(a.opening) + imp.pdf.closing - balThen);
  }
  ctx.ui.imp = null;
  commit(); toast(picked.length ? `Imported ${picked.length} transactions into ${accountName(imp.accountId)}` : `${accountName(imp.accountId)} balance set from your statement`);
  location.hash = 'trans';
}
const tidy = (s) => String(s).toLowerCase().replace(/\b\w/g, (c) => c.toUpperCase()).slice(0, 60);

// ---------- backup ----------
async function backup(copy) {
  const s = ctx.state;
  s.settings.lastBackup = C.today();
  const text = JSON.stringify({ app: 'money-hq', exported: new Date().toISOString(), state: s });
  if (copy) {
    try { await navigator.clipboard.writeText(text); toast('Backup copied. Paste it into a private note.'); } catch { toast("Copying isn't allowed here. Use Download backup instead."); }
  } else {
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([text], { type: 'application/json' }));
    a.download = `money-hq-backup-${C.today()}.json`;
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 4000);
    toast('Backup downloaded');
  }
  commit();
}
async function restore(file) {
  if (!file) return;
  try {
    const j = JSON.parse(await file.text());
    if (j.app !== 'money-hq' || !j.state) throw new Error();
    ctx.state = Store.migrate(j.state); commit(); applyTheme();
    toast(`Restored ${ctx.state.transactions.length.toLocaleString()} entries from ${niceDate(j.exported.slice(0, 10))}`);
  } catch { toast("That file isn't a Money HQ backup."); }
}

// ---------- boot & lock ----------
function lockScreen(record) {
  app.innerHTML = `<div class="lock"><span class="brand-mark big" aria-hidden="true">£</span><h1>Money HQ is locked</h1>
    <form id="unlock" class="lock-form"><input id="unlock-pass" class="in" type="password" autocomplete="current-password" placeholder="Passcode" aria-label="Passcode" autofocus><button class="btn primary">Unlock</button></form><p class="neg" id="unlock-err" hidden>That passcode didn't work.</p></div>`;
  $('#unlock').onsubmit = async (e) => {
    e.preventDefault();
    try { ctx.state = await Store.unlock(record, $('#unlock-pass').value); ctx.ui.lockOn = true; start(); startSync(); window.claude?.use?.('sample').then((x) => { if (x) { ctx.ai = x; render(); } }).catch(() => {}); }
    catch { $('#unlock-err').hidden = false; }
  };
}

function start() {
  applyTheme();
  autoPostRecurring();
  window.addEventListener('hashchange', go);
  go();
}

window.addEventListener('pagehide', () => Store.flush());
document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden') Store.flush(); });

(async () => {
  const r = await Store.load();
  if (r.needsPasscode) return lockScreen(r.record);
  ctx.state = r.state;
  if (r.fresh) Store.save(ctx.state);
  start();
  window.claude?.use?.('sample').then((sample) => { if (sample) { ctx.ai = sample; render(); } }).catch(() => {});
  startSync();
  if ('serviceWorker' in navigator && location.protocol === 'https:' && !/claude|anthropic/.test(location.host)) navigator.serviceWorker.register('sw.js').catch(() => {});
})();
