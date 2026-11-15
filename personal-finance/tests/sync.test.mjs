import test from 'node:test';
import assert from 'node:assert/strict';
import * as S from '../js/sync.js';

const salt = new Uint8Array(16).fill(7);

test('seal and open a large record; wrong passcode fails', async () => {
  const k = await S.deriveKey('correct horse', salt);
  const big = { transactions: Array.from({ length: 4000 }, (_, i) => ({ id: 't' + i, note: 'Uber ' + Math.random(), amount: i })) };
  const sealed = await S.sealRecord(big, k);
  assert.ok(sealed.chunks.length >= 1);
  assert.ok(sealed.chunks.every((c) => c.length <= 180000));
  assert.ok(!sealed.chunks.join('').includes('Uber'), 'stored text is encrypted');
  const back = await S.openRecord(sealed, sealed.chunks, k);
  assert.deepEqual(back, big);
  const wrong = await S.deriveKey('wrong', salt);
  await assert.rejects(S.openRecord(sealed, sealed.chunks, wrong));
});

test('merge unites transactions, honours deletions, newer copy wins elsewhere', () => {
  const a = { settings: { updatedAt: '2026-11-15T10:00:00Z', name: 'old' }, transactions: [{ id: '1' }, { id: '2' }], tombstones: ['3'] };
  const b = { settings: { updatedAt: '2026-11-15T11:00:00Z', name: 'new' }, transactions: [{ id: '1', edited: true }, { id: '3' }, { id: '4' }], tombstones: [] };
  const m = S.merge(a, b);
  assert.equal(m.settings.name, 'new');
  assert.deepEqual(m.transactions.map((t) => t.id).sort(), ['1', '2', '4']);
  assert.equal(m.transactions.find((t) => t.id === '1').edited, true);
  assert.deepEqual(m.tombstones, ['3']);
});
