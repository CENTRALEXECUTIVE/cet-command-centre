import test from 'node:test';
import assert from 'node:assert/strict';
import * as I from '../js/insights.js';
import { blankState, sampleState } from '../js/store.js';
import * as C from '../js/calc.js';

const NOW = '2026-11-15';
let n = 0;
const tx = (date, type, amount, category, note, accountId = 'card', extra = {}) => ({ id: 't' + ++n, date, type, amount, category, note, accountId, subcategory: '', description: '', ...extra });
function base() {
  const s = blankState();
  s.accounts.find((a) => a.id === 'bank').opening = 1000;
  s.accounts.find((a) => a.id === 'cash').opening = 200;
  return s;
}

test('detects a monthly subscription and a price rise, ignores irregular spending', () => {
  const t = [
    ...['2026-07-12', '2026-08-12', '2026-09-12', '2026-10-12'].map((d) => tx(d, 'expense', 38.5, 'Household', 'EE SIM')),
    tx('2026-11-12', 'expense', 42, 'Household', 'EE SIM'),
    tx('2026-08-01', 'expense', 12, 'Food', 'Costa'), tx('2026-08-03', 'expense', 4, 'Food', 'Costa'), tx('2026-10-20', 'expense', 6, 'Food', 'Costa'),
  ];
  const subs = I.detectSubscriptions(t, [], NOW);
  assert.equal(subs.length, 1);
  assert.deepEqual([subs[0].name, subs[0].freq, subs[0].amount, subs[0].previous, subs[0].priceRise, subs[0].listed], ['EE SIM', 'monthly', 42, 38.5, true, false]);
  assert.equal(subs[0].nextDate, '2026-12-12');
  assert.equal(I.detectSubscriptions(t, [{ name: 'EE sim' }], NOW)[0].listed, true);
});

test('a subscription that stopped is not reported', () => {
  const t = ['2026-01-05', '2026-02-05', '2026-03-05'].map((d) => tx(d, 'expense', 9.99, 'Household', 'Netflix'));
  assert.equal(I.detectSubscriptions(t, [], NOW).length, 0);
});

test('findIssues: double charge, fee, unusual amount, unlisted subscription, and dismissing', () => {
  const s = base();
  s.transactions = [
    tx('2026-11-08', 'expense', 125.19, 'Social', 'Trainline'), tx('2026-11-09', 'expense', 125.19, 'Social', 'Trainline'),
    tx('2026-11-10', 'expense', 12, 'Other', 'Late payment fee'),
    ...['2026-06-01', '2026-07-03', '2026-08-02', '2026-09-04'].map((d) => tx(d, 'expense', 50, 'Transport', 'Fuel')),
    tx('2026-11-11', 'expense', 180, 'Transport', 'Fuel'),
    ...['2026-08-20', '2026-09-20', '2026-10-20'].map((d) => tx(d, 'expense', 65, 'Household', 'IPTV')),
    tx('2026-11-05', 'expense', 4, 'Food', 'Costa'), tx('2026-11-05', 'expense', 4, 'Food', 'Costa'), // small repeats are normal
  ];
  const issues = I.findIssues(s, NOW);
  const kinds = issues.map((i) => i.kind).sort();
  assert.deepEqual(kinds, ['duplicate', 'fee', 'subscription', 'unusual']);
  assert.equal(issues[0].kind, 'duplicate', 'most serious first');
  s.settings.dismissed = [issues[0].id];
  assert.equal(I.findIssues(s, NOW).some((i) => i.kind === 'duplicate'), false);
});

test('nextPayday: weekly, monthly (clamped) and never today', () => {
  assert.equal(I.nextPayday({ mode: 'weekly', weekday: 2 }, '2026-11-15'), '2026-11-17'); // Sunday → Tuesday
  assert.equal(I.nextPayday({ mode: 'weekly', weekday: 0 }, '2026-11-15'), '2026-11-22'); // today is Sunday → next Sunday
  assert.equal(I.nextPayday({ mode: 'monthly', day: 28 }, '2026-11-15'), '2026-11-28');
  assert.equal(I.nextPayday({ mode: 'monthly', day: 10 }, '2026-11-15'), '2026-12-10');
  assert.equal(I.nextPayday({ mode: 'monthly', day: 31 }, '2027-02-10'), '2027-02-28');
});

