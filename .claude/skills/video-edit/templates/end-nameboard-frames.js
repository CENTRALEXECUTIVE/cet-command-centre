const { chromium } = require('playwright');
(async () => {
  const b = await chromium.launch(); const p = await b.newPage({ viewport: { width: 1080, height: 1920 } });
  await p.goto('file://' + __dirname + '/end.html'); await p.evaluate(() => document.fonts.ready); await p.waitForTimeout(300);
  const N = 84; // 2.8s @30fps
  for (let i = 0; i < N; i++) {
    const t = i * 1000 / 30;
    await p.evaluate(t => document.getAnimations().forEach(a => { a.pause(); a.currentTime = t; }), t);
    await p.screenshot({ path: __dirname + '/f/' + String(i).padStart(3, '0') + '.png' });
  }
  await b.close();
})();
