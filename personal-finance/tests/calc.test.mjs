import test from 'node:test';
import assert from 'node:assert/strict';
import * as c from '../js/calc.js';

test('addMonths clamps month ends', () => {
  assert.equal(c.addMonths('2026-01-31', 1), '2026-02-28');
  assert.equal(c.addMonths('2026-11-15', 12), '2027-11-15');
  assert.equal(c.addMonths('2026-03-10', -3), '2025-12-10');
});

test('UK tax year starts 6 April', () => {
  assert.equal(c.taxYear('2026-04-05').label, '2025/26');
  assert.equal(c.taxYear('2026-04-06').label, '2026/27');
  assert.equal(c.taxYear('2026-11-15').start, '2026-04-06');
});

test('futureValue with and without growth', () => {
  assert.equal(c.futureValue(1000, 100, 0, 12), 2200);
  const fv = c.futureValue(0, 100, 0.12, 12);
  assert.ok(Math.abs(fv - 1268.25) < 0.01, String(fv));
});

test('financial freedom: 5000/month at 4% needs 1.5m', () => {
  const f = c.freedom({ monthlyNeed: 5000, current: 620, monthly: 400, rate: 0.1 }, '2026-11-15');
  assert.equal(f.goal, 1500000);
  assert.ok(f.years > 25 && f.years < 40, String(f.years));
  assert.match(f.date, /^20\d\d-/);
});

test('freedom returns null when never reachable', () => {
  const f = c.freedom({ monthlyNeed: 5000, current: 0, monthly: 0, rate: 0 }, '2026-11-15');
  assert.equal(f.months, null);
});

test('sinking fund maths', () => {
  const s = c.sinkingFund({ target: 30000, saved: 2000, monthly: 560, date: '2029-01-01' }, '2026-11-15');
  assert.equal(s.months, 25);
  assert.equal(s.byDate, 2000 + 560 * 25);
  assert.ok(Math.abs(s.needPerMonth - 28000 / 25) < 0.01);
  assert.equal(s.onTrack, false);
});

test('debt plan: avalanche pays highest rate first and beats snowball on interest', () => {
  const debts = [
    { id: 'a', name: 'Card', balance: 3000, rate: 24.9, min: 90 },
    { id: 'b', name: 'Loan', balance: 1000, rate: 6, min: 50 },
  ];
  const av = c.debtPlan(debts, 400, 'avalanche', '2026-11-15');
  const sn = c.debtPlan(debts, 400, 'snowball', '2026-11-15');
  assert.ok(av.months > 0 && sn.months > 0);
  assert.ok(av.totalInterest <= sn.totalInterest);
  assert.ok(sn.debts.find((d) => d.id === 'b').paidOffMonth < sn.debts.find((d) => d.id === 'a').paidOffMonth);
  assert.equal(av.schedule.at(-1).total, 0);
});

test('debt plan flags a payment that never clears the debt', () => {
  const p = c.debtPlan([{ name: 'x', balance: 10000, rate: 30, min: 100 }], 100, 'avalanche', '2026-11-15');
  assert.equal(p.stuck, true);
  assert.equal(p.date, null);
});

test('monthly totals, categories and balances', () => {
  const tx = [
    { date: '2026-11-01', type: 'income', amount: 100, category: 'Salary', accountId: 'a' },
    { date: '2026-11-02', type: 'expense', amount: 30, category: 'Food', accountId: 'a' },
    { date: '2026-11-03', type: 'expense', amount: 20, category: 'Food', accountId: 'b' },
    { date: '2026-11-04', type: 'transfer', amount: 10, accountId: 'a', toAccountId: 'b' },
    { date: '2026-10-04', type: 'expense', amount: 99, category: 'Food', accountId: 'a' },
  ];
  assert.deepEqual(c.monthTotals(tx, '2026-11'), { income: 100, expense: 50, net: 50 });
  assert.deepEqual(c.byCategory(tx, { from: '2026-11-01', to: '2026-11-30' }), [{ name: 'Food', total: 50 }]);
  assert.deepEqual(c.accountBalances([{ id: 'a', opening: 0 }, { id: 'b', opening: 5 }], tx), { a: -39, b: -5 });
});

test('recurring occurrences catch up missed months', () => {
  const r = c.dueOccurrences({ next: '2026-09-08', freq: 'monthly' }, '2026-11-15');
  assert.deepEqual(r.dates, ['2026-09-08', '2026-10-08', '2026-11-08']);
  assert.equal(r.next, '2026-12-08');
});

test('budget summary matches the AutoPilot sheet', () => {
  const s = c.budgetSummary({
    income: [{ amount: 3450 }, { amount: 1333 }],
    items: [{ group: 'fixed', amount: 2016 }, { group: 'variable', amount: 1358.33 }, { group: 'optional', amount: 190 }],
    allocation: [{ amount: 749.48 }, { amount: 377.79 }],
  });
  assert.equal(s.income, 4783);
  assert.ok(Math.abs(s.surplus - 1218.67) < 0.001);
  assert.ok(Math.abs(s.unallocated - 91.4) < 0.001);
});

test('frequent entries rank by count', () => {
  const tx = [
    { type: 'income', category: 'Salary', note: 'Bolt', amount: 90, date: '2026-11-01' },
    { type: 'income', category: 'Salary', note: 'bolt', amount: 95, date: '2026-11-02' },
    { type: 'expense', category: 'Food', note: 'Costa', amount: 4, date: '2026-11-02' },
  ];
  const f = c.frequentEntries(tx);
  assert.equal(f[0].note, 'Bolt');
  assert.equal(f[0].count, 2);
});
