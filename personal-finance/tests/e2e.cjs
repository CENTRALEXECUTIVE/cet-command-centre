// End-to-end check of every screen and feature in a real browser.
// Run: (python3 -m http.server 8765 &) && node tests/e2e.cjs [baseUrl] [screenshotDir]
// Needs Playwright available to Node (not a dependency of this folder).
const { chromium } = require('playwright');
const fs = require('fs'), os = require('os'), path = require('path');
const BASE = process.argv[2] || 'http://localhost:8765';
const SHOTS = process.argv[3];
const ROUTES = ['home', 'insights', 'trans', 'stats', 'accounts', 'plan', 'goals', 'invest', 'debts', 'freedom', 'recurring', 'import', 'prefs'];
const fails = [], passes = [];
const check = (name, ok, extra = '') => (ok ? passes : fails).push(name + (ok ? '' : ' ' + extra));
const wait = (p, ms = 250) => p.waitForTimeout(ms);

(async () => {
  const b = await chromium.launch();
  for (const [name, viewport, colorScheme] of [['desk', { width: 1366, height: 900 }, 'dark'], ['phone', { width: 400, height: 860 }, 'light']]) {
    const c = await b.newContext({ viewport, colorScheme, deviceScaleFactor: SHOTS ? 2 : 1 });
    const p = await c.newPage();
    const errs = [];
    p.on('pageerror', (e) => errs.push(e.message));
    p.on('console', (m) => m.type() === 'error' && !/favicon|fonts\.g/.test(m.text()) && errs.push(m.text()));
    const go = async (r) => { await p.goto(`${BASE}/#${r}`); await wait(p, 350); };
    const text = (sel) => p.locator(sel).first().innerText();

    // 1. every screen renders, nothing overflows sideways
    for (const r of ROUTES) {
      await go(r);
      const ov = await p.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
      check(`${name} ${r} fits the screen`, ov <= 0, `overflow ${ov}px`);
      check(`${name} ${r} has a heading`, (await p.locator('.main h1').count()) === 1);
      if (SHOTS) await p.screenshot({ path: `${SHOTS}/${name}-${r}.png`, fullPage: name === 'desk' && ['home', 'insights'].includes(r) });
    }

    // 2. add, edit and delete a transaction
    await go('home');
    await p.click(name === 'phone' ? '.fab' : '.page-head [data-action=quick-add]');
    await p.fill('#tx-amount', '12.50'); await p.click('[data-tcat="🍜 Food"]'); await p.fill('#tx-note', 'E2E lunch');
    await p.click('#tx-form button[type=submit].primary'); await wait(p);
    check(`${name} add transaction`, (await text('.main')).includes('E2E lunch'));
    await go('trans');
    await p.fill('#tx-search', 'E2E lunch'); await wait(p, 500);
    check(`${name} search finds it`, (await p.locator('.tx-row').count()) === 1);
    await p.click('.tx-row'); await p.fill('#tx-amount', '15'); await p.click('#tx-form button[type=submit].primary'); await wait(p);
    check(`${name} edit transaction`, (await text('.tx-row')).includes('£15.00'));
    await p.click('.tx-row'); await p.click('[data-tdel]'); await p.click('[data-tdel]'); await wait(p);
    check(`${name} delete transaction`, (await p.locator('.tx-row').count()) === 0);
    await p.fill('#tx-search', ''); await wait(p, 400);
    await p.click('[data-ttab=calendar]'); check(`${name} calendar view`, (await p.locator('.cal-cell:not(.blank)').count()) >= 28);
    await p.click('[data-ttab=monthly]'); check(`${name} monthly view`, (await p.locator('tr.click').count()) >= 1);

    // 2b. subcategories: chips for the chosen category, saved on the entry, filterable
    await go('home');
    await p.click(name === 'phone' ? '.fab' : '.page-head [data-action=quick-add]');
    await p.fill('#tx-amount', '40'); await p.click('[data-tcat="🚖 Transport"]'); await wait(p, 150);
    check(`${name} subcategory chips shown`, (await p.locator('[data-tsub="Fuel"]').count()) === 1);
    await p.click('[data-tsub="Fuel"]'); await p.fill('#tx-note', 'E2E fuel'); await p.click('#tx-form button[type=submit].primary'); await wait(p);
    await go('trans'); await p.click('[data-ttab=daily]'); await p.fill('#tx-search', 'E2E fuel'); await wait(p, 500);
    check(`${name} subcategory saved`, (await text('.tx-row')).includes('Fuel'));
    await p.fill('#tx-search', ''); await p.selectOption('#tx-fcat', '🚖 Transport||Fuel'); await wait(p);
    check(`${name} filter by subcategory`, (await p.locator('.tx-row').count()) >= 1 && (await p.locator('.tx-row').allInnerTexts()).every((t) => t.includes('Fuel')));
    await p.selectOption('#tx-fcat', ''); await wait(p, 200);
    await go('prefs'); check(`${name} subcategories in settings`, (await p.locator('[data-rename-sub]').count()) >= 1);

    // 3. stats periods and drill-down
    await go('stats');
    for (const per of ['weekly', 'annually', 'taxyear', 'monthly']) { await p.selectOption('#stats-period', per); await wait(p, 150); }
    await p.click('.cat-line:not([disabled])'); await wait(p);
    check(`${name} stats drill-down`, (await p.locator('.main .card').count()) >= 3);

    // 4. ask: the three questions from the video
    await go('home');
    for (const [q, re] of [['Where did all my money go this month?', /You spent £/], ['Does anything look off?', /worth a look/], ["What's left before payday?", /safe to spend before payday/], ['how much did I earn from uber this year', /earned £[\d,.]+ from Uber/]]) {
      await p.fill('#ask-q', q); await p.press('#ask-q', 'Enter'); await wait(p);
      check(`${name} ask "${q}"`, re.test(await text('.answer-text')), await text('.answer-text'));
    }
    await p.click('[data-action=ask-clear]');

    // 5. insights: checks, dismiss, add subscription, payday settings, tax pot
    await go('insights');
    const kinds = await p.locator('.issue .pill').allInnerTexts();
    check(`${name} finds a double charge`, kinds.some((k) => /charged twice/i.test(k)), kinds.join());
    check(`${name} finds a fee`, kinds.some((k) => /fee/i.test(k)));
    check(`${name} finds a price rise`, kinds.some((k) => /price rise/i.test(k)));
    const before = await p.locator('.issue').count();
    await p.click('.issue [data-dismiss]'); await wait(p);
    check(`${name} dismiss an issue`, (await p.locator('.issue').count()) === before - 1);
    const recBefore = await p.evaluate(() => document.querySelectorAll('.issue [data-sub-add]').length);
    await p.click('.issue [data-sub-add]'); await wait(p);
    check(`${name} add subscription to Repeating`, (await p.locator('.issue [data-sub-add]').count()) === recBefore - 1);
    await p.selectOption('[data-bind="settings.payday||mode"]', 'monthly'); await wait(p);
    check(`${name} payday monthly setting`, (await p.locator('[data-bind="settings.payday||day"]').count()) === 1);
    check(`${name} safe-to-spend shown`, /£/.test(await text('.payday .hero-num')));
    check(`${name} money score shown`, /^\d+$/.test((await p.locator('.ring-big').first().textContent()).trim()));
    check(`${name} tax pot estimate`, /put aside by today/.test(await text('.main')));

    // 6. budget: build from real spending
    await go('plan');
    await p.click('[data-toggle-ui=budgetSuggest]'); await p.click('[data-action=budget-apply]'); await wait(p);
    check(`${name} budget built from history`, (await p.locator('.budget-line').count()) >= 4 && (await text('.main')).includes('Average month') === false || (await p.locator('.budget-line').count()) >= 4);

    // 7. repeating: log a due payment
    await go('recurring');
    if (await p.locator('[data-rec-log]').count()) { const n = await p.locator('[data-rec-log]').count(); await p.click('[data-rec-log]'); await wait(p); check(`${name} log a due bill`, (await p.locator('[data-rec-log]').count()) === n - 1); }

    // 8. planners accept input and recalculate
    await go('freedom'); const f0 = await text('.main h1');
    await p.fill('[data-bind="freedom||monthly"]', '3000'); await p.press('[data-bind="freedom||monthly"]', 'Tab'); await wait(p);
    check(`${name} freedom recalculates`, (await text('.main h1')) !== f0);
    await go('debts'); const d0 = await text('.main h1');
    await p.fill('[data-bind="debts||budget"]', '1500'); await p.press('[data-bind="debts||budget"]', 'Tab'); await wait(p);
    check(`${name} debt plan recalculates`, (await text('.main h1')) !== d0);
    await p.click('[data-value=snowball]'); await wait(p); check(`${name} snowball switch`, (await p.locator('.seg-btn.on').innerText()).includes('Snowball'));
    await go('goals'); await p.click('[data-add="goals.sinking"]'); await wait(p); check(`${name} add goal`, (await p.locator('[data-bind^="goals.sinking|"][data-bind$="|name"]').count()) === 4);
    await go('invest'); await p.click('[data-add=holdings]'); await wait(p); check(`${name} add holding`, (await p.locator('[data-bind^="holdings|"][data-bind$="|name"]').count()) === 3);
    await go('accounts'); await p.click('[data-toggle-ui=editAccounts]'); await p.fill('[data-setbal=bank]', '2500'); await p.press('[data-setbal=bank]', 'Tab'); await wait(p); await p.click('[data-toggle-ui=editAccounts]');
    check(`${name} set account balance`, (await text('.main')).includes('£2,500.00'));
    await p.click('[data-action=snapshot]'); await wait(p);

    // 9. bank statement import with duplicate detection
    const csv = path.join(os.tmpdir(), `mhq-${name}.csv`);
    const d = new Date(), dd = `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}/${d.getFullYear()}`;
    fs.writeFileSync(csv, `Transaction Date,Transaction Type,Sort Code,Account Number,Transaction Description,Debit Amount,Credit Amount,Balance\n${dd},DEB,x,1,TESCO STORES 2041,23.10,,500\n${dd},DEB,x,1,SHELL GARAGE,61.00,,439\n${dd},FPI,x,1,UBER BV,,96.68,535\n`);
    await go('import'); await p.setInputFiles('#bank-file', csv); await wait(p, 600);
    check(`${name} statement recognised`, /Lloyds/.test(await text('.card.highlight')));
    check(`${name} statement lines categorised`, (await p.locator('.card.highlight tbody tr').count()) === 3 && (await p.inputValue('#imp-sub-0')) === 'Groceries');
    await p.click('[data-action=imp-bank-commit]'); await wait(p, 500);
    await go('home');
    check(`${name} import clears example data`, (await p.locator('.banner').count()) === 0);
    await go('trans'); await p.click('[data-ttab=daily]'); await wait(p); check(`${name} imported entries listed`, (await p.locator('.tx-row').count()) === 3);
    await go('import'); await p.setInputFiles('#bank-file', csv); await wait(p, 600);
    check(`${name} re-import flagged as already imported`, (await p.locator('.card.highlight .pill').allInnerTexts()).filter((t) => /already/i.test(t)).length === 3);
    await p.click('[data-action=imp-cancel]');

    // 10. saved across reloads, passcode lock and unlock
    await p.reload(); await wait(p, 600); await go('trans');
    check(`${name} data survives reload`, (await p.locator('.tx-row').count()) === 3);
    await go('prefs'); await p.fill('#pass1', 'test1234'); await p.fill('#pass2', 'test1234'); await p.click('[data-action=set-pass]'); await wait(p, 900);
    await p.reload(); await wait(p, 600);
    check(`${name} locks on reload`, (await p.locator('#unlock-pass').count()) === 1);
    await p.fill('#unlock-pass', 'wrong'); await p.click('#unlock button'); await wait(p, 900);
    check(`${name} wrong passcode refused`, await p.locator('#unlock-err').isVisible());
    await p.fill('#unlock-pass', 'test1234'); await p.click('#unlock button'); await wait(p, 900);
    await go('trans'); check(`${name} unlock restores data`, (await p.locator('.tx-row').count()) === 3);

    // 11. split a payment, tag business, filter
    await go('home');
    await p.click(name === 'phone' ? '.fab' : '.page-head [data-action=quick-add]');
    await p.fill('#tx-amount', '60'); await p.fill('#tx-note', 'Costco run'); await p.click('[data-sp-on]');
    await p.fill('#sp-amt-0', '45'); await p.fill('#sp-amt-1', '15'); await p.check('#tx-biz');
    await p.click('#tx-form button[type=submit].primary'); await wait(p);
    await go('trans'); await p.click('[data-ttab=daily]'); await p.fill('#tx-search', 'Costco'); await wait(p, 500);
    check(`${name} split saved as parts`, (await p.locator('.tx-row').count()) === 2);
    await p.selectOption('#tx-fscope', 'business'); await wait(p);
    check(`${name} business filter`, (await p.locator('.tx-row').count()) === 2);
    await p.selectOption('#tx-fscope', 'personal'); await wait(p);
    check(`${name} personal filter`, (await p.locator('.tx-row').count()) === 0);
    await p.selectOption('#tx-fscope', ''); await p.fill('#tx-search', ''); await wait(p, 400);

    // 12. halal: zakat and screening
    await go('invest');
    await p.fill('[data-bind="zakat||gramPrice"]', '1'); await p.press('[data-bind="zakat||gramPrice"]', 'Tab'); await wait(p);
    await p.fill('[data-bind="zakat||gold"]', '5000'); await p.press('[data-bind="zakat||gold"]', 'Tab'); await wait(p);
    check(`${name} zakat worked out`, /Zakat due: £[\d,.]+/.test(await text('.main')));
    for (const [id, v] of [['#sc-cap', '1000'], ['#sc-debt', '100'], ['#sc-cash', '50'], ['#sc-inc', '2']]) { await p.fill(id, v); await p.press(id, 'Tab'); await wait(p, 120); }
    check(`${name} company screen`, /passes the financial screens/i.test(await text('.main')));
    check(`${name} halal shortlist`, (await p.locator('.short-row').count()) >= 5);
    await go('prefs'); check(`${name} sync card shown`, (await text('.main')).includes('Sync between your phone and computer'));

    check(`${name} no script errors`, errs.length === 0, errs.slice(0, 3).join(' | '));
    await c.close();
  }
  // 13. two devices syncing through a stand-in for the claude.ai page store
  {
    const store = new Map();
    const fake = `(() => {
      const mk = (path) => ({ id: path.split('/').pop(), path,
        get: async () => { const v = await window.__kv('get', path); return { exists: v != null, data: () => v }; },
        set: async (d) => { await window.__kv('set', path, d); }, delete: async () => { await window.__kv('del', path); } });
      const db = { collection: (c) => ({ doc: (n) => mk(c + '/' + n) }), doc: mk };
      window.claude = { use: async (n) => n === 'db' ? db : n === 'user' ? { id: async () => 'viewer-1' } : null };
    })();`;
    const dev = async (label) => {
      const c = await b.newContext({ viewport: { width: 400, height: 860 } });
      await c.exposeBinding('__kv', (_src, op, path, val) => { if (op === 'get') return store.get(path) ?? null; if (op === 'set') store.set(path, val); else store.delete(path); return null; });
      await c.addInitScript(fake);
      const p = await c.newPage(); const errs = []; p.on('pageerror', (e) => errs.push(e.message));
      return { c, p, errs, label };
    };
    const A = await dev('laptop'), B = await dev('phone');
    await A.p.goto(`${BASE}/#prefs`); await wait(A.p, 800);
    await A.p.click('[data-action=start-fresh]'); await wait(A.p);
    await A.p.goto(`${BASE}/#home`); await wait(A.p, 400);
    await A.p.click('.fab'); await A.p.fill('#tx-amount', '7.77'); await A.p.click('[data-tcat="🍜 Food"]'); await A.p.fill('#tx-note', 'Synced snack'); await A.p.click('#tx-form button[type=submit].primary'); await wait(A.p);
    await A.p.goto(`${BASE}/#prefs`); await wait(A.p, 600);
    await A.p.fill('#sync1', 'bismillah1'); await A.p.fill('#sync2', 'bismillah1'); await A.p.click('[data-action=sync-on]'); await wait(A.p, 2500);
    check('sync: encrypted copy stored', [...store.keys()].some((k) => k.endsWith('/vault')) && !JSON.stringify([...store.values()]).includes('Synced snack'));
    await B.p.goto(`${BASE}/#prefs`); await wait(B.p, 800);
    await B.p.fill('#sync1', 'wrongpass'); await B.p.fill('#sync2', 'wrongpass'); await B.p.click('[data-action=sync-on]'); await wait(B.p, 2000);
    check('sync: wrong passcode refused', (await B.p.locator('#sync1').count()) === 1);
    await B.p.fill('#sync1', 'bismillah1'); await B.p.fill('#sync2', 'bismillah1'); await B.p.click('[data-action=sync-on]'); await wait(B.p, 2500);
    await B.p.goto(`${BASE}/#trans`); await wait(B.p, 500);
    check('sync: phone receives laptop entry', (await B.p.locator('.main').innerText()).includes('Synced snack'));
    await B.p.click('.fab'); await B.p.fill('#tx-amount', '3.33'); await B.p.click('[data-tcat="🍜 Food"]'); await B.p.fill('#tx-note', 'Phone coffee'); await B.p.click('#tx-form button[type=submit].primary'); await wait(B.p, 4500);
    await A.p.goto(`${BASE}/#trans`); await wait(A.p, 300); await A.p.evaluate(() => document.dispatchEvent(new Event('visibilitychange'))); await wait(A.p, 2500);
    await A.p.goto(`${BASE}/#trans`); await wait(A.p, 500);
    check('sync: laptop receives phone entry', (await A.p.locator('.main').innerText()).includes('Phone coffee'));
    await A.p.reload(); await wait(A.p, 1500); await A.p.goto(`${BASE}/#prefs`); await wait(A.p, 500);
    check('sync: stays on after reload', (await A.p.locator('[data-action=sync-now]').count()) === 1);
    check('sync: no script errors', A.errs.length + B.errs.length === 0, [...A.errs, ...B.errs].join(' | '));
    await A.c.close(); await B.c.close();
  }
  // 14. a setup prepared by Claude, waiting in the private store, loads with one tap
  {
    const zlib = require('zlib');
    const setup = { app: 'money-hq', exported: new Date().toISOString(), state: { settings: { name: 'Tester' }, accounts: [{ id: 'bank', name: 'Accounts', group: 'bank', opening: 500 }], transactions: [{ id: 'x1', date: new Date().toISOString().slice(0, 10), type: 'expense', amount: 12.34, category: 'Other', note: 'Setup coffee', accountId: 'bank' }] } };
    const store = new Map([
      ['data/users/viewer-9/setup-meta', { count: 1, z: true, entries: 1 }],
      ['data/users/viewer-9/setup-c0', { d: zlib.gzipSync(Buffer.from(JSON.stringify(setup))).toString('base64') }],
    ]);
    const c = await b.newContext({ viewport: { width: 400, height: 860 } });
    await c.exposeBinding('__kv', (_s, op, path, val) => { if (op === 'get') return store.get(path) ?? null; if (op === 'set') store.set(path, val); else store.delete(path); return null; });
    await c.addInitScript(`(() => { const mk = (path) => ({ id: path.split('/').pop(), path, get: async () => { const v = await window.__kv('get', path); return { exists: v != null, data: () => v }; }, set: async (d) => { await window.__kv('set', path, d); }, delete: async () => { await window.__kv('del', path); } }); const db = { collection: (col) => ({ doc: (n) => mk(col + '/' + n) }), doc: mk }; window.claude = { use: async (n) => n === 'db' ? db : n === 'user' ? { id: async () => 'viewer-9' } : null }; })();`);
    const p = await c.newPage(); const errs = []; p.on('pageerror', (e) => errs.push(e.message));
    await p.goto(`${BASE}/#home`); await wait(p, 1500);
    check('setup: loads by itself over example data', (await p.locator('[data-action=setup-load]').count()) === 0);
    await p.goto(`${BASE}/#trans`); await wait(p, 500);
    check('setup: data loaded', (await p.locator('.main').innerText()).includes('Setup coffee'));
    check('setup: kept for your other devices', store.has('data/users/viewer-9/setup-c0') && store.has('data/users/viewer-9/setup-meta'));
    await p.goto(`${BASE}/#home`); await wait(p, 800);
    check('setup: example banner gone', (await p.locator('.banner').count()) === 0);
    store.set('data/users/viewer-9/updates', { ops: [{ op: 'setAsset', asset: 'Held by Mum', value: 6000 }, { op: 'setBalance', account: 'Accounts', balance: 777 }] });
    await p.reload(); await wait(p, 1500); await p.goto(`${BASE}/#accounts`); await wait(p, 600);
    const acctText = await p.locator('.main').innerText();
    check('updates: applied once and removed', acctText.includes('£777.00') && !store.has('data/users/viewer-9/updates'));
    check('setup: no script errors', errs.length === 0, errs.join(' | '));
    await c.close();
  }
  await b.close();
  console.log(`${passes.length} passed, ${fails.length} failed`);
  fails.forEach((f) => console.log('FAIL', f));
  process.exit(fails.length ? 1 : 0);
})();
