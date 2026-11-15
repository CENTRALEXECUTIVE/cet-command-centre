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

import { parseTransactionListText, parseCardStatementText, parseAnyStatement } from '../js/importers.js';

// Made-up NatWest online transaction list (newest first, wrapped descriptions).
const LIST = `                                                                           Account name: TEST P
Showing: 01 Oct 2026 to 05 Oct 2026, All Transactions
Date          Type    Description                                Paid in     Paid out          Balance
05 Oct 2026   BAC     UBER PAYMENTS UK L                         £300.00                         £1,150.00
                      9613 03OCT26 , TESCO STORES , SHEFFIELD
04 Oct 2026   POS                                                               £50.00           £850.00
                      GB
                      MUM MONZO , TEST BILL , VIA MOBILE -
03 Oct 2026   DPC     PYMT , FP 03/10/26 10 ,                                  £100.00           £900.00
                      02165853701441000N
02 Oct 2026   D/D     EE LIMITED                                                £38.50          £1,000.00
01 Oct 2026   BAC     CENTRAL EXECUTIVE                          £88.50                         £1,038.50
Page 1 of 1`.split('\n');

test('NatWest online transaction list: order, wrapped text and balances', () => {
  const r = parseTransactionListText(LIST);
  assert.equal(r.kind, 'list');
  assert.equal(r.lines.length, 5);
  assert.deepEqual(r.lines.map((l) => [l.date, l.type, l.amount]), [
    ['2026-10-01', 'income', 88.5], ['2026-10-02', 'expense', 38.5], ['2026-10-03', 'expense', 100], ['2026-10-04', 'expense', 50], ['2026-10-05', 'income', 300],
  ]);
  assert.equal(r.lines[3].description, '9613 03OCT26 TESCO STORES SHEFFIELD GB');
  assert.match(r.lines[2].description, /^MUM MONZO TEST BILL/);
  assert.equal(r.balanced, true);
  assert.equal(r.closing, 1150);
});

// Made-up credit card statement in the NatWest Mastercard layout.
const CARD = `                                                       Cardholder          MR TEST PERSON
                                                       MasterCard Number      0000 0000 0000 0000
                                                       Total Credit Limit                 £5,000
                                                       Summary                             04 January 2027
                                                       Balance brought forward from
                                                                                          £100.00
                                                       previous statement
                                                       Payments to your account          £100.00 -
                                                       Spending on your account
                                                                                        + £55.00
                                                       New Balance                       = £55.00
Trans   Post
Date    Date                Description                                                 Amount
                            BALANCE FROM PREVIOUS STATEMENT                              £100.00
20 DEC 20 DEC   01206644    FASTER PAYMENT RECEIVED - THANK YOU                            100.00 -
                                                             Sub-Total                     100.00 -
28 DEC 29 DEC   09492433    ACORN INSURANCE FORMBY ENG                                      45.00
02 JAN 03 JAN   20450045    COSTA COFFEE SHEFFIELD                                          10.00`.split('\n');

test('credit card statement: year rollover, payments marked, summary check', () => {
  const r = parseCardStatementText(CARD);
  assert.equal(r.kind, 'card');
  assert.deepEqual(r.lines.map((l) => [l.date, l.type, l.amount, !!l.cardPayment]), [
    ['2026-12-20', 'income', 100, true], ['2026-12-28', 'expense', 45, false], ['2027-01-02', 'expense', 10, false],
  ]);
  assert.equal(r.balanced, true);
  assert.equal(r.closing, -55, 'card balance is money owed');
});

test('parseAnyStatement picks the right reader', () => {
  assert.equal(parseAnyStatement(LIST).kind, 'list');
  assert.equal(parseAnyStatement(CARD).kind, 'card');
  assert.equal(parseAnyStatement(SAMPLE).kind, 'statement');
});
