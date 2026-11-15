// Pure import logic: CSV/OFX statement parsing, Money Manager rows, auto-categorising,
// duplicate detection. No DOM — unit-tested in tests/importers.test.mjs.
import { num, round2, isoDate, addDays } from './calc.js';

// ---------- CSV ----------
export function detectDelimiter(text) {
  const head = text.split(/\r?\n/).slice(0, 5).join('\n');
  const counts = [',', ';', '\t', '|'].map((d) => [d, (head.match(new RegExp(d === '|' ? '\\|' : d, 'g')) || []).length]);
  counts.sort((a, b) => b[1] - a[1]);
  return counts[0][1] ? counts[0][0] : ',';
}

export function parseCSV(text, delimiter) {
  text = String(text).replace(/^﻿/, '');
  const d = delimiter || detectDelimiter(text);
  const rows = [];
  let row = [], field = '', q = false;
  for (let i = 0; i < text.length; i++) {
    const c = text[i];
    if (q) {
      if (c === '"') { if (text[i + 1] === '"') { field += '"'; i++; } else q = false; }
      else field += c;
    } else if (c === '"') q = true;
    else if (c === d) { row.push(field); field = ''; }
    else if (c === '\n' || c === '\r') {
      if (c === '\r' && text[i + 1] === '\n') i++;
      row.push(field); field = '';
      if (row.some((x) => x.trim() !== '')) rows.push(row);
      row = [];
    } else field += c;
  }
  row.push(field);
  if (row.some((x) => x.trim() !== '')) rows.push(row);
  return rows.map((r) => r.map((x) => x.trim()));
}

// Find the header row (some banks put account info above it).
export function findHeaderRow(rows) {
  const keys = /date|amount|description|paid|debit|credit|details|memo|name|transaction/i;
  for (let i = 0; i < Math.min(rows.length, 15); i++) {
    const hits = rows[i].filter((c) => keys.test(c)).length;
    if (hits >= 2) return i;
  }
  return 0;
}

// ---------- dates & amounts ----------
const MONTHS = { jan: 1, feb: 2, mar: 3, apr: 4, may: 5, jun: 6, jul: 7, aug: 8, sep: 9, sept: 9, oct: 10, nov: 11, dec: 12 };

