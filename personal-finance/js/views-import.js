// Import (Money Manager, bank statements, Open Banking) and Settings.
import * as C from './calc.js';
import * as IM from './importers.js';
import { ctx, esc, gbp, niceDate, field, select, delBtn, addBtn, catsOf } from './ui.js';
import { ACCOUNT_GROUPS } from './store.js';

const S = () => ctx.state;

// ---------- IMPORT ----------
export function importView() {
  const imp = ctx.ui.imp;
  return `<header class="page-head"><div><p class="eyebrow">Bring your money in</p><h1>Import & bank feeds</h1></div></header>
  ${imp?.kind === 'mm' ? mmReview(imp) : imp?.kind === 'bank' ? bankReview(imp) : ''}
  <section class="grid-2">
    <div class="card"><div class="card-head"><h2>1 · Money Manager history</h2></div>
      <p>In Money Manager: <b>More → Backup → Export data to Excel</b>, then pick the file here. Accounts and categories are created to match, and anything already imported is skipped, so you can re-import a newer export at any time.</p>
      <label class="file-drop"><input id="mm-file" type="file" accept=".xlsx,.xls,.csv" data-import="mm"><span>Choose Money Manager export (.xlsx)</span></label></div>
    <div class="card"><div class="card-head"><h2>2 · Bank statement</h2></div>
      <p>Download a CSV (or OFX/QFX) statement from your banking app or website. Monzo, Starling, Revolut, Lloyds, Halifax, NatWest, Barclays, Nationwide, Santander, Chase and Amex are recognised automatically. Any other bank works by picking the columns.</p>
      <label class="lbl">Into account ${select('ui||impAccount', ctx.ui.impAccount || S().accounts[0]?.id, S().accounts.map((a) => [a.id, a.name]), { label: 'Account' })}</label>
      <label class="file-drop"><input id="bank-file" type="file" accept=".csv,.ofx,.qfx,.txt,.xlsx" data-import="bank"><span>Choose statement file</span></label>
      <p class="muted">Every line is categorised from your own history first, then from your rules, then from common UK merchants. Anything that matches an entry you typed by hand is unticked so it isn't counted twice.</p></div>
  </section>
  <section class="card"><div class="card-head"><h2>3 · Connect your bank (Open Banking)</h2><span class="pill warn">Not connected</span></div>
    <div class="grid-2"><div>
      <p><b>Is it safe?</b> Open Banking is regulated by the FCA. You approve access inside your own bank's app, you never share your login, access is read-only (nobody can move money), and you can cancel it at any time from your bank app.</p>
      <p><b>What it needs.</b> A connection goes through a regulated provider (TrueLayer, Yapily, Enable Banking, Plaid). Their secret keys can't live in a web page, so this needs a small private server. Most providers now charge per connection, and the old free option (GoCardless/Nordigen) has closed to new sign-ups.</p></div>
    <div><p><b>Until then</b>, a monthly statement import gets you the same data for free and keeps everything on this device.</p>
      <ul class="ticks"><li>Pick a provider and get API keys</li><li>Add the small sync server (I can build it next)</li><li>Approve each bank once in your bank app</li><li>New transactions arrive here and are auto-categorised like imports</li></ul></div></div></section>`;
}

function mmReview(imp) {
  if (imp.error) return `<section class="card"><p class="neg">${esc(imp.error)}</p><button class="btn ghost small" data-action="imp-cancel">Close</button></section>`;
  const r = imp.mm;
  return `<section class="card highlight"><div class="card-head"><h2>Ready to import: ${esc(imp.fileName)}</h2></div>
    <div class="totals"><div><span>New entries</span><b>${r.fresh.length.toLocaleString()}</b></div><div><span>Already here</span><b>${r.dupes}</b></div><div><span>Dates</span><b class="small">${niceDate(r.from)} → ${niceDate(r.to)}</b></div></div>
    <p>Income ${gbp(r.income)} · Expenses ${gbp(r.expense)} · ${r.transfers} transfers</p>
    ${r.newAccounts.length ? `<p><b>New accounts:</b> ${r.newAccounts.map(esc).join(', ')}</p>` : ''}
    ${r.newCats.length ? `<p><b>New categories:</b> ${r.newCats.map(esc).join(', ')}</p>` : ''}
    ${S().settings.sample ? '<p class="nudge warn">This clears the example data first.</p>' : ''}
    <div class="row-btns"><button class="btn primary" data-action="imp-mm-commit" ${r.fresh.length ? '' : 'disabled'}>Import ${r.fresh.length.toLocaleString()} entries</button><button class="btn ghost" data-action="imp-cancel">Cancel</button></div></section>`;
}

