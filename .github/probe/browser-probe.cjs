const { chromium } = require('playwright');
(async () => {
  const runs = JSON.parse(process.env.RUNS);
  const browser = await chromium.launch({ headless: true, args: ['--enable-unsafe-webgpu'] });
  for (const [port, device, dtype] of runs) {
    const page = await browser.newPage();
    page.on('console', (m) => console.log('[console]', m.text().slice(0, 300)));
    await page.goto(`http://localhost:${port}/index.html`);
    const r = await page.evaluate(([d, t]) => window.runProbe(d, t), [device, dtype]);
    console.log('RESULT', port, JSON.stringify(r));
    await page.close();
  }
  await browser.close();
})();
