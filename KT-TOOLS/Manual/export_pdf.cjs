// Manual HTML -> PDF (A4, internal links kept as PDF links, contents list at the top).
// Run from the repo root: node KT-TOOLS/Manual/export_pdf.cjs
const path = require('path');
const { chromium } = require(path.join(__dirname, '../../node_modules/playwright'));
const fs = require('fs');
const src = path.join(__dirname, 'murano_user_manual.html');
const out = path.join(__dirname, 'murano_user_manual.pdf');
(async () => {
  let body = fs.readFileSync(src, 'utf8');
  // The address becomes a real link in the PDF.
  body = body.replace(/<code>https:\/\/murano\.kcoltech\.com<\/code>/g, '<a href="https://murano.kcoltech.com"><code>https://murano.kcoltech.com</code></a>');
  const printCss = `<style>
    @page { size: A4; margin: 14mm 12mm; }
    body { background: #fff !important; padding: 0 !important; font-size: 13px; }
    .wrap { display: block !important; max-width: none !important; }
    .rail { display: block !important; position: static !important; max-height: none !important; padding: 0 0 18px !important; columns: 3; column-gap: 24px; font-size: 12px; border-bottom: 1px solid #e3e2ef; margin-bottom: 18px; }
    .rail nav { display: block !important; }
    .rail a, .rail .group { display: block; break-inside: avoid; }
    .rail .group { margin-top: 8px; }
    .finder, .top-link { display: none !important; }
    main { padding-top: 0 !important; gap: 28px !important; }
    .roles { grid-template-columns: 1fr 1fr !important; }
    .role, .guide, .basic, tr { break-inside: avoid; }
    h2 { break-after: avoid; }
    .matrix-wrap { overflow: visible !important; }
    table { min-width: 0 !important; font-size: 11px; }
    th, td { padding: 6px 7px !important; }
    .lv { border-radius: 6px !important; }
    #guides { break-before: page; } main { grid-template-columns: minmax(0,1fr) !important; } .basics { grid-template-columns: repeat(3, minmax(0,1fr)) !important; } .lv, tbody th, thead th { white-space: normal !important; } thead th { font-size: 9px !important; letter-spacing: 0 !important; } .rail .group { break-after: avoid; } code, .path { white-space: normal !important; }
  </style>`;
  const html = '<!doctype html><html lang="en"><head><meta charset="utf-8">' + printCss.replace('<style>', '<style>') + '</head><body>' + body.replace('</style>', '</style>' + printCss) + '</body></html>';
  // The machine's own Chrome when Playwright's browser isn't downloaded (npx playwright install).
  const chrome = ['C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe'].find(p => fs.existsSync(p));
  let b;
  try { b = await chromium.launch(); } catch (e) {
    if (!chrome) throw e;
    b = await chromium.launch({ executablePath: chrome });
  }
  const pg = await b.newPage({ colorScheme: 'light' });
  await pg.setContent(html, { waitUntil: 'networkidle' });
  await pg.emulateMedia({ media: 'print', colorScheme: 'light' });
  await pg.pdf({ path: out, format: 'A4', printBackground: true, outline: true, tagged: true,
    displayHeaderFooter: true, headerTemplate: '<span></span>',
    footerTemplate: '<div style="font-size:8px;width:100%;text-align:center;color:#888">Murano user manual - page <span class="pageNumber"></span> of <span class="totalPages"></span></div>',
    margin: { top: '14mm', bottom: '16mm', left: '12mm', right: '12mm' } });
  await b.close();
  console.log('ok', fs.statSync(out).size);
})();

