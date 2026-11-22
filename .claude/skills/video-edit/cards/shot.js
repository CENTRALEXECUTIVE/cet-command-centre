// Screenshot every card in manifest.json at 4/3 scale (1080 CSS px -> 1440 px).
// Usage: node shot.js [filter]
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
(async () => {
  const man = JSON.parse(fs.readFileSync(path.join(__dirname, 'manifest.json')));
  const only = process.argv[2];
  const b = await chromium.launch();
  for (const m of man) {
    if (only && !m.out.startsWith(only)) continue;
    const ctx = await b.newContext({ viewport: { width: m.W, height: m.H }, deviceScaleFactor: 4 / 3 });
    const p = await ctx.newPage();
    await p.goto('file://' + m.html);
    await p.evaluate(() => document.fonts.ready);
    await p.waitForTimeout(150);
    const out = path.join(__dirname, 'png', m.out.replace('.jpg', '.png'));
    fs.mkdirSync(path.dirname(out), { recursive: true });
    for (let t = 0; t < 4; t++) { try { await p.screenshot({ path: out }); break; } catch (e) { if (t === 3) throw e; await p.waitForTimeout(800); } }
    await ctx.close();
    console.log(m.out);
  }
  await b.close();
})();