test('payday: balance minus bills still to come', () => {
  const s = base();
  s.settings.payday = { mode: 'monthly', day: 28 };
  s.recurring = [
    { id: 'r1', name: 'Insurance', type: 'expense', amount: 185, freq: 'monthly', next: '2026-11-20' },
    { id: 'r2', name: 'Gym', type: 'expense', amount: 22, freq: 'weekly', next: '2026-11-13' }, // overdue + 20th + 27th
    { id: 'r3', name: 'Rent', type: 'expense', amount: 900, freq: 'monthly', next: '2026-12-01' }, // after payday
    { id: 'r4', name: 'School run', type: 'income', amount: 300, freq: 'monthly', next: '2026-11-25' },
  ];
  s.transactions = [tx('2026-11-14', 'expense', 100, 'Food', 'Shop', 'bank'), tx('2026-11-14', 'expense', 999, 'Food', 'Card spend', 'card')];
  const p = I.payday(s, NOW);
  assert.equal(p.date, '2026-11-28');
  assert.equal(p.days, 13);
  assert.equal(p.available, 1100); // bank 900 + cash 200; card not counted
  assert.equal(p.billsTotal, 185 + 66);
  assert.equal(p.safe, 1100 - 251);
  assert.equal(p.incomingTotal, 300);
  assert.equal(p.perDay, C.round2(849 / 13));
});

test('tax: bands and National Insurance', () => {
  assert.deepEqual(I.taxOn(12570), { tax: 0, ni: 0, total: 0 });
  const a = I.taxOn(30000);
  assert.equal(a.tax, 3486); assert.equal(a.ni, 1045.8);
  const b = I.taxOn(60000);
  assert.equal(b.tax, 7540 + 3892); assert.equal(b.ni, C.round2(37700 * 0.06 + 9730 * 0.02));
});

test('taxEstimate uses the UK tax year and chosen categories only', () => {
  const s = base();
  s.settings.tax = { income: ['Business'], costs: ['BUSINESS'] };
  s.transactions = [
    tx('2026-04-05', 'income', 5000, 'Business', 'Old year'),
    tx('2026-05-01', 'income', 20000, 'Business', 'Jobs'), tx('2026-06-01', 'income', 999, 'Gift', 'Not trade'),
    tx('2026-06-01', 'expense', 2000, 'BUSINESS', 'Ads'), tx('2026-06-02', 'expense', 500, 'Food', 'Lunch'),
  ];
  const t = I.taxEstimate(s, NOW);
  assert.equal(t.label, '2026/27');
  assert.deepEqual([t.income, t.costs, t.profit], [20000, 2000, 18000]);
  assert.ok(t.projectedProfit > t.profit && t.dueSoFar > 0 && t.dueSoFar < t.projected.total);
  assert.equal(I.taxEstimate(base(), NOW).configured, false);
});

test('suggestBudget averages the last full months', () => {
  const t = [tx('2026-10-05', 'expense', 300, 'Food', 'x'), tx('2026-09-05', 'expense', 100, 'Food', 'x'), tx('2026-08-05', 'expense', 200, 'Food', 'x'), tx('2026-11-05', 'expense', 9999, 'Food', 'this month ignored'), tx('2026-10-01', 'income', 3000, 'Salary', 'Uber')];
  const b = I.suggestBudget(t, NOW);
  assert.deepEqual(b.expenses, [{ category: 'Food', monthly: 200 }]);
  assert.equal(b.income[0].monthly, 1000);
});

test('money score stays within 0–100 with sensible parts', () => {
  const h = I.healthScore(sampleState(NOW), NOW);
  assert.ok(h.score >= 0 && h.score <= 100);
  assert.equal(h.parts.length, 4);
  assert.ok(I.healthScore(blankState(), NOW).score >= 0);
});

