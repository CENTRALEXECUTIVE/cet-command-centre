import test from 'node:test';
import assert from 'node:assert/strict';
import * as im from '../js/importers.js';

test('parseCSV handles quotes, commas and semicolons', () => {
  assert.deepEqual(im.parseCSV('a,b\n"x, y","he said ""hi"""\n'), [['a', 'b'], ['x, y', 'he said "hi"']]);
  assert.deepEqual(im.parseCSV('a;b\r\n1;2'), [['a', 'b'], ['1', '2']]);
});

test('UK dates are day-first', () => {
  assert.equal(im.parseDate('03/04/2026'), '2026-04-03');
  assert.equal(im.parseDate('12 Nov 2026'), '2026-11-12');
  assert.equal(im.parseDate('12-Nov-26'), '2026-11-12');
  assert.equal(im.parseDate('2026-11-12 08:30:00'), '2026-11-12');
  assert.equal(im.parseDate('20261112120000[0:GMT]'), '2026-11-12');
  assert.equal(im.parseDate(new Date(2026, 10, 12, 23, 30)), '2026-11-12');
  assert.equal(im.parseDate('nonsense'), null);
});

test('parseAmount handles £, commas, brackets and DR', () => {
  assert.equal(im.parseAmount('£1,234.50'), 1234.5);
  assert.equal(im.parseAmount('(12.00)'), -12);
  assert.equal(im.parseAmount('45.10 DR'), -45.1);
  assert.equal(im.parseAmount('-3.2'), -3.2);
});

test('Monzo CSV is detected and parsed', () => {
  const csv = 'Transaction ID,Date,Time,Type,Name,Emoji,Category,Amount,Currency,Local amount,Local currency,Notes and #tags,Address,Receipt,Description,Category split,Money Out,Money In\n' +
    'tx_1,12/11/2026,08:40:40,Card payment,Costa Coffee,☕,Eating out,-4.15,GBP,-4.15,GBP,,,,COSTA,,-4.15,\n' +
    'tx_2,12/11/2026,21:43:34,Faster payment,Bolt Operations,,Income,94.63,GBP,94.63,GBP,,,,BOLT,,,94.63\n';
  const rows = im.parseCSV(csv);
  const p = im.detectPreset(rows[0]);
  assert.equal(p.id, 'monzo');
  const lines = im.rowsToLines(rows.slice(1), im.resolveMapping(rows[0], p.map));
  assert.equal(lines.length, 2);
  assert.deepEqual([lines[0].type, lines[0].amount, lines[0].date], ['expense', 4.15, '2026-11-12']);
  assert.equal(lines[1].type, 'income');
});

test('Lloyds debit/credit columns', () => {
  const rows = im.parseCSV('Transaction Date,Transaction Type,Sort Code,Account Number,Transaction Description,Debit Amount,Credit Amount,Balance\n13/11/2026,DEB,\'30-00-00,123,TESCO STORES 2041,23.10,,500.00\n14/11/2026,FPI,\'30-00-00,123,UBER BV,,96.68,596.68');
  const p = im.detectPreset(rows[0]);
  assert.equal(p.id, 'lloyds');
  const lines = im.rowsToLines(rows.slice(1), im.resolveMapping(rows[0], p.map));
  assert.deepEqual(lines.map((l) => [l.type, l.amount]), [['expense', 23.1], ['income', 96.68]]);
});

test('Amex amounts are inverted (spend is positive)', () => {
  const rows = im.parseCSV('Date,Description,Amount\n12/11/2026,AMAZON UK,19.99\n13/11/2026,PAYMENT RECEIVED - THANK YOU,-200.00');
  const p = im.detectPreset(rows[0]);
  assert.equal(p.id, 'amex');
  const lines = im.rowsToLines(rows.slice(1), im.resolveMapping(rows[0], p.map), { sign: p.sign });
  assert.equal(lines[0].type, 'expense');
  assert.equal(lines[1].type, 'income');
});

