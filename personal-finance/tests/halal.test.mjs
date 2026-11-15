import test from 'node:test';
import assert from 'node:assert/strict';
import * as H from '../js/halal.js';

test('zakat: below and above the silver nisab', () => {
  const z = { metal: 'silver', gramPrice: 1, gold: 0, silver: 0, investPct: 0.25, owedToYou: 0, debtsDue: 0, extraCash: 0 };
  const low = H.zakat(z, { cash: 500 });
  assert.equal(low.nisab, 612.36); assert.equal(low.due, false); assert.equal(low.amount, 0);
  const high = H.zakat({ ...z, gold: 1000, debtsDue: 500 }, { cash: 4000, investments: 8000 });
  assert.equal(high.net, 4000 + 1000 + 2000 - 500);
  assert.equal(high.due, true); assert.equal(high.amount, 162.5);
});

test('zakat needs a price before it can judge the nisab', () => {
  const r = H.zakat({ metal: 'gold', gramPrice: 0 }, { cash: 99999 });
  assert.equal(r.priced, false); assert.equal(r.due, false);
});

test('AAOIFI screen', () => {
  assert.equal(H.screen({ marketCap: 1000, debt: 100, cashAndInterest: 50, nonCompliantIncomePct: 2 }).verdict, 'pass');
  const f = H.screen({ marketCap: 1000, debt: 400, cashAndInterest: 50, nonCompliantIncomePct: 2 });
  assert.equal(f.verdict, 'fail'); assert.equal(f.tests[0].pass, false);
  assert.equal(H.screen({ marketCap: 1000, debt: 1 }).verdict, 'incomplete');
});

test('purification and compliance share', () => {
  const hs = [
    { name: 'A', units: 10, price: 10, halal: 'compliant', dividends: 40, nonCompliantPct: 2.5 },
    { name: 'B', units: 1, price: 100, halal: 'unchecked', dividends: 0 },
  ];
  assert.deepEqual(H.purification(hs), { rows: [{ name: 'A', amount: 1 }], total: 1 });
  const c = H.compliance(hs);
  assert.equal(c.total, 200); assert.equal(c.pct, 0.5);
});