test('parsePeriod understands everyday phrases', () => {
  assert.deepEqual([I.parsePeriod('last month', NOW).from, I.parsePeriod('last month', NOW).to], ['2026-10-01', '2026-10-31']);
  assert.equal(I.parsePeriod('in march', NOW).from, '2026-03-01');
  assert.equal(I.parsePeriod('in december', NOW).from, '2025-12-01', 'a month still to come means last year');
  assert.equal(I.parsePeriod('this year', NOW).from, '2026-01-01');
  assert.equal(I.parsePeriod('this tax year', NOW).from, '2026-04-06');
  assert.equal(I.parsePeriod('this week', NOW).from, '2026-11-09');
  assert.equal(I.parsePeriod('how much on food', NOW).isDefault, true);
});

test('answer: the three questions from the video and specific lookups', () => {
  const s = base();
  s.categories.push({ id: 'x', name: '🍜 Food', kind: 'expense' });
  s.settings.payday = { mode: 'monthly', day: 28 };
  s.transactions = [
    tx('2026-11-02', 'expense', 60, '🍜 Food', 'Tesco'), tx('2026-11-03', 'expense', 40, '🚖 Transport', 'Fuel'),
    tx('2026-10-03', 'expense', 25, '🍜 Food', 'Tesco'),
    tx('2026-11-04', 'income', 500, '💰 Salary', 'Uber', 'bank'), tx('2026-03-04', 'income', 250, '💰 Salary', 'Uber', 'bank'), tx('2026-11-05', 'income', 100, '💰 Salary', 'Bolt', 'bank'),
  ];
  const where = I.answer('Where did all my money go this month?', s, NOW);
  assert.match(where.text, /£100\.00 this month/); assert.equal(where.rows[0].label, '🍜 Food');
  assert.match(I.answer('Does anything look off?', s, NOW).text, /Nothing looks off/);
  assert.match(I.answer("What's left before payday?", s, NOW).text, /safe to spend before payday on 28 Nov 2026/);
  assert.match(I.answer('how much did I spend on food last month', s, NOW).text, /£25\.00 on Food in October/);
  assert.match(I.answer('how much did I earn from uber this year?', s, NOW).text, /earned £750\.00 from Uber in 2026/);
  assert.match(I.answer('how much have I made this month', s, NOW).text, /earned £600\.00 this month/);
  assert.match(I.answer('what did I spend at tesco in total', s, NOW).text, /£85\.00 at Tesco in total/);
  assert.match(I.answer('what is my net worth', s, NOW).text, /net worth is/);
  assert.equal(I.answer('tell me a joke', s, NOW).unknown, true);
});

test('AI context contains summaries, not the whole history', () => {
  const s = sampleState(NOW);
  const c = I.contextForAI(s, NOW);
  assert.ok(c.recentTransactions.length <= 60);
  assert.ok(JSON.stringify(c).length < 30000);
});

test('applyUpdates sets balances, goals, debts and assets by name', () => {
  const s = base();
  s.accounts.push({ id: 'hb', name: 'Hagbad', group: 'savings', opening: 0 });
  s.transactions = [{ id: 'a', date: '2026-01-01', type: 'transfer', amount: 5500, accountId: 'cash', toAccountId: 'hb' }];
  s.goals.sinking = [{ id: 'g', name: 'Wedding', target: 30000, saved: 0 }];
  s.debts.items = [{ id: 'd', name: 'Virgin', balance: 0, rate: 0, min: 185 }];
  const notes = I.applyUpdates(s, [{ op: 'setBalance', account: 'hagbad', balance: 6000 }, { op: 'setGoal', goal: 'Wedding', saved: 12000 }, { op: 'setDebt', debt: 'Virgin', balance: 1870.25 }, { op: 'setAsset', asset: 'Held by Mum', value: 6000 }, { op: 'setBalance', account: 'Nope', balance: 1 }]);
  assert.equal(C.accountBalances(s.accounts, s.transactions).hb, 6000);
  assert.equal(s.goals.sinking[0].saved, 12000);
  assert.equal(s.debts.items[0].balance, 1870.25);
  assert.equal(s.networth.assets.at(-1).value, 6000);
  assert.equal(notes.length, 4);
});

