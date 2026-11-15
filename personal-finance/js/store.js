// Persistence: everything lives on this device in IndexedDB (no server, no account).
// Optional passcode lock encrypts the whole record with AES-GCM (key from PBKDF2).
import { today, addDays, addMonths, isoDate } from './calc.js';
import { SHORTLIST } from './halal.js';

const DB = 'money-hq', STORE = 'kv', KEY = 'state';
export const SCHEMA = 3;
export const uid = () => Math.random().toString(36).slice(2, 10) + Date.now().toString(36).slice(-4);

// ---------- IndexedDB (falls back to memory if blocked) ----------
let mem = null;
function idb() {
  return new Promise((res, rej) => {
    try {
      const r = indexedDB.open(DB, 1);
      r.onupgradeneeded = () => r.result.createObjectStore(STORE);
      r.onsuccess = () => res(r.result);
      r.onerror = () => rej(r.error);
    } catch (e) { rej(e); }
  });
}
async function rawGet() {
  try {
    const db = await idb();
    return await new Promise((res, rej) => { const q = db.transaction(STORE).objectStore(STORE).get(KEY); q.onsuccess = () => res(q.result); q.onerror = () => rej(q.error); });
  } catch { return mem; }
}
async function rawPut(v) {
  mem = v;
  try {
    const db = await idb();
    await new Promise((res, rej) => { const t = db.transaction(STORE, 'readwrite'); t.objectStore(STORE).put(v, KEY); t.oncomplete = res; t.onerror = () => rej(t.error); });
    return true;
  } catch { return false; }
}
// Small extra values (e.g. the sync key, which stays a non-extractable CryptoKey).
export async function kvGet(k) {
  try { const db = await idb(); return await new Promise((res, rej) => { const q = db.transaction(STORE).objectStore(STORE).get(k); q.onsuccess = () => res(q.result); q.onerror = () => rej(q.error); }); } catch { return undefined; }
}
export async function kvPut(k, v) {
  try { const db = await idb(); await new Promise((res, rej) => { const t = db.transaction(STORE, 'readwrite'); if (v === undefined) t.objectStore(STORE).delete(k); else t.objectStore(STORE).put(v, k); t.oncomplete = res; t.onerror = () => rej(t.error); }); } catch {}
}
export async function wipe() {
  mem = null;
  try { const db = await idb(); await new Promise((res) => { const t = db.transaction(STORE, 'readwrite'); t.objectStore(STORE).delete(KEY); t.oncomplete = res; t.onerror = res; }); } catch {}
}

// ---------- encryption ----------
const enc = new TextEncoder(), dec = new TextDecoder();
const b64 = (buf) => btoa(String.fromCharCode(...new Uint8Array(buf)));
const unb64 = (s) => Uint8Array.from(atob(s), (c) => c.charCodeAt(0));
let cryptoKey = null, cryptoSalt = null;

