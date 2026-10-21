// Screenshots for the ready-made website library (php artisan murano:website-templates):
// node website_template_thumbs.cjs <jobs.json>   jobs = [{url, out}]
const path = require('path');
const fs = require('fs');
const { chromium } = require(path.join(__dirname, '../../node_modules/playwright'));

(async () => {
  const jobs = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1280, height: 800 } });
  for (const j of jobs) {
    try {
      await page.goto(j.url, { waitUntil: 'load', timeout: 45000 });
      await page.waitForTimeout(1500); // sliders / fade-ins
      await page.screenshot({ path: j.out, type: 'jpeg', quality: 85 });
      console.log('ok', j.out);
    } catch (e) {
      console.log('fail', j.url, e.message);
    }
  }
  await browser.close();
})();
