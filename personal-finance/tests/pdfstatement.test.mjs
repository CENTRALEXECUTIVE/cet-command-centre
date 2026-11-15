import test from 'node:test';
import assert from 'node:assert/strict';
import { parseStatementText, isOwnTransfer, statementHolder, tidyStatement } from '../js/importers.js';

// Made-up statement in the NatWest layout (not real data).
const SAMPLE = `Account Name                                   Account No Sort Code Page No
MR TEST PERSON                                 12345678   11-22-33  1 of 2
                                                                   Period Covered        16 DEC 2025 to 14 JAN 2026
                                                                   Previous Balance                  £1,000.00
                                                                   Paid In                             £350.00
                                                                   Withdrawn                           £245.50
                                                                   New Balance                       £1,104.50
 Date              Description                                     Paid In(£) Withdrawn(£)        Balance(£)
16 DEC 2025 BROUGHT FORWARD                                                                         1,000.00
18 DEC             Automated Credit UBER PAYMENTS UK L               200.00                         1,200.00
                   Card Transaction 9613 17DEC25 TESCO STORES
                   SHEFFIELD GB                                                       45.50          1,154.50
National Westminster Bank Plc. Registered in England and Wales No. 929027.
Account Name                                   Account No Sort Code Page No
 Date              Description                                     Paid In(£) Withdrawn(£)        Balance(£)
02 JAN             OnLine Transaction To Pot SAVING Via Mobile-Xfer                    200.00            954.50
05 JAN             Automated Credit CENTRAL EXECUTIVE JOHN SMITH FP
                   05/01/26 0822 A653889C9136469E9A                  150.00                         1,104.50`.split('\n');

test('parses a NatWest-style PDF statement and balances to the bank totals', () => {
  const r = parseStatementText(SAMPLE);
  assert.equal(r.lines.length, 4);
  assert.equal(r.balanced, true);
  assert.deepEqual(r.lines.map((l) => [l.date, l.type, l.amount]), [
    ['2025-12-18', 'income', 200], ['2025-12-18', 'expense', 45.5], ['2026-01-02', 'expense', 200], ['2026-01-05', 'income', 150],
  ]);
  assert.equal(r.lines[1].description, 'TESCO STORES SHEFFIELD GB', 'wrapped description joined, boilerplate removed');
  assert.equal(r.lines[3].description, 'CENTRAL EXECUTIVE JOHN SMITH');
  assert.equal(r.closing, 1104.5);
  assert.deepEqual(r.account, { number: '12345678', sortCode: '11-22-33' });
});

test('year rolls over inside a Dec→Jan statement', () => {
  const r = parseStatementText(SAMPLE);
  assert.equal(r.lines[2].date.slice(0, 4), '2026');
});

test('own transfers are recognised', () => {
  const holder = statementHolder(SAMPLE);
  assert.equal(holder, 'MR TEST PERSON');
  assert.equal(isOwnTransfer('OnLine Transaction To Pot SAVING Via Mobile-Xfer', holder), true);
  assert.equal(isOwnTransfer('OnLine Transaction ACC-NWESTPLAT 400000******0000', holder), true);
  assert.equal(isOwnTransfer('TEST PERSON TESTP REVOLUT', holder), true);
  assert.equal(isOwnTransfer('Card Transaction TESCO STORES', holder), false);
  assert.equal(isOwnTransfer('MUM MONZO TEST BILLS', holder), false);
});

test('tidyStatement strips bank boilerplate', () => {
  assert.equal(tidyStatement('Direct Debit RADIUS - UK FUELS'), 'RADIUS - UK FUELS');
  assert.equal(tidyStatement('Automated Credit BOLT OPERATIONS UK FP 18/05/26 1101'), 'BOLT OPERATIONS UK');
});
