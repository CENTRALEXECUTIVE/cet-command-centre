// Phone ⇄ laptop sync through the claude.ai page store (free, nothing to sign up for).
// The whole record is gzipped and AES-256-GCM encrypted ON THIS DEVICE with a key made
// from your sync passcode, then split into chunks under your private data/users/<id>/
// area. The store only ever holds scrambled bytes; without the passcode it is unreadable.
// Self-hosted copies have no store, so sync shows as unavailable there.

const enc = new TextEncoder(), dec = new TextDecoder();
const CHUNK = 180000; // characters of base64 per document (store limit is 256 KiB)
let db = null, uid = null, key = null, salt = null;
export const state = { available: false, enabled: false, status: 'off', lastSync: null, error: '' };

const b64 = (u8) => { let s = ''; for (let i = 0; i < u8.length; i += 0x8000) s += String.fromCharCode(...u8.subarray(i, i + 0x8000)); return btoa(s); };
const unb64 = (s) => Uint8Array.from(atob(s), (c) => c.charCodeAt(0));

async function gzip(text) {
  if (typeof CompressionStream === 'undefined') return { data: enc.encode(text), z: false };
  const cs = new Blob([text]).stream().pipeThrough(new CompressionStream('gzip'));
  return { data: new Uint8Array(await new Response(cs).arrayBuffer()), z: true };
}
async function gunzip(bytes, z) {
  if (!z) return dec.decode(bytes);
  const ds = new Blob([bytes]).stream().pipeThrough(new DecompressionStream('gzip'));
  return await new Response(ds).text();
}
async function derive(pass, saltBytes) {
  const base = await crypto.subtle.importKey('raw', enc.encode(pass), 'PBKDF2', false, ['deriveKey']);
  return crypto.subtle.deriveKey({ name: 'PBKDF2', salt: saltBytes, iterations: 250000, hash: 'SHA-256' }, base, { name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt']);
}

// Pure helpers (tested in tests/sync.test.mjs)
export async function sealRecord(obj, k) {
  const { data, z } = await gzip(JSON.stringify(obj));
  const iv = crypto.getRandomValues(new Uint8Array(12));
  const ct = new Uint8Array(await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, k, data));
  const text = b64(ct);
  const chunks = [];
  for (let i = 0; i < text.length; i += CHUNK) chunks.push(text.slice(i, i + CHUNK));
  return { iv: b64(iv), z, chunks };
}
export async function openRecord(meta, chunks, k) {
  const ct = unb64(chunks.join(''));
  const pt = new Uint8Array(await crypto.subtle.decrypt({ name: 'AES-GCM', iv: unb64(meta.iv) }, k, ct));
  return JSON.parse(await gunzip(pt, meta.z));
}
export { derive as deriveKey };

// Merge two copies: transactions are united by id (minus anything deleted on either
// device); everything else takes the newer copy.
export function merge(local, remote) {
  const newer = (remote.settings?.updatedAt || '') >= (local.settings?.updatedAt || '') ? remote : local;
  const older = newer === remote ? local : remote;
  const dead = new Set([...(local.tombstones || []), ...(remote.tombstones || [])]);
  const byId = new Map();
  for (const t of older.transactions || []) byId.set(t.id, t);
  for (const t of newer.transactions || []) byId.set(t.id, t);
  return { ...newer, tombstones: [...dead].slice(-5000), transactions: [...byId.values()].filter((t) => !dead.has(t.id)) };
}

// ---------- store wiring ----------
export async function init() {
  try {
    if (!window.claude?.use) return false;
    const [d, u] = await Promise.all([window.claude.use('db'), window.claude.use('user')]);
    if (!d || !u) return false;
    const id = await u.id();
    if (!id) return false;
    db = d; uid = id; state.available = true;
    return true;
  } catch { return false; }
}
const doc = (name) => db.collection(`data/users/${uid}`).doc(name);

export async function remoteMeta() {
  if (!db) return null;
  const snap = await doc('vault').get();
  return snap.exists ? snap.data() : null;
}

// Turn on sync on this device. Returns 'pulled' when another device already synced
// (and the data was opened with this passcode), or 'new' when this is the first device.
export async function enable(pass) {
  const meta = await remoteMeta();
  if (meta) {
    const k = await derive(pass, unb64(meta.salt));
    const chunks = await readChunks(meta);
    const data = await openRecord(meta, chunks, k); // throws on wrong passcode
    key = k; salt = meta.salt; state.enabled = true; state.lastRev = meta.rev;
    return { result: 'pulled', data, meta };
  }
  const sb = crypto.getRandomValues(new Uint8Array(16));
  key = await derive(pass, sb); salt = b64(sb); state.enabled = true; state.lastRev = 0;
  return { result: 'new' };
}
async function readChunks(meta) {
  const out = [];
  for (let i = 0; i < meta.count; i++) { const s = await doc(`c${i}`).get(); if (!s.exists) throw new Error('A piece of the synced data is missing. Push again from your other device.'); out.push(s.data().d); }
  return out;
}

export async function push(data, device) {
  if (!db || !key) return;
  state.status = 'saving';
  const sealed = await sealRecord(data, key);
  const meta = await remoteMeta();
  const rev = Math.max(state.lastRev || 0, meta?.rev || 0) + 1;
  for (let i = 0; i < sealed.chunks.length; i++) await doc(`c${i}`).set({ d: sealed.chunks[i], rev });
  for (let i = sealed.chunks.length; i < (meta?.count || 0); i++) await doc(`c${i}`).delete();
  await doc('vault').set({ salt, iv: sealed.iv, z: sealed.z, count: sealed.chunks.length, rev, device, at: new Date().toISOString() });
  state.lastRev = rev; state.lastSync = new Date(); state.status = 'synced';
}

// Fetch the remote copy if another device has pushed since we last looked.
export async function pullIfNewer() {
  if (!db || !key) return null;
  const meta = await remoteMeta();
  if (!meta || meta.rev <= (state.lastRev || 0)) return null;
  const data = await openRecord(meta, await readChunks(meta), key);
  state.lastRev = meta.rev;
  return { data, meta };
}

export const snapshot = () => (key ? { key, salt, lastRev: state.lastRev || 0 } : null);
export function resume(saved) { if (!saved?.key) return false; key = saved.key; salt = saved.salt; state.lastRev = saved.lastRev || 0; state.enabled = true; state.status = 'synced'; return true; }
export function disable() { key = null; salt = null; state.enabled = false; state.status = 'off'; }
export async function forget() {
  if (!db) return;
  const meta = await remoteMeta();
  for (let i = 0; i < (meta?.count || 0); i++) await doc(`c${i}`).delete();
  await doc('vault').delete();
  disable();
}
