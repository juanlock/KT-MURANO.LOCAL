// Capturas para el flyer de Murano (2026-11-16): pantallas reales con DATOS DE DEMOSTRACIÓN de una compañía de prueba
// (My Bodega, is_live = 0), nunca de un cliente. Solo en una PC de desarrollo, con una contraseña temporal que se restaura después.
// node KT-TOOLS/murano/flyer/shots.cjs <email> <password> <saleId>
const path = require('path');
const fs = require('fs');
const { chromium } = require(path.join(__dirname, '../../../node_modules/playwright'));
const OUT = path.join(__dirname, 'shots');
fs.mkdirSync(OUT, { recursive: true });
const BASE = 'http://kt-murano.local';
const [EMAIL, PW, SALE] = process.argv.slice(2);

const PAGES = [
  ['dashboard', '/next/dashboard', 6000],
  ['sales', '/next/sales', 4500],
  ['sale_detail', `/next/sales/${SALE}`, 4500],
  ['accounting', '/next/accounting-v2/dashboard', 6000],
  ['employees', '/next/hrm/employees', 4500],
  ['payrolls', '/next/hrm/payrolls', 4500],
  ['attendance', '/next/hrm/attendance', 4500],
  ['products', '/next/products', 4500],
  ['stock', '/next/reports/stock', 5000],
  ['purchases', '/next/purchases', 4500],
  ['store_orders', '/next/store/orders', 4500],
  ['pos', '/next/pos', 6000],
  ['forms', '/next/forms', 4000],
  ['helpdesk', '/next/helpdesk/dashboard', 5000],
  ['assets', '/next/assets/dashboard', 5000],
];

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1.5, locale: 'es-PE', colorScheme: 'light' });
  const page = await ctx.newPage();
  await page.goto(BASE + '/login', { waitUntil: 'networkidle' });
  await page.locator('input:visible').first().fill(EMAIL);
  if (!(await page.locator('input[type=password]').count())) { await page.keyboard.press('Enter'); await page.waitForTimeout(1500); }
  await page.fill('input[type=password]', PW);
  await page.keyboard.press('Enter');
  await page.waitForTimeout(4500);
  console.log('login ->', page.url());
  for (const [name, url, wait] of PAGES) {
    await page.goto(BASE + url, { waitUntil: 'networkidle' }).catch(() => {});
    await page.waitForTimeout(wait);
    await page.mouse.move(5, 895);
    await page.screenshot({ path: path.join(OUT, name + '.png') });
    console.log(name, page.url());
  }
  await browser.close();
})().catch(e => { console.error(e); process.exit(1); });