test('unknown bank: header row found below preamble and columns guessed', () => {
  const rows = im.parseCSV('Account Name:,Joint\nAccount Balance:,£10\n\nDate,Details,Paid out,Paid in,Balance\n01/11/2026,EE LIMITED,38.50,,100\n');
  const hi = im.findHeaderRow(rows);
  assert.equal(hi, 2);
  const m = im.guessMapping(rows[hi]);
  const lines = im.rowsToLines(rows.slice(hi + 1), m);
  assert.deepEqual([lines[0].type, lines[0].amount, lines[0].description], ['expense', 38.5, 'EE LIMITED']);
});

test('OFX statements', () => {
  const ofx = '<OFX><STMTTRN><TRNTYPE>DEBIT<DTPOSTED>20261112<TRNAMT>-17.50<FITID>abc<NAME>MR TS<MEMO>card</STMTTRN><STMTTRN><TRNTYPE>CREDIT<DTPOSTED>20261113<TRNAMT>261.00<FITID>def<NAME>DEANNA</STMTTRN></OFX>';
  const l = im.parseOFX(ofx);
  assert.equal(l.length, 2);
  assert.deepEqual([l[0].date, l[0].type, l[0].amount, l[0].ref], ['2026-11-12', 'expense', 17.5, 'abc']);
});

test('Money Manager export rows (the real column layout)', () => {
  const rows = [
    ['Date', 'Account', 'Category', 'Subcategory', 'Note', 'GBP', 'Income/Expense', 'Tag', 'Description', 'Amount', 'Currency', 'Account'],
    [new Date(2026, 10, 15, 7, 30), 'Accounts', 'Business', '', 'Central job', 108, 'Income', '', 'Darren', '108.0', 'GBP', 108],
    [new Date(2026, 10, 14, 21, 46), 'Card', 'Charity', '', 'Sadaqah', 9.74, 'Expense', '', '', '9.74', 'GBP', 9.74],
    [new Date(2026, 10, 12), 'Accounts', 'Car', '', '', 200, 'Transfer-Out', '', '', '200', 'GBP', 200],
    [new Date(2026, 10, 10), 'Accounts', 'Other', '', 'Correction', -32.4, 'Income', '', '', '-32.4', 'GBP', -32.4],
  ];
  const out = im.moneyManagerRows(rows);
  assert.equal(out.length, 4);
  assert.equal(out[3].amount, -32.4, 'negative income nets off like Money Manager');
  assert.deepEqual([out[0].type, out[0].category, out[0].note, out[0].description, out[0].amount, out[0].time], ['income', 'Business', 'Central job', 'Darren', 108, '07:30']);
  assert.deepEqual([out[2].type, out[2].account, out[2].toAccount], ['transfer', 'Accounts', 'Car']);
  assert.throws(() => im.moneyManagerRows([['a', 'b']]));
});

test('categorise: rules beat history beat keywords', () => {
  const memory = im.learnFromHistory([{ type: 'expense', note: 'Costa coffee', category: 'Treats', subcategory: '' }]);
  assert.equal(im.categorise({ type: 'expense', description: 'COSTA COFFEE 123' }, { memory }).category, 'Treats');
  assert.equal(im.categorise({ type: 'expense', description: 'COSTA COFFEE 123' }, { memory, rules: [{ match: 'costa', category: 'Food' }] }).category, 'Food');
  assert.equal(im.categorise({ type: 'expense', description: 'TESCO STORES' }).subcategory, 'Groceries');
  assert.equal(im.categorise({ type: 'income', description: 'UBER BV' }).subcategory, 'Uber');
});

test('duplicates: exact re-import and matching manual entries', () => {
  const line = { date: '2026-11-13', type: 'expense', amount: 4.15, description: 'COSTA', ref: '' };
  const existing = [
    { id: 'm1', date: '2026-11-12', type: 'expense', amount: 4.15, note: 'Costa coffee' },
    { id: 'i1', date: '2026-11-01', type: 'expense', amount: 9, source: 'statement', importKey: im.importKey('acc', { date: '2026-11-01', type: 'expense', amount: 9, description: 'EE', ref: '' }) },
  ];
  const r = im.findDuplicates([line, { date: '2026-11-01', type: 'expense', amount: 9, description: 'EE', ref: '' }, { ...line, amount: 5 }], existing, 'acc');
  assert.deepEqual(r.map((x) => x.dup), ['manual', 'exact', null]);
  assert.equal(r[0].dupOf, 'm1');
});