function bankReview(imp) {
  if (imp.error) return `<section class="card"><p class="neg">${esc(imp.error)}</p><button class="btn ghost small" data-action="imp-cancel">Close</button></section>`;
  const cats = (t) => catsOf(t);
  const picked = imp.lines.filter((l) => l.pick);
  const colOpts = [['', '—'], ...(imp.header || []).map((h, i) => [String(i), h || `Column ${i + 1}`])];
  const m = imp.mapping || {};
  return `<section class="card highlight"><div class="card-head"><h2>${esc(imp.fileName)}</h2><span class="muted">${imp.preset ? `Recognised as ${esc(imp.preset.name)}` : imp.ofx ? 'OFX statement' : 'Check the columns below'}</span></div>
    ${imp.header ? `<details ${imp.preset ? '' : 'open'}><summary>Columns</summary><div class="form-grid">
      ${['date', 'description', 'amount', 'debit', 'credit'].map((k) => `<label class="lbl">${{ date: 'Date', description: 'Description', amount: 'Amount (one column)', debit: 'Money out', credit: 'Money in' }[k]}<select id="map-${k}" class="in" data-map="${k}">${colOpts.map(([v, t]) => `<option value="${v}" ${String(m[k] ?? '') === v ? 'selected' : ''}>${esc(t)}</option>`).join('')}</select></label>`).join('')}
      <label class="lbl">Spending shows as<select id="map-sign" class="in" data-map="sign"><option value="normal" ${imp.sign !== 'inverted' ? 'selected' : ''}>Negative (most banks)</option><option value="inverted" ${imp.sign === 'inverted' ? 'selected' : ''}>Positive (credit cards)</option></select></label>
      <label class="lbl">Dates look like<select id="map-dates" class="in" data-map="dateOrder"><option value="dmy" ${imp.dateOrder !== 'mdy' ? 'selected' : ''}>31/12/2026 (UK)</option><option value="mdy" ${imp.dateOrder === 'mdy' ? 'selected' : ''}>12/31/2026 (US)</option></select></label></div></details>` : ''}
    <div class="totals"><div><span>Lines</span><b>${imp.lines.length}</b></div><div><span>Ticked</span><b>${picked.length}</b></div><div><span>Out / In</span><b class="small"><span class="neg">${gbp(C.sum(picked.filter((l) => l.type === 'expense'), (l) => l.amount))}</span> / <span class="pos">${gbp(C.sum(picked.filter((l) => l.type === 'income'), (l) => l.amount))}</span></b></div></div>
    <div class="table-wrap"><table class="table edit"><thead><tr><th><input id="imp-all" type="checkbox" class="check" data-imp-all ${picked.length === imp.lines.length ? 'checked' : ''} aria-label="Tick all"></th><th>Date</th><th>Description</th><th class="r">Amount</th><th>Category</th><th>Sub</th><th></th></tr></thead><tbody>
    ${imp.lines.map((l, i) => `<tr class="${l.dup ? 'dim' : ''}"><td><input id="imp-${i}" type="checkbox" class="check" data-imp-pick="${i}" ${l.pick ? 'checked' : ''} aria-label="Import line"></td><td class="nowrap">${niceDate(l.date)}</td><td>${esc(l.description)}</td>
      <td class="r ${l.type === 'income' ? 'pos' : 'neg'}">${l.type === 'income' ? '+' : '−'}${gbp(l.amount)}</td>
      <td><select id="imp-cat-${i}" class="in mini" data-imp-cat="${i}" aria-label="Category">${cats(l.type).map((c) => `<option ${c === l.category ? 'selected' : ''}>${esc(c)}</option>`).join('')}</select></td>
      <td><input id="imp-sub-${i}" class="in mini" data-imp-sub="${i}" value="${esc(l.subcategory)}" aria-label="Subcategory"></td>
      <td>${l.dup === 'exact' ? '<span class="pill">already imported</span>' : l.dup === 'manual' ? '<span class="pill warn">you logged this</span>' : l.how === 'none' ? '<span class="pill">check</span>' : ''}</td></tr>`).join('')}
    </tbody></table></div>
    <label class="lbl inline"><input id="imp-remember" type="checkbox" class="check" data-imp-remember ${imp.remember !== false ? 'checked' : ''}> Remember category changes as rules for next time</label>
    <div class="row-btns"><button class="btn primary" data-action="imp-bank-commit" ${picked.length ? '' : 'disabled'}>Import ${picked.length} transaction${picked.length === 1 ? "" : "s"}</button><button class="btn ghost" data-action="imp-cancel">Cancel</button></div></section>`;
}

