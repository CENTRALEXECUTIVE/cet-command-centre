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

    check(`${name} no script errors`, errs.length === 0, errs.slice(0, 3).join(' | '));
    await c.close();
  }
  await b.close();
  console.log(`${passes.length} passed, ${fails.length} failed`);
  fails.forEach((f) => console.log('FAIL', f));
  process.exit(fails.length ? 1 : 0);
})();