// UK first: 03/04/2026 is 3 April. Returns YYYY-MM-DD or null.
export function parseDate(v, order = 'dmy') {
  if (v == null || v === '') return null;
  if (v instanceof Date && !isNaN(v)) return isoDate(v);
  if (typeof v === 'number' && v > 20000 && v < 80000) {
    // Excel serial date
    const d = new Date(Math.round((v - 25569) * 86400 * 1000));
    return `${d.getUTCFullYear()}-${String(d.getUTCMonth() + 1).padStart(2, '0')}-${String(d.getUTCDate()).padStart(2, '0')}`;
  }
  const s = String(v).trim();
  let m = s.match(/^(\d{4})-(\d{1,2})-(\d{1,2})/);
  if (m) return mk(+m[1], +m[2], +m[3]);
  m = s.match(/^(\d{4})(\d{2})(\d{2})/); // OFX 20261112
  if (m && s.length >= 8 && !/[\/\-.]/.test(s.slice(0, 8))) return mk(+m[1], +m[2], +m[3]);
  m = s.match(/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2,4})/);
  if (m) {
    const y = +m[3] < 100 ? 2000 + +m[3] : +m[3];
    return order === 'mdy' ? mk(y, +m[1], +m[2]) : mk(y, +m[2], +m[1]);
  }
  m = s.match(/^(\d{1,2})[\s\-]([A-Za-z]{3,9})[\s\-,]+(\d{2,4})/); // 12 Nov 2026, 12-Nov-26
  if (m && monthNo(m[2])) {
    const y = +m[3] < 100 ? 2000 + +m[3] : +m[3];
    return mk(y, monthNo(m[2]), +m[1]);
  }
  m = s.match(/^([A-Za-z]{3,9})\s+(\d{1,2}),?\s+(\d{4})/); // Nov 12, 2026
  if (m && monthNo(m[1])) return mk(+m[3], monthNo(m[1]), +m[2]);
  return null;
}
const monthNo = (w) => MONTHS[w.toLowerCase().slice(0, 3)] || null;
function mk(y, mo, d) {
  if (!(mo >= 1 && mo <= 12 && d >= 1 && d <= 31)) return null;
  return `${y}-${String(mo).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
}

export function parseAmount(v) {
  if (typeof v === 'number') return v;
  let s = String(v ?? '').trim();
  if (!s) return 0;
  let neg = false;
  if (/^\(.*\)$/.test(s)) { neg = true; s = s.slice(1, -1); }
  if (/\bDR\b/i.test(s)) neg = true;
  s = s.replace(/\b(CR|DR|GBP)\b/gi, '').replace(/[£$€,\s]/g, '');
  if (s.endsWith('-')) { neg = true; s = s.slice(0, -1); }
  const n = parseFloat(s);
  if (!Number.isFinite(n)) return 0;
  return neg ? -Math.abs(n) : n;
}

// ---------- bank presets ----------
// Each preset: how to recognise the header row, and which columns hold what.
// sign: 'normal' = money out is negative. 'inverted' = money out is positive (credit cards like Amex).
export const PRESETS = [
  { id: 'monzo', name: 'Monzo', test: (h) => h.includes('transaction id') && h.includes('emoji'), map: { date: 'date', description: 'name', amount: 'amount', category: 'category', ref: 'transaction id', notes: 'notes and #tags' } },
  { id: 'starling', name: 'Starling', test: (h) => h.includes('counter party') && h.some((x) => x.startsWith('amount')), map: { date: 'date', description: 'counter party', amount: (h) => h.find((x) => x.startsWith('amount')), notes: 'reference', category: 'spending category' } },
  { id: 'revolut', name: 'Revolut', test: (h) => h.includes('started date') && h.includes('product'), map: { date: 'completed date', description: 'description', amount: 'amount', fee: 'fee', state: 'state' } },
  { id: 'lloyds', name: 'Lloyds / Halifax / Bank of Scotland', test: (h) => h.includes('transaction description') && h.includes('debit amount'), map: { date: 'transaction date', description: 'transaction description', debit: 'debit amount', credit: 'credit amount' } },
  { id: 'nationwide', name: 'Nationwide', test: (h) => h.includes('paid out') && h.includes('paid in'), map: { date: 'date', description: (h) => h.find((x) => x === 'description' || x === 'transactions'), debit: 'paid out', credit: 'paid in' } },
  { id: 'natwest', name: 'NatWest / RBS', test: (h) => h.includes('value') && h.includes('account name') && h.includes('description'), map: { date: 'date', description: 'description', amount: 'value' } },
  { id: 'barclays', name: 'Barclays', test: (h) => h.includes('memo') && h.includes('subcategory') && h.includes('amount'), map: { date: 'date', description: 'memo', amount: 'amount' } },
  { id: 'chase', name: 'Chase UK', test: (h) => h.includes('transaction type') && h.includes('description') && h.includes('amount') && h.includes('balance') && !h.includes('debit amount'), map: { date: 'date', description: 'description', amount: 'amount' } },
  { id: 'amex', name: 'American Express', test: (h) => h.length <= 6 && h.includes('date') && h.includes('description') && h.includes('amount') && !h.includes('balance'), map: { date: 'date', description: 'description', amount: 'amount' }, sign: 'inverted' },
  { id: 'santander', name: 'Santander', test: (h) => h.includes('money in') && h.includes('money out'), map: { date: 'date', description: 'description', debit: 'money out', credit: 'money in' } },
];

export function detectPreset(header) {
  const h = header.map((x) => x.toLowerCase().trim());
  return PRESETS.find((p) => p.test(h)) || null;
}

// Turn a preset (or a manual mapping) into column indexes.
export function resolveMapping(header, map) {
  const h = header.map((x) => x.toLowerCase().trim());
  const out = {};
  for (const [k, v] of Object.entries(map)) {
    const name = typeof v === 'function' ? v(h) : v;
    const i = typeof name === 'number' ? name : h.indexOf(String(name ?? '').toLowerCase());
    if (i >= 0) out[k] = i;
  }
  return out;
}

// Guess a mapping for an unknown bank.
export function guessMapping(header) {
  const h = header.map((x) => x.toLowerCase().trim());
  const find = (...res) => h.findIndex((x) => res.some((r) => r.test(x)));
  const m = {
    date: find(/^date$/, /transaction date/, /posted/, /date/),
    description: find(/description/, /details/, /narrative/, /^name$/, /payee/, /memo/, /merchant/, /reference/),
    amount: find(/^amount/, /^value$/, /amount/),
    debit: find(/debit/, /paid out/, /money out/, /withdrawal/, /^out$/),
    credit: find(/credit/, /paid in/, /money in/, /deposit/, /^in$/),
  };
  Object.keys(m).forEach((k) => m[k] < 0 && delete m[k]);
  if (m.debit != null && m.credit != null) delete m.amount;
  return m;
}

// rows (without header) + column mapping → normalised statement lines.
export function rowsToLines(rows, mapping, { sign = 'normal', dateOrder = 'dmy' } = {}) {
  const out = [];
  for (const r of rows) {
    const date = parseDate(r[mapping.date], dateOrder);
    if (!date) continue;
    if (mapping.state != null && /declined|reverted|failed/i.test(r[mapping.state] || '')) continue;
    let amount;
    if (mapping.amount != null) amount = parseAmount(r[mapping.amount]) - (mapping.fee != null ? Math.abs(parseAmount(r[mapping.fee])) : 0);
    else amount = Math.abs(parseAmount(r[mapping.credit])) - Math.abs(parseAmount(r[mapping.debit]));
    if (sign === 'inverted') amount = -amount;
    if (!amount) continue;
    const description = cleanDesc(r[mapping.description] || '');
    out.push({
      date,
      amount: round2(Math.abs(amount)),
      type: amount < 0 ? 'expense' : 'income',
      description,
      bankCategory: mapping.category != null ? r[mapping.category] || '' : '',
      ref: mapping.ref != null ? r[mapping.ref] || '' : '',
      notes: mapping.notes != null ? r[mapping.notes] || '' : '',
    });
  }
  return out;
}

export function cleanDesc(s) {
  return String(s).replace(/\s{2,}/g, ' ').replace(/\b(CARD PAYMENT TO|DEBIT CARD PAYMENT|CONTACTLESS|POS|VIS|DD|SO|BGC|FPI|FPO|ON \d{2}\s?\w{3}\s?\d{0,4})\b/gi, ' ').replace(/\s{2,}/g, ' ').trim();
}

// ---------- OFX / QFX ----------
export function parseOFX(text) {
  const out = [];
  const blocks = String(text).split(/<STMTTRN>/i).slice(1);
  const tag = (b, t) => { const m = b.match(new RegExp(`<${t}>([^<\\r\\n]*)`, 'i')); return m ? m[1].trim() : ''; };
  for (const b of blocks) {
    const amount = parseAmount(tag(b, 'TRNAMT'));
    const date = parseDate(tag(b, 'DTPOSTED'));
    if (!date || !amount) continue;
    out.push({ date, amount: round2(Math.abs(amount)), type: amount < 0 ? 'expense' : 'income', description: cleanDesc(tag(b, 'NAME') || tag(b, 'MEMO')), notes: tag(b, 'MEMO'), ref: tag(b, 'FITID'), bankCategory: '' });
  }
  return out;
}

// ---------- Money Manager (Realbyte) export ----------
// Header: Date, Account, Category, Subcategory, Note, GBP, Income/Expense, Tag, Description, Amount, Currency, Account
export function moneyManagerRows(rows) {
  const hi = rows.findIndex((r) => r.some((c) => String(c).trim().toLowerCase() === 'income/expense'));
  if (hi < 0) throw new Error('This does not look like a Money Manager export (no "Income/Expense" column).');
  const h = rows[hi].map((c) => String(c ?? '').trim().toLowerCase());
  const col = (name) => h.indexOf(name);
  const ci = { date: col('date'), account: col('account'), category: col('category'), sub: col('subcategory'), note: col('note'), amount: col('amount') >= 0 ? col('amount') : col('gbp'), gbp: col('gbp'), type: col('income/expense'), desc: col('description') };
  const out = [];
  for (const r of rows.slice(hi + 1)) {
    const kind = String(r[ci.type] ?? '').trim().toLowerCase();
    if (!kind) continue;
    const date = parseDate(r[ci.date]);
    if (!date) continue;
    // Keep the sign: Money Manager allows a negative income/expense (a refund or correction) and nets it off.
    const amount = num(ci.gbp >= 0 && r[ci.gbp] !== '' && r[ci.gbp] != null ? r[ci.gbp] : r[ci.amount]);
    const base = {
      date,
      time: r[ci.date] instanceof Date ? `${String(r[ci.date].getHours()).padStart(2, '0')}:${String(r[ci.date].getMinutes()).padStart(2, '0')}` : '',
      amount: round2(amount),
      account: String(r[ci.account] ?? '').trim() || 'Accounts',
      note: String(r[ci.note] ?? '').trim(),
      description: String(r[ci.desc] ?? '').trim(),
    };
    if (kind.startsWith('transfer')) {
      if (kind === 'transfer-in') continue; // the matching -out row carries it
      out.push({ ...base, type: 'transfer', toAccount: String(r[ci.category] ?? '').trim() });
    } else {
      out.push({ ...base, type: kind.startsWith('inc') ? 'income' : 'expense', category: String(r[ci.category] ?? '').trim() || 'Other', subcategory: String(r[ci.sub] ?? '').trim() });
    }
  }
  return out;
}

// ---------- categorising ----------
export const KEYWORDS = [
  [/tesco|asda|sainsbury|aldi|lidl|morrisons|co-?op|iceland|waitrose|m&s food|farmfoods|spar\b/i, '🍜 Food', 'Groceries'],
  [/costa|starbucks|greggs|pret|mcdonald|kfc|nando|subway|deliveroo|just eat|uber \*?eats|domino|pizza|cafe|coffee|burger|kebab|grill/i, '🍜 Food', 'Eating out'],
  [/shell|bp\b|esso|texaco|jet\b|gulf|fuel|petrol|tesco fuel|murco/i, '🚖 Transport', 'Fuel'],
  [/car wash|parking|ringgo|paybyphone|ncp|dvla|mot\b|kwik ?fit|halfords|tyre|congestion|dart ?charge|toll/i, '🚖 Transport', 'Car'],
  [/trainline|lner|northern|tfl|national rail|stagecoach|first bus|megabus|easyjet|ryanair|jet2|british airways/i, '👬🏻 Social Life', 'Travel'],
  [/netflix|spotify|disney|prime video|apple\.com|google \*|youtube|icloud|now tv|dazn/i, '🪑 Household', 'Subscriptions'],
  [/\bee\b|vodafone|o2\b|three\b|giffgaff|virgin media|sky\b|bt\b|plusnet|talktalk/i, '🪑 Household', 'Bills'],
  [/council tax|british gas|octopus|eon|e\.on|edf|ovo|scottish power|water/i, '🪑 Household', 'Bills'],
  [/amazon|ebay|argos|currys|ikea|b&q|primark|tk ?maxx|next\b|jd sports|zara|asos/i, '🛒 Shopping', ''],
  [/gym|puregym|the gym|nuffield|boots|superdrug|pharmacy|dentist|optician/i, '🧘🏼 Health', ''],
  [/barber|haircut|salon/i, '💄 Beauty', ''],
  [/hmrc|tax\b/i, '🧾Taxes', ''],
  [/insurance|admiral|aviva|direct line|lv=|hastings|churchill/i, '🚖 Transport', 'Insurance'],
  [/islamic relief|muslim aid|charity|sadaqah|zakat|penny appeal|human appeal/i, 'Charity', ''],
  [/chatgpt|openai|anthropic|claude|google workspace|squarespace/i, 'BUSINESS', 'Software'],
];
export const INCOME_KEYWORDS = [
  [/uber/i, '💰 Salary', 'Uber'],
  [/bolt/i, '💰 Salary', 'Bolt'],
  [/central executive|cet\b/i, 'Business', 'Central job'],
  [/salary|wages|payroll/i, '💰 Salary', ''],
  [/refund/i, 'Other', 'Refund'],
];

export const normMerchant = (s) => String(s || '').toLowerCase().replace(/[^a-z ]/g, ' ').replace(/\b(ltd|limited|uk|gb|london|sheffield|www|com|co)\b/g, ' ').replace(/\s+/g, ' ').trim().split(' ').slice(0, 3).join(' ');

// Build a merchant→category memory from past transactions (learns from Money Manager history).
export function learnFromHistory(txns) {
  const mem = new Map();
  for (const t of txns) {
    if (t.type === 'transfer') continue;
    for (const s of [t.note, t.description, t.bankDescription]) {
      const k = normMerchant(s);
      if (k.length < 3) continue;
      const key = `${t.type}|${k}`;
      const e = mem.get(key) || {};
      const c = `${t.category}\u0001${t.subcategory || ''}`;
      e[c] = (e[c] || 0) + 1;
      mem.set(key, e);
    }
  }
  const out = new Map();
  for (const [k, counts] of mem) {
    const [best] = Object.entries(counts).sort((a, b) => b[1] - a[1]);
    const [category, subcategory] = best[0].split('\u0001');
    out.set(k, { category, subcategory });
  }
  return out;
}

export function categorise(line, { rules = [], memory = new Map() } = {}) {
  const d = `${line.description} ${line.notes || ''}`;
  for (const r of rules) {
    if (!r.match) continue;
    if ((r.type && r.type !== line.type)) continue;
    if (d.toLowerCase().includes(r.match.toLowerCase())) return { category: r.category, subcategory: r.subcategory || '', how: 'rule' };
  }
  const mm = memory.get(`${line.type}|${normMerchant(line.description)}`);
  if (mm) return { ...mm, how: 'history' };
  const list = line.type === 'income' ? INCOME_KEYWORDS : KEYWORDS;
  for (const [re, category, subcategory] of list) if (re.test(d)) return { category, subcategory, how: 'keyword' };
  return { category: line.type === 'income' ? 'Other' : 'Other', subcategory: '', how: 'none' };
}

// ---------- duplicates ----------
export const importKey = (accountId, l) => `${accountId}|${l.date}|${l.type}|${round2(l.amount).toFixed(2)}|${normMerchant(l.description)}|${l.ref || ''}`;

// Marks each line: 'exact' if already imported, 'manual' if a hand-entered transaction
// (typed in, or imported from Money Manager) with the same amount & type sits within ±3 days (likely the same spend), else null.
export function findDuplicates(lines, existing, accountId) {
  const keys = new Set(existing.map((t) => t.importKey).filter(Boolean));
  const used = new Set();
  return lines.map((l) => {
    if (keys.has(importKey(accountId, l))) return { ...l, dup: 'exact' };
    const lo = addDays(l.date, -3), hi = addDays(l.date, 3);
    const m = existing.find((t) => !used.has(t.id) && t.source !== 'statement' && t.type === l.type && Math.abs(num(t.amount) - l.amount) < 0.005 && t.date >= lo && t.date <= hi);
    if (m) { used.add(m.id); return { ...l, dup: 'manual', dupOf: m.id }; }
    return { ...l, dup: null };
  });
}

// ---------- PDF bank statements (NatWest and similar layouts) ----------
// Works on text lines (from pdf.js in the app, or pdftotext in tests). Rows look like:
//   "18 MAY   Automated Credit UBER PAYMENTS UK L      230.31        14,739.65"
// with the description sometimes wrapping onto the line above. Every row ends with the
// running balance, so money in vs out is decided by how the balance moved — no need to
// know which column a number sat in.
const MON3 = { JAN: 1, FEB: 2, MAR: 3, APR: 4, MAY: 5, JUN: 6, JUL: 7, AUG: 8, SEP: 9, OCT: 10, NOV: 11, DEC: 12 };
const MONEY = String.raw`-?[\d,]+\.\d{2}(?:\s?(?:OD|DR|CR))?`;
const toNum = (s) => { const neg = /OD|DR|^-/.test(s); const n = parseFloat(s.replace(/[^\d.]/g, '')); return neg ? -n : n; };

export function parseStatementText(lines) {
  const text = lines.join('\n');
  const per = text.match(/(\d{1,2})\s+([A-Z]{3})[A-Z]*\s+(\d{4})\s+to\s+(\d{1,2})\s+([A-Z]{3})[A-Z]*\s+(\d{4})/i);
  const startY = per ? +per[3] : null, startM = per ? MON3[per[2].toUpperCase()] : null;
  const summary = {};
  for (const [k, re] of [['opening', /Previous Balance\s+£?(-?[\d,]+\.\d{2})/i], ['paidIn', /Paid In\s+£?([\d,]+\.\d{2})/i], ['withdrawn', /Withdrawn\s+£?([\d,]+\.\d{2})/i], ['closing', /New Balance\s+£?(-?[\d,]+\.\d{2}(?:\s?OD)?)/i]]) {
    const m = text.match(re); if (m) summary[k] = toNum(m[1]);
  }
  const acct = text.match(/Account No\s+Sort Code[^\n]*\n[^\n]*?(\d{8})\s+(\d{2}-\d{2}-\d{2})/i);
  const out = [];
  let inTable = false, date = null, prevBal = null, desc = [];
  const yearFor = (mon) => (startY == null ? new Date().getFullYear() : mon < startM ? startY + 1 : startY);
  for (const raw of lines) {
    const line = raw.replace(/\s+$/, '');
    if (/^\s*Date\s+Description\b.*Balance/i.test(line)) { inTable = true; desc = []; continue; }
    if (!inTable) continue;
    if (/National Westminster Bank Plc|RETSTMT|^\s*Account Name\b|Registered in England|Authority and regulated|Financial Services Firm/i.test(line)) { inTable = false; desc = []; continue; }
    if (!line.trim()) continue;
    let rest = line;
    const dm = rest.match(/^\s*(\d{1,2})\s+([A-Z]{3})(?:\s+(\d{4}))?\b\s*/);
    if (dm && MON3[dm[2]]) {
      const mon = MON3[dm[2]], y = dm[3] ? +dm[3] : yearFor(mon);
      date = `${y}-${String(mon).padStart(2, '0')}-${dm[1].padStart(2, '0')}`;
      rest = rest.slice(dm[0].length);
    }
    const am = rest.match(new RegExp(String.raw`(?:(${MONEY})\s+)?(${MONEY})\s*$`));
    if (!am) { desc.push(rest.trim()); continue; }
    const bal = toNum(am[2]);
    const body = rest.slice(0, am.index).trim();
    const words = [...desc, body].join(' ').replace(/\s{2,}/g, ' ').trim();
    desc = [];
    if (/BROUGHT FORWARD/i.test(words) || !am[1]) { prevBal = bal; continue; }
    if (/CARRIED FORWARD/i.test(words)) { prevBal = bal; continue; }
    const amt = toNum(am[1]);
    let signed = prevBal == null ? null : round2(bal - prevBal);
    if (signed == null || Math.abs(Math.abs(signed) - Math.abs(amt)) > 0.011) signed = /credit|deposit|refund|interest paid|received/i.test(words) ? Math.abs(amt) : -Math.abs(amt);
    prevBal = bal;
    if (!date) continue;
    out.push({ date, amount: round2(Math.abs(signed)), type: signed < 0 ? 'expense' : 'income', description: tidyStatement(words), notes: words, balance: bal, ref: '', bankCategory: '' });
  }
  const totals = { paidIn: round2(out.filter((l) => l.type === 'income').reduce((t, l) => t + l.amount, 0)), withdrawn: round2(out.filter((l) => l.type === 'expense').reduce((t, l) => t + l.amount, 0)) };
  const checks = summary.paidIn != null ? Math.abs(totals.paidIn - summary.paidIn) < 0.02 && Math.abs(totals.withdrawn - summary.withdrawn) < 0.02 : null;
  return { lines: out, summary, totals, balanced: checks, account: acct ? { number: acct[1], sortCode: acct[2] } : null, closing: out.length ? out.at(-1).balance : summary.closing ?? null };
}

// "Automated Credit UBER PAYMENTS UK L" → "Uber Payments Uk L"; drop bank boilerplate & refs.
export function tidyStatement(s) {
  return String(s)
    .replace(/^(Automated Credit|OnLine Transaction|Card Transaction \d{4} \d{2}[A-Z]{3}\d{2}|Direct Debit|Standing Order|Cash Withdrawal \S+ \d{2}[A-Z]{3}\d{2}|Debit Card Refund \S+|Card Refund \S+|BACS|Charges|Faster Payment)\s*/i, '')
    .replace(/\b(FP|VIA MOBILE - PYMT|VIA ONLINE - PYMT|\/DOC\/\S+|\/TXT\/\S+|XP\d+|REV\d+|PID-\d+)\b/gi, ' ')
    .replace(/\b\d{2}\/\d{2}\/\d{2}\b.*$/, '')
    .replace(/\s{2,}/g, ' ').trim().slice(0, 80) || String(s).slice(0, 80);
}

// Money moved between your own accounts / pots / cards is not spending or income.
export function isOwnTransfer(text, holder = '') {
  const t = String(text).toUpperCase();
  if (/\b(TO POT|FROM POT|POT SAVING|SAVINGS? POT|OWN ACCOUNT|TRANSFER TO SAV|NWESTPLAT|AMERICAN EXP|AMEX|BARCLAYCARD|CAPITAL ONE|MBNA|CREDIT CARD|VANQUIS|AQUA CARD)\b/.test(t)) return true;
  const words = String(holder).toUpperCase().replace(/^(MR|MRS|MS|MISS|DR)\s+/, '').split(/\s+/).filter((w) => w.length > 2);
  if (words.length >= 2 && words.every((w) => t.includes(w))) return true; // e.g. "JANE SMITH ..."
  if (words[0] && new RegExp(`\\b(${words[0].slice(0, 4)}\\w*)\\b.*\\b(REVOLUT|MONZO|WISE ACC|METRO|STARLING|CHASE|KROO)\\b`).test(t) && !/\bMUM\b/.test(t)) return true;
  return false;
}
export function statementHolder(lines) {
  const i = lines.findIndex((l) => /Account Name/i.test(l));
  return i >= 0 && lines[i + 1] ? lines[i + 1].trim().split(/\s{2,}/)[0] : '';
}