// Build the review model for a statement.
export function prepareBank(imp) {
  const s = S();
  let lines;
  if (imp.ofx) lines = IM.parseOFX(imp.text);
  else lines = IM.rowsToLines(imp.rows, imp.mapping, { sign: imp.sign, dateOrder: imp.dateOrder });
  const memory = IM.learnFromHistory(s.transactions);
  const acct = imp.accountId;
  const marked = IM.findDuplicates(lines, s.transactions, acct);
  const prev = new Map((imp.lines || []).map((l) => [l.key, l]));
  imp.lines = marked.map((l) => {
    const key = IM.importKey(acct, l);
    const old = prev.get(key);
    const c = IM.categorise(l, { rules: s.rules, memory });
    const category = catsOf(l.type).includes(c.category) ? c.category : catsOf(l.type).includes('Other') ? 'Other' : catsOf(l.type)[0];
    return old ? { ...l, ...old, dup: l.dup } : { ...l, key, category, subcategory: c.subcategory || '', how: c.how, pick: !l.dup, autoCat: category };
  });
}

export function prepareMM(rows) {
  const s = S();
  const parsed = IM.moneyManagerRows(rows);
  const existing = new Set(s.settings.sample ? [] : s.transactions.map((t) => t.importKey).filter(Boolean));
  const keyOf = (t) => `mm|${t.date}|${t.time}|${t.type}|${t.amount.toFixed(2)}|${t.account}|${t.note}|${t.category || t.toAccount}`;
  const seen = new Set();
  const fresh = [];
  let dupes = 0;
  for (const t of parsed) {
    let k = keyOf(t), n = 1;
    while (seen.has(k)) k = `${keyOf(t)}#${++n}`; // identical rows in the same minute are real
    seen.add(k);
    if (existing.has(k)) { dupes++; continue; }
    fresh.push({ ...t, importKey: k });
  }
  const accNames = new Set(s.settings.sample ? [] : s.accounts.map((a) => a.name.trim().toLowerCase()));
  const newAccounts = [...new Set(fresh.flatMap((t) => [t.account, t.toAccount]).filter(Boolean).map((a) => a.trim()))].filter((a) => !accNames.has(a.toLowerCase()) && !(s.settings.sample && ['cash', 'accounts', 'card', 'savings'].includes(a.toLowerCase())));
  const catNames = new Set(s.categories.map((c) => `${c.kind}|${c.name}`));
  const newCats = [...new Set(fresh.filter((t) => t.type !== 'transfer').map((t) => `${t.type}|${t.category}`))].filter((k) => !catNames.has(k)).map((k) => k.split('|')[1]);
  const dates = fresh.map((t) => t.date).sort();
  return {
    fresh, dupes, newAccounts, newCats: [...new Set(newCats)],
    from: dates[0], to: dates.at(-1),
    income: C.sum(fresh.filter((t) => t.type === 'income'), (t) => t.amount),
    expense: C.sum(fresh.filter((t) => t.type === 'expense'), (t) => t.amount),
    transfers: fresh.filter((t) => t.type === 'transfer').length,
  };
}