test('applyUpdates adds missed transactions once, creating categories and subcategories', () => {
  const s = base();
  const op = { op: 'addTransactions', account: 'Card', items: [
    { date: '2026-07-23', amount: 24.99, category: '🧘🏼 Health', subcategory: 'Gym', note: 'Wellhub', key: 'card|1' },
    { date: '2026-05-12', amount: -60, category: '🚖 Transport', subcategory: 'Services & Repairs', note: 'Refund', key: 'card|2' },
    { date: '2026-07-16', amount: 10, category: 'Brand new', note: 'Test', key: 'card|3' },
  ] };
  const n1 = I.applyUpdates(s, [op]);
  assert.match(n1[0], /^3 missed card entries added \(£-25\.01 out\)$/);
  assert.equal(s.transactions.length, 3);
  assert.ok(s.categories.some((c) => c.name === 'Brand new' && c.kind === 'expense'));
  assert.ok(s.categories.find((c) => c.name === '🧘🏼 Health').subs.includes('Gym'));
  I.applyUpdates(s, [op]);
  assert.equal(s.transactions.length, 3, 'second time adds nothing');
});

test('applyUpdates moves money into a named account, creating it once', () => {
  const s = base();
  const op = { op: 'addTransactions', account: 'Accounts', items: [
    { date: '2025-10-08', amount: 10990, type: 'transfer', toAccount: 'Car', toGroup: 'other', note: 'Mercedes', key: 'b|1' },
    { date: '2025-12-03', amount: 1400, type: 'transfer', toAccount: 'Loans to family & friends', note: 'Loan', key: 'b|2' },
  ] };
  I.applyUpdates(s, [op]); I.applyUpdates(s, [op]);
  const car = s.accounts.find((a) => a.name === 'Car');
  const bal = C.accountBalances(s.accounts, s.transactions);
  assert.equal(bal[car.id], 10990);
  assert.equal(s.accounts.filter((a) => a.name === 'Loans to family & friends').length, 1);
  assert.equal(bal.bank, 1000 - 10990 - 1400);
});

test('applyUpdates corrects entries by key and removes an emptied account', () => {
  const s = base();
  I.applyUpdates(s, [{ op: 'addTransactions', account: 'Accounts', items: [{ date: '2025-12-03', amount: 1400, type: 'transfer', toAccount: 'Loans', note: 'Loan', key: 'b|9' }] }]);
  const before = C.accountBalances(s.accounts, s.transactions).bank;
  const notes = I.applyUpdates(s, [{ op: 'editTransactions', items: [{ key: 'b|9', set: { type: 'expense', category: 'Family support', subcategory: 'Eedo Kawsar', note: 'Eedo Kawsar' } }] }, { op: 'removeAccount', account: 'Loans' }]);
  assert.deepEqual(notes, ['1 entry corrected', 'Loans removed']);
  const t = s.transactions.find((x) => x.importKey === 'b|9');
  assert.deepEqual([t.type, t.category, t.toAccountId], ['expense', 'Family support', undefined]);
  assert.equal(C.accountBalances(s.accounts, s.transactions).bank, before, 'bank balance unchanged');
  assert.ok(!s.accounts.some((a) => a.name === 'Loans'));
});

test('applyUpdates replaces the budget plan', () => {
  const s = base();
  const n = I.applyUpdates(s, [{ op: 'setBudget', income: [{ name: 'Central jobs', amount: 4000 }], items: [{ group: 'fixed', section: 'Home', name: 'Mum bills', amount: 1000, category: 'Household' }], allocation: [{ name: 'Tax pot', amount: 600 }] }]);
  const b = C.budgetSummary(s.budget);
  assert.deepEqual([b.income, b.outgoing, b.allocated], [4000, 1000, 600]);
  assert.equal(n[0], 'Budget plan updated to your real averages');
});