async function deriveKey(pass, salt) {
  const base = await crypto.subtle.importKey('raw', enc.encode(pass), 'PBKDF2', false, ['deriveKey']);
  return crypto.subtle.deriveKey({ name: 'PBKDF2', salt, iterations: 250000, hash: 'SHA-256' }, base, { name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt']);
}
async function seal(state) {
  const iv = crypto.getRandomValues(new Uint8Array(12));
  const ct = await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, cryptoKey, enc.encode(JSON.stringify(state)));
  return { locked: true, salt: b64(cryptoSalt), iv: b64(iv), data: b64(ct) };
}
export async function unlock(record, pass) {
  const salt = unb64(record.salt);
  const key = await deriveKey(pass, salt);
  const pt = await crypto.subtle.decrypt({ name: 'AES-GCM', iv: unb64(record.iv) }, key, unb64(record.data)); // throws on wrong passcode
  cryptoKey = key; cryptoSalt = salt;
  return migrate(JSON.parse(dec.decode(pt)));
}
export async function setPasscode(pass) {
  if (!pass) { cryptoKey = null; cryptoSalt = null; return; }
  cryptoSalt = crypto.getRandomValues(new Uint8Array(16));
  cryptoKey = await deriveKey(pass, cryptoSalt);
}
export const isLocked = () => !!cryptoKey;

// ---------- load / save ----------
export async function load() {
  const rec = await rawGet();
  if (!rec) return { state: sampleState(), fresh: true };
  if (rec.locked) return { record: rec, needsPasscode: true };
  return { state: migrate(rec) };
}
let timer = null, pending = null;
export function save(state, { now = false } = {}) {
  pending = state;
  clearTimeout(timer);
  const run = async () => { const s = pending; pending = null; await rawPut(cryptoKey ? await seal(s) : s); };
  if (now) return run();
  timer = setTimeout(run, 250);
}
export function flush() { if (pending) { clearTimeout(timer); return save(pending, { now: true }); } }

export function migrate(s) {
  const base = blankState();
  const out = { ...base, ...s, settings: { ...base.settings, ...(s.settings || {}) } };
  for (const k of ['accounts', 'categories', 'transactions', 'recurring', 'rules', 'holdings', 'tombstones', 'halalShortlist']) if (!Array.isArray(out[k])) out[k] = base[k];
  for (const k of ['budget', 'goals', 'networth', 'debts', 'freedom', 'zakat']) out[k] = { ...base[k], ...(s[k] || {}) };
  if ((s.schema || 1) < 3) out.settings.theme = 'light'; // v3 is light-first; dark stays available in Settings
  out.settings.payday = { ...base.settings.payday, ...(s.settings?.payday || {}) };
  out.settings.tax = { income: [], costs: [], ...(s.settings?.tax || {}) };
  if (!Array.isArray(out.settings.dismissed)) out.settings.dismissed = [];
  for (const c of out.categories) {
    if (!Array.isArray(c.subs)) c.subs = [...new Set(out.transactions.filter((t) => t.category === c.name && (t.type === c.kind || !t.type) && t.subcategory).map((t) => t.subcategory))];
  }
  if (!out.halalShortlist.length) out.halalShortlist = SHORTLIST.map((x) => ({ id: uid(), ...x, status: 'researching' }));
  out.schema = SCHEMA;
  return out;
}

// ---------- defaults ----------
export const ACCOUNT_GROUPS = [
  { id: 'cash', name: 'Cash', liability: false },
  { id: 'bank', name: 'Accounts', liability: false },
  { id: 'card', name: 'Card', liability: true },
  { id: 'savings', name: 'Savings', liability: false },
  { id: 'investment', name: 'Investments', liability: false },
  { id: 'loan', name: 'Loans', liability: true },
  { id: 'other', name: 'Other', liability: false },
];

const EXPENSE_CATS = ['🍜 Food', '🚖 Transport', '🪑 Household', '👬🏻 Social Life', '🧘🏼 Health', '💄 Beauty', '🛒 Shopping', '🧥 Apparel', 'Holidays', 'Charity', 'BUSINESS', '🧾Taxes', 'Other'];
const INCOME_CATS = ['💰 Salary', 'Business', '🏅 Bonus', 'Other'];

function budgetTemplate(withAmounts) {
  const a = (n) => (withAmounts ? n : 0);
  const it = (group, section, name, amount, category = '') => ({ id: uid(), group, section, name, amount: a(amount), category });
  return {
    income: [
      { id: uid(), name: 'Uber / Bolt', amount: a(3400) },
      { id: uid(), name: 'Business jobs', amount: a(1200) },
    ],
    items: [
      it('fixed', 'Household bills', 'Rent / mortgage', 950, '🪑 Household'),
      it('fixed', 'Household bills', 'Council tax', 140, '🪑 Household'),
      it('fixed', 'Household bills', 'Gas & electricity', 120, '🪑 Household'),
      it('fixed', 'Insurances', 'Car insurance', 185, '🚖 Transport'),
      it('fixed', 'Transport / vehicles', 'Car finance', 0, '🚖 Transport'),
      it('fixed', 'Debt', 'Loan repayments', 0),
      it('variable', 'Food', 'Groceries & eating out', 350, '🍜 Food'),
      it('variable', 'Lifestyle', 'Phone / SIM', 40, '🪑 Household'),
      it('variable', 'Lifestyle', 'Gym & football', 42, '🧘🏼 Health'),
      it('variable', 'Lifestyle', 'Subscriptions', 25, '🪑 Household'),
      it('variable', 'Petrol & travel', 'Fuel, wash, parking', 420, '🚖 Transport'),
      it('optional', 'Entertainment', 'Socialising', 150, '👬🏻 Social Life'),
      it('optional', 'Lifestyle', 'Charity', 40, 'Charity'),
      it('optional', 'Lifestyle', 'Clothes & beauty', 40, '🧥 Apparel'),
    ],
    allocation: [
      { id: uid(), name: 'Stocks & Shares ISA', amount: a(500) },
      { id: uid(), name: 'Pension (SIPP)', amount: a(250) },
      { id: uid(), name: 'Emergency fund', amount: a(200) },
    ],
  };
}

export function blankState() {
  return {
    schema: SCHEMA,
    settings: { name: '', sample: false, lastBackup: null, theme: 'light', weekStart: 1, created: today(), payday: { mode: 'weekly', weekday: 2, day: 28 }, tax: { income: [], costs: [] }, dismissed: [] },
    accounts: [
      { id: 'cash', name: 'Cash', group: 'cash', opening: 0 },
      { id: 'bank', name: 'Accounts', group: 'bank', opening: 0 },
      { id: 'card', name: 'Card', group: 'card', opening: 0 },
      { id: 'savings', name: 'Savings', group: 'savings', opening: 0 },
    ],
    categories: [
      ...EXPENSE_CATS.map((name) => ({ id: uid(), name, kind: 'expense' })),
      ...INCOME_CATS.map((name) => ({ id: uid(), name, kind: 'income' })),
    ],
    transactions: [],
    recurring: [],
    rules: [],
    holdings: [],
    tombstones: [],
    halalShortlist: SHORTLIST.map((x) => ({ id: uid(), ...x, status: 'researching' })),
    zakat: { metal: 'silver', gramPrice: 0, gold: 0, silver: 0, investPct: 0.25, owedToYou: 0, debtsDue: 0, extraCash: 0, dueDate: '' },
    budget: budgetTemplate(false),
    goals: { sinking: [], investments: [] },
    networth: { assets: [], liabilities: [], history: [] },
    debts: { items: [], budget: 0, method: 'avalanche' },
    freedom: { monthlyNeed: 3000, current: 0, monthly: 0, rate: 0.07, withdrawalRate: 0.04 },
  };
}

// ---------- example data (clearly flagged; never the user's own figures) ----------
export function sampleState(now = today()) {
  const s = blankState();
  s.settings.sample = true;
  s.budget = budgetTemplate(true);
  s.accounts.find((a) => a.id === 'bank').opening = 2400;
  s.accounts.find((a) => a.id === 'cash').opening = 300;
  s.accounts.find((a) => a.id === 'savings').opening = 3000;
  s.accounts.push({ id: 'isa', name: 'Stocks & Shares ISA', group: 'investment', opening: 4200 });
  let seed = 7;
  const rnd = () => ((seed = (seed * 16807) % 2147483647) / 2147483647);
  const pick = (a) => a[Math.floor(rnd() * a.length)];
  const tx = (date, type, amount, category, note, accountId, subcategory = '') => s.transactions.push({ id: uid(), date, type, amount: Math.round(amount * 100) / 100, category, subcategory, note, description: '', accountId, source: 'example' });
  const start = addDays(now, -95);
  for (let d = start; d <= now; d = addDays(d, 1)) {
    const dow = new Date(d + 'T12:00').getDay();
    if (dow !== 0) { tx(d, 'income', 55 + rnd() * 60, '💰 Salary', 'Uber', 'bank'); tx(d, 'income', 45 + rnd() * 70, '💰 Salary', 'Bolt', 'bank'); }
    if (rnd() < 0.25) tx(d, 'income', pick([95, 108, 140, 261]), 'Business', 'Airport job', 'bank');
    if (rnd() < 0.7) tx(d, 'expense', 4 + rnd() * 14, '🍜 Food', pick(['Costa', 'Greggs', 'Chicken shop', 'Pret', 'Pizza']), pick(['card', 'cash']));
    if (rnd() < 0.35) tx(d, 'expense', 30 + rnd() * 35, '🚖 Transport', 'Fuel', 'card', 'Fuel');
    if (rnd() < 0.12) tx(d, 'expense', 3 + rnd() * 4, '🚖 Transport', 'Car wash', 'cash');
    if (rnd() < 0.1) tx(d, 'expense', 20 + rnd() * 60, '👬🏻 Social Life', pick(['Bowling', 'Dinner out', 'Cinema']), 'card');
    if (rnd() < 0.06) tx(d, 'expense', 15 + rnd() * 50, '🛒 Shopping', 'Amazon', 'card');
    if (rnd() < 0.05) tx(d, 'expense', 5, 'Charity', 'Sadaqah', 'card');
    if (d.endsWith('-01')) tx(d, 'expense', 950, '🪑 Household', 'Rent', 'bank');
    if (d.endsWith('-08')) tx(d, 'expense', 185, '🚖 Transport', 'Taxi insurance', 'card', 'Insurance');
    if (d.endsWith('-12')) tx(d, 'expense', 38.5, '🪑 Household', 'EE SIM', 'card', 'Bills');
    if (d.endsWith('-25')) tx(d, 'transfer', 500, '', 'ISA top-up', 'bank'), (s.transactions.at(-1).toAccountId = 'isa');
  }
  // keep the example balances believable: most income has already been spent or moved on
  const flow = (id) => s.transactions.reduce((t, x) => t + (x.accountId === id ? (x.type === 'income' ? x.amount : -x.amount) : 0) + (x.toAccountId === id ? x.amount : 0), 0);
  s.accounts.find((a) => a.id === 'bank').opening = Math.round(1240 - flow('bank'));
  s.accounts.find((a) => a.id === 'cash').opening = Math.round(85 - flow('cash'));
  s.accounts.find((a) => a.id === 'card').opening = Math.round(-640 - flow('card'));
  s.recurring = [
    { id: uid(), name: 'Taxi insurance', type: 'expense', amount: 185, category: '🚖 Transport', subcategory: 'Insurance', accountId: 'card', freq: 'monthly', next: addMonths(now.slice(0, 8) + '08', now.slice(8) >= '08' ? 1 : 0), auto: false },
    { id: uid(), name: 'EE SIM', type: 'expense', amount: 38.5, category: '🪑 Household', subcategory: 'Bills', accountId: 'card', freq: 'monthly', next: addMonths(now.slice(0, 8) + '12', now.slice(8) >= '12' ? 1 : 0), auto: true },
    { id: uid(), name: 'Gym', type: 'expense', amount: 22, category: '🧘🏼 Health', subcategory: '', accountId: 'card', freq: 'monthly', next: addDays(now, -2), auto: false },
  ];
  s.goals = {
    sinking: [
      { id: uid(), name: 'Emergency fund', target: 10000, date: addMonths(now, 24), saved: 3000, monthly: 200 },
      { id: uid(), name: 'Car replacement', target: 15000, date: addMonths(now, 48), saved: 1500, monthly: 250 },
      { id: uid(), name: 'Eid & Ramadan', target: 1500, date: addMonths(now, 4), saved: 400, monthly: 150 },
    ],
    investments: [
      { id: uid(), name: 'Stocks & Shares ISA', value: 4200, monthly: 500, rate: 0.07 },
      { id: uid(), name: 'Pension (SIPP)', value: 800, monthly: 250, rate: 0.06 },
    ],
  };
  s.holdings = [
    { id: uid(), name: 'Global all-cap index fund', ticker: 'VWRP', wrapper: 'S&S ISA', units: 32, price: 112.4, cost: 3400, contributedThisYear: 2500 },
    { id: uid(), name: 'S&P 500 index fund', ticker: 'CSP1', wrapper: 'S&S ISA', units: 1.2, price: 540, cost: 600, contributedThisYear: 0 },
  ];
  s.networth = {
    assets: [{ id: uid(), name: 'Car', value: 11000 }],
    liabilities: [{ id: uid(), name: 'Student loan', value: 18000 }, { id: uid(), name: 'Car loan', value: 4500 }],
    history: [],
  };
  s.debts = {
    items: [
      { id: uid(), name: 'Credit card', balance: 2400, rate: 24.9, min: 75 },
      { id: uid(), name: 'Car loan', balance: 4500, rate: 9.9, min: 210 },
    ],
    budget: 450, method: 'avalanche',
  };
  s.freedom = { monthlyNeed: 4000, current: 8200, monthly: 750, rate: 0.07, withdrawalRate: 0.04 };
  s.settings.tax = { income: ['💰 Salary', 'Business'], costs: ['BUSINESS'] };
  // a few things for the Insights checks to find
  tx(addDays(now, -6), 'expense', 125.19, '👬🏻 Social Life', 'Trainline', 'card'); tx(addDays(now, -5), 'expense', 125.19, '👬🏻 Social Life', 'Trainline', 'card');
  tx(addDays(now, -9), 'expense', 12, 'Other', 'Late payment fee', 'card');
  for (const k of [3, 2, 1]) tx(addMonths(addDays(now, -4), -k), 'expense', 7.99, '🪑 Household', 'Streaming TV', 'card');
  tx(addDays(now, -4), 'expense', 9.99, '🪑 Household', 'Streaming TV', 'card');
  s.rules = [{ id: uid(), match: 'costa', type: 'expense', category: '🍜 Food', subcategory: 'Coffee' }];
  return s;
}

export const freshFromSample = () => blankState();
export { isoDate };