export function guessGroup(name) {
  const n = name.toLowerCase();
  if (/cash/.test(n)) return 'cash';
  if (/card|credit|amex|barclaycard/.test(n)) return 'card';
  if (/sav|hagbad|isa|pot/.test(n)) return 'savings';
  if (/pension|invest|sipp|shares|stock/.test(n)) return 'investment';
  if (/loan|finance|mortgage/.test(n)) return 'loan';
  if (/car|house|property/.test(n)) return 'other';
  return 'bank';
}

// ---------- SYNC ----------
function syncCard() {
  const sy = ctx.sync || {};
  const head = (pill) => `<div class="card-head"><h2>Sync between your phone and computer</h2>${pill}</div>`;
  if (!sy.available) return `<section class="card">${head('<span class="pill">Not available here</span>')}
    <p class="muted">Sync works when you open Money HQ from its claude.ai link while signed in. This copy keeps everything on this device; use Backup below to move data.</p></section>`;
  if (!sy.enabled) return `<section class="card">${head('<span class="pill">Off</span>')}
    <p>Open this same link on your phone and your computer and they stay in step. Everything is encrypted on this device with a sync passcode before it is stored, so the stored copy is unreadable without it.</p>
    <p class="muted small-print">First device: choose a sync passcode. Other devices: type the same one and your data loads. If you forget it, the synced copy can't be opened (your data on each device is still there).</p>
    <div class="form-row"><input id="sync1" class="in" type="password" autocomplete="new-password" placeholder="Sync passcode (6+ characters)" aria-label="Sync passcode"><input id="sync2" class="in" type="password" autocomplete="new-password" placeholder="Repeat it" aria-label="Repeat sync passcode"><button class="btn primary" data-action="sync-on">Turn on sync</button></div></section>`;
  const when = sy.lastSync ? new Date(sy.lastSync).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' }) : '';
  return `<section class="card">${head(`<span class="pill ${sy.status === 'error' ? 'over' : 'ok'}">${sy.status === 'error' ? 'Problem' : sy.status === 'saving' ? 'Saving…' : 'On'}</span>`)}
    <p>${sy.status === 'error' ? `<span class="neg">${esc(sy.error)}</span>` : `Encrypted and synced${when ? ` at ${when}` : ''}. Changes save a few seconds after you make them, and other devices update when you open the app.`}</p>
    <div class="row-btns"><button class="btn primary small" data-action="sync-now">Sync now</button><button class="btn ghost small" data-action="sync-off">Turn off on this device</button><button class="btn ghost small danger" data-action="sync-forget">${ctx.ui.confirmForget ? 'Tap again to delete the synced copy' : 'Delete synced copy'}</button></div></section>`;
}

