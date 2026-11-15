// Halal investing maths: zakat, AAOIFI-style screening, dividend purification.
// Pure functions — unit-tested in tests/halal.test.mjs. Not religious or financial advice.
import * as C from './calc.js';

export const NISAB_GRAMS = { gold: 87.48, silver: 612.36 };
export const ZAKAT_RATE = 0.025;

// Shortlist seeded on first use. Fees and tickers are deliberately left for the user to
// confirm on the provider factsheet: published figures disagree between sources.
export const SHORTLIST = [
  { name: 'iShares MSCI World Islamic ETF', ticker: 'ISWD', kind: 'ETF · developed markets', note: 'Global (ex-emerging) Shariah-screened index. Check the current fee on the iShares factsheet.' },
  { name: 'iShares MSCI USA Islamic ETF', ticker: 'ISUS', kind: 'ETF · US', note: 'US large caps, Shariah-screened. Heavier in tech.' },
  { name: 'iShares MSCI EM Islamic ETF', ticker: 'ISDE', kind: 'ETF · emerging markets', note: 'Emerging markets slice. Usually a smaller holding.' },
  { name: 'HSBC Islamic Global Equity Index Fund', ticker: '', kind: 'Index fund', note: 'A single-fund global option many UK platforms offer inside a Stocks & Shares ISA.' },
  { name: 'Wahed Invest (managed ISA)', ticker: '', kind: 'Managed halal portfolio', note: 'Hands-off halal ISA. Higher fee than DIY ETFs, but no picking needed.' },
];

export function zakat(z, { cash = 0, investments = 0 } = {}) {
  const metal = z.metal === 'gold' ? 'gold' : 'silver';
  const nisab = NISAB_GRAMS[metal] * C.num(z.gramPrice);
  const parts = [
    { label: 'Cash, bank and savings', value: Math.max(0, C.num(cash)) + C.num(z.extraCash) },
    { label: 'Gold and silver you own', value: C.num(z.gold) + C.num(z.silver) },
    { label: `Investments (${Math.round(C.num(z.investPct) * 100)}% counted)`, value: Math.max(0, C.num(investments)) * C.num(z.investPct) },
    { label: 'Money owed to you', value: C.num(z.owedToYou) },
    { label: 'Debts due now', value: -C.num(z.debtsDue) },
  ];
  const net = parts.reduce((t, p) => t + p.value, 0);
  const due = nisab > 0 && net >= nisab;
  return { metal, nisab: C.round2(nisab), parts, net: C.round2(net), due, amount: due ? C.round2(net * ZAKAT_RATE) : 0, priced: nisab > 0 };
}

// AAOIFI financial ratios, all measured against market value.
export function screen({ marketCap, debt, cashAndInterest, nonCompliantIncomePct }) {
  const cap = C.num(marketCap);
  const tests = [
    { name: 'Interest-bearing debt under 30% of market value', value: cap ? C.num(debt) / cap : null, limit: 0.3 },
    { name: 'Cash and interest-bearing deposits under 30% of market value', value: cap ? C.num(cashAndInterest) / cap : null, limit: 0.3 },
    { name: 'Non-compliant income under 5% of revenue', value: nonCompliantIncomePct === '' || nonCompliantIncomePct == null ? null : C.num(nonCompliantIncomePct) / 100, limit: 0.05 },
  ].map((t) => ({ ...t, pass: t.value == null ? null : t.value < t.limit }));
  const known = tests.filter((t) => t.pass != null);
  return { tests, verdict: known.length < 3 ? 'incomplete' : known.every((t) => t.pass) ? 'pass' : 'fail' };
}

export function purification(holdings) {
  const rows = holdings.map((h) => ({ name: h.name, amount: C.round2(C.num(h.dividends) * (C.num(h.nonCompliantPct) / 100)) })).filter((r) => r.amount > 0);
  return { rows, total: C.round2(rows.reduce((t, r) => t + r.amount, 0)) };
}

export function compliance(holdings) {
  const value = (h) => C.num(h.units) * C.num(h.price);
  const total = holdings.reduce((t, h) => t + value(h), 0);
  const by = { compliant: 0, doubtful: 0, not: 0, unchecked: 0 };
  for (const h of holdings) by[h.halal || 'unchecked'] += value(h);
  return { total, by, pct: total ? by.compliant / total : 0 };
}