// ---------- SETTINGS ----------
export function prefs() {
  const s = S();
  const used = new Map();
  s.transactions.forEach((t) => used.set(t.category, (used.get(t.category) || 0) + 1));
  const lockOn = ctx.ui.lockOn;
  const cats = (kind) => s.categories.filter((c) => c.kind === kind).sort((a, b) => (used.get(b.name) || 0) - (used.get(a.name) || 0));
  return `<header class="page-head"><div><p class="eyebrow">Settings</p><h1>Settings & privacy</h1></div></header>
  <section class="grid-2">
    <div class="card"><div class="card-head"><h2>You</h2></div>
      <label class="lbl">Your first name ${field('settings||name', s.settings.name, { label: 'Name' })}</label>
      <label class="lbl">Theme ${select('settings||theme', s.settings.theme, [['auto', 'Match my device'], ['dark', 'Dark'], ['light', 'Light']], { label: 'Theme' })}</label></div>
    <div class="card"><div class="card-head"><h2>Passcode lock</h2><span class="pill ${lockOn ? 'ok' : ''}">${lockOn ? 'On' : 'Off'}</span></div>
      <p class="muted">Encrypts everything stored on this device (AES-256). If you forget the passcode the data can't be recovered, so keep a backup.</p>
      <div class="form-row"><input id="pass1" class="in" type="password" autocomplete="new-password" placeholder="${lockOn ? 'New passcode' : 'Choose a passcode'}" aria-label="Passcode"><input id="pass2" class="in" type="password" autocomplete="new-password" placeholder="Repeat it" aria-label="Repeat passcode">
      <button class="btn primary small" data-action="set-pass">${lockOn ? 'Change' : 'Turn on'}</button>${lockOn ? '<button class="btn ghost small" data-action="clear-pass">Turn off</button>' : ''}</div></div>
  </section>
  ${syncCard()}
  <section class="card"><div class="card-head"><h2>Backup</h2><span class="muted">${s.settings.lastBackup ? `Last backup ${niceDate(s.settings.lastBackup)}` : 'No backup yet'}</span></div>
    <p>Your data lives only in this browser on this device. A backup file lets you move to a new phone or recover after clearing the browser. Save it somewhere private (your own Google Drive or iCloud).</p>
    <div class="row-btns"><button class="btn primary" data-action="backup">Download backup</button><button class="btn ghost" data-action="backup-copy">Copy backup text</button>
      <label class="btn ghost file-btn">Restore from backup<input id="restore-file" type="file" accept=".json,application/json" data-restore></label></div></section>
  <section class="grid-2">${['expense', 'income'].map((kind) => `<div class="card"><div class="card-head"><h2>${kind === 'expense' ? 'Expense' : 'Income'} categories</h2></div>
    ${cats(kind).map((c) => `<div class="line-edit"><input id="cat-${c.id}" class="in" value="${esc(c.name)}" data-rename-cat="${c.id}" aria-label="Category name"><span class="muted nowrap">${used.get(c.name) || 0} uses</span>${used.get(c.name) ? '' : delBtn('categories', c.id, 'category')}</div>`).join('')}
    ${addBtn('categories', 'Add category', { name: 'New category', kind })}
    <p class="muted">Renaming updates every transaction, budget line and rule. Type an existing name to merge two categories.</p></div>`).join('')}</section>
  <section class="card"><div class="card-head"><h2>Auto-categorise rules</h2><span class="muted">Used when importing statements</span></div>
    ${s.rules.map((r) => `<div class="line-edit">If description contains ${field(`rules|${r.id}|match`, r.match, { label: 'Text to match' })} → ${select(`rules|${r.id}|category`, r.category, [...catsOf('expense'), ...catsOf('income')], { label: 'Category' })}${field(`rules|${r.id}|subcategory`, r.subcategory, { placeholder: 'Sub', label: 'Subcategory', cls: 'short' })}${delBtn('rules', r.id, 'rule')}</div>`).join('') || '<p class="muted">No rules yet. They are added when you correct a category during import.</p>'}
    ${addBtn('rules', 'Add rule', { match: '', type: '', category: catsOf('expense')[0], subcategory: '' })}</section>
  <section class="card danger-zone"><div class="card-head"><h2>Start again</h2></div>
    ${s.settings.sample ? '<button class="btn" data-action="start-fresh">Clear example data</button>' : ''}
    ${ctx.ui.confirmWipe ? '<p class="neg"><b>This deletes every transaction, account, plan and goal on this device.</b></p><div class="row-btns"><button class="btn danger" data-action="wipe">Yes, delete everything</button><button class="btn ghost" data-toggle-ui="confirmWipe">Keep my data</button></div>' : '<button class="btn ghost danger" data-toggle-ui="confirmWipe">Delete all data…</button>'}</section>`;
}
