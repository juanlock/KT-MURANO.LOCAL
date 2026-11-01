// The user manual as one PDF per business type and language (user's request, 2026-11-01):
// public/manual/murano-{type}-{en|es}.pdf -- downloaded from the manual drawer, tab "Your company".
// Each PDF: "Murano for <type>" (the advantages summary, at most two pages), then the manual
// entries of the modules that type starts with (all sections, no role filter).
// Run from the repo root, after `database/scripts/manual_business_types.php`:
//   node KT-TOOLS/Manual/build_type_pdfs.mjs
import fs from 'fs';
import os from 'os';
import path from 'path';
import { createRequire } from 'module';
import { fileURLToPath, pathToFileURL } from 'url';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.join(here, '..', '..');
const require = createRequire(import.meta.url);
const { chromium } = require(path.join(root, 'node_modules', 'playwright'));

// manual.js / advantages.js are ES modules of the SPA; imported through .mjs copies.
async function load(rel) {
  const tmp = path.join(os.tmpdir(), 'murano-' + path.basename(rel, '.js') + '-' + Date.now() + '.mjs');
  fs.copyFileSync(path.join(root, rel), tmp);
  try { return await import(pathToFileURL(tmp).href); } finally { fs.unlinkSync(tmp); }
}
const { MANUAL, MANUAL_UPDATED } = await load('resources/src/manual/manual.js');
const { TYPES, FLYER, advantagesFor } = await load('resources/src/manual/advantages.js');
const types = JSON.parse(fs.readFileSync(path.join(here, 'business_types.json'), 'utf8'));
const outDir = path.join(root, 'public', 'manual');
fs.mkdirSync(outDir, { recursive: true });

const esc = t => String(t ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const fmt = t => esc(t).replace(/\*\*(.+?)\*\*/g, '<b>$1</b>');
const T = {
  en: { for: 'Murano for', does: 'What Murano does for you', every: 'And every company gets', modules: 'Modules included', manual: 'User manual', contents: 'Contents', note: 'Note', updated: 'Updated', page: 'page', of: 'of' },
  es: { for: 'Murano para', does: 'Lo que Murano hace por usted', every: 'Y toda empresa tiene', modules: 'Módulos incluidos', manual: 'Manual de usuario', contents: 'Contenido', note: 'Nota', updated: 'Actualizado', page: 'página', of: 'de' },
};

function section(s, l) {
  let h = `<h3>${esc(s.title?.[l])}</h3>`;
  if (s.intro) h += `<p class="intro">${fmt(s.intro[l])}</p>`;
  if (s.steps) h += `<ol>${s.steps[l].map(x => `<li>${fmt(x)}</li>`).join('')}</ol>`;
  if (s.icons) h += `<ul>${s.icons.map(i => `<li><b>${esc(i.title[l])}</b> — ${fmt(i.text[l])}</li>`).join('')}</ul>`;
  if (s.note) h += `<p class="note"><b>${T[l].note}:</b> ${fmt(s.note[l])}</p>`;
  return h;
}

function html(type, l) {
  const mods = types[type].modules;
  const on = m => !m || mods.includes(m);
  const a = advantagesFor(type, mods);
  const entries = MANUAL.filter(m => on(m.module)).map(m => ({ ...m, sections: m.sections.filter(s => on(s.module)) })).filter(m => m.sections.length);
  const css = `
    @page { size: A4; margin: 16mm 14mm; }
    body { font-family: "Segoe UI", Arial, sans-serif; color: #1f2328; font-size: 11.5px; line-height: 1.5; margin: 0; }
    .cover { border-bottom: 3px solid #4f46e5; padding-bottom: 10px; margin-bottom: 14px; }
    .brand { color: #4f46e5; font-weight: 700; letter-spacing: .5px; font-size: 13px; }
    h1 { font-size: 24px; margin: 4px 0 2px; } .tag { font-size: 14px; color: #4f46e5; font-weight: 600; margin: 0 0 4px; }
    .lead { color: #57606a; margin: 0; }
    h2 { font-size: 16px; color: #4f46e5; margin: 18px 0 6px; break-after: avoid; }
    h3 { font-size: 12.5px; margin: 12px 0 4px; break-after: avoid; }
    .adv { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 14px; margin: 0 0 6px; }
    .card { border: 1px solid #e3e2ef; border-radius: 8px; padding: 8px 10px; break-inside: avoid; }
    .card b { display: block; color: #3b2f8f; margin-bottom: 2px; }
    .mods { color: #57606a; } .mods span { display: inline-block; background: #eef0ff; color: #3b2f8f; border-radius: 10px; padding: 1px 8px; margin: 2px 4px 2px 0; }
    .manual { break-before: page; }
    .toc { columns: 2; column-gap: 20px; padding-left: 18px; } .toc li { break-inside: avoid; }
    .entry { break-before: auto; } .entry h2 { border-bottom: 1px solid #e3e2ef; padding-bottom: 3px; }
    .intro { color: #57606a; margin: 2px 0 4px; } ol, ul { margin: 2px 0 6px; padding-left: 18px; } li { margin: 2px 0; }
    .note { background: #f5f3ff; border-left: 3px solid #7c3aed; padding: 5px 8px; margin: 4px 0 8px; }
  `;
  const card = p => `<div class="card"><b>${esc(p.t[l])}</b>${esc(p.d[l])}</div>`;
  return `<!doctype html><html lang="${l}"><head><meta charset="utf-8"><title>Murano - ${esc(a.label[l])}</title><style>${css}</style></head><body>
    <div class="cover"><div class="brand">MURANO · KcolTech</div><h1>${T[l].for} ${esc(a.label[l])}</h1>
      <p class="tag">${esc(a.tagline[l])}</p><p class="lead">${esc(a.intro[l])}</p></div>
    <h2>${T[l].does}</h2><div class="adv">${a.points.map(card).join('')}</div>
    <h2>${T[l].every}</h2><div class="adv">${a.common.map(card).join('')}</div>
    <h2>${T[l].modules}</h2><p class="mods">${mods.map(m => `<span>${esc(m)}</span>`).join('')}</p>
    <div class="manual"><h2>${T[l].manual} · ${T[l].contents}</h2>
      <ol class="toc">${entries.map(m => `<li>${esc(m.title[l])}</li>`).join('')}</ol>
      ${entries.map(m => `<div class="entry"><h2>${esc(m.title[l])}</h2>${m.intro ? `<p class="intro">${fmt(m.intro[l])}</p>` : ''}${m.sections.map(s => section(s, l)).join('')}</div>`).join('')}
    </div></body></html>`;
}

// ---- The promotional flyer (user's request, 2026-11-01): two A4 pages per type and language,
// opening with the type's graphic (icon + the business flow Murano covers), then its advantages;
// page 2: what every company gets, the modules and how to ask for a demo.
const FT = {
  en: { for: 'Murano for', how: 'How it works in your business', all: 'All in Murano', adv: 'Advantages for you', plus: 'Also included', mods: 'Modules included', demo: 'Ask for a free demo', cloud: 'In the cloud · computer, tablet and phone · English and Spanish' },
  es: { for: 'Murano para', how: 'Cómo funciona en su negocio', all: 'Todo en Murano', adv: 'Ventajas para usted', plus: 'Además, incluido', mods: 'Módulos incluidos', demo: 'Pida una demostración gratuita', cloud: 'En la nube · computadora, tablet y celular · español e inglés' },
};
// Module keys (config/modules.js) as people read them.
const MOD = {
  accounting: ['Accounting', 'Contabilidad'], assets: ['Assets', 'Activos'], documents: ['Documents', 'Documentos'], email_office: ['Email Office', 'Email Office'],
  forms: ['Forms', 'Formularios'], gallery: ['Gallery', 'Galería'], helpdesk: ['Helpdesk', 'Helpdesk'], hrm: ['HR and payroll', 'RR. HH. y planillas'],
  website: ['Website', 'Sitio web'], sales: ['Sales', 'Ventas'], pos: ['POS', 'Punto de venta'], purchases: ['Purchases', 'Compras'], suppliers: ['Suppliers', 'Proveedores'],
  products: ['Products and stock', 'Productos y stock'], customers: ['Customers', 'Clientes'], bookings: ['Bookings', 'Reservas'], commissions: ['Commissions', 'Comisiones'],
  portfolio: ['Portfolio', 'Portafolio'], promotions: ['Promotions', 'Promociones'], marketing: ['Marketing', 'Marketing'], meeting: ['Meetings', 'Reuniones'],
  projects: ['Projects', 'Proyectos'], service: ['Service jobs', 'Trabajos de servicio'], fleet: ['Fleet', 'Flota'], kitchen: ['Kitchen', 'Cocina'],
  EWallet: ['E-Wallet', 'Monedero'], Store: ['Online store', 'Tienda en línea'], recruit: ['Recruitment', 'Reclutamiento'], realestate: ['Real estate', 'Bienes raíces'],
  hospital: ['Hospital management', 'Gestión hospitalaria'], manufacturing: ['Manufacturing (MRP)', 'Manufactura (MRP)'], school: ['School management', 'Gestión escolar'],
  intranet: ['Intranet', 'Intranet'],
};
const modName = (m, l) => (MOD[m] ? MOD[m][l === 'es' ? 1 : 0] : m);

function flyerHtml(type, l) {
  const mods = types[type].modules;
  const a = advantagesFor(type, mods);
  const f = FLYER[type] || FLYER.general;
  const c = f.color;
  const css = `
    @page { size: A4; margin: 0; }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: "Segoe UI", Arial, sans-serif; color: #1f2328; font-size: 12px; line-height: 1.45; }
    .page { width: 210mm; height: 297mm; padding: 0 0 12mm; position: relative; overflow: hidden; break-after: page; }
    .page:last-child { break-after: auto; }
    .hero { background: linear-gradient(135deg, ${c} 0%, #1e1b4b 100%); color: #fff; padding: 16mm 14mm 12mm; position: relative; }
    .hero .brand { font-weight: 700; letter-spacing: 2px; font-size: 11px; opacity: .85; }
    .hero h1 { font-size: 30px; margin: 6px 0 4px; line-height: 1.15; max-width: 140mm; }
    .hero .tag { font-size: 16px; font-weight: 600; margin: 0 0 6px; max-width: 140mm; }
    .hero .intro { opacity: .9; max-width: 135mm; margin: 0; }
    .hero .big { position: absolute; right: 14mm; top: 14mm; width: 42mm; height: 42mm; border-radius: 50%; background: rgba(255,255,255,.15);
      border: 3px solid rgba(255,255,255,.45); display: flex; align-items: center; justify-content: center; font-size: 72px; }
    .wrap { padding: 0 14mm; }
    h2 { color: ${c}; font-size: 17px; margin: 9mm 0 4mm; }
    .flow { display: flex; align-items: flex-start; justify-content: space-between; position: relative; padding: 2mm 2mm 0; }
    .flow::before { content: ''; position: absolute; left: 14mm; right: 14mm; top: 15mm; height: 4px; background: linear-gradient(90deg, ${c}, #a5b4fc); border-radius: 2px; }
    .step { width: 38mm; text-align: center; position: relative; }
    .step .dot { width: 26mm; height: 26mm; margin: 0 auto 3mm; border-radius: 50%; background: #fff; border: 4px solid ${c};
      display: flex; align-items: center; justify-content: center; font-size: 36px; box-shadow: 0 3px 10px rgba(0,0,0,.12); }
    .step .n { position: absolute; top: -1mm; left: 6mm; background: ${c}; color: #fff; border-radius: 50%; width: 7mm; height: 7mm; font-size: 11px;
      font-weight: 700; display: flex; align-items: center; justify-content: center; }
    .step b { font-size: 12.5px; }
    .hub { margin: 4mm auto 0; width: 70mm; text-align: center; background: ${c}; color: #fff; border-radius: 20px; padding: 2mm 0; font-weight: 700; letter-spacing: .5px; }
    .cards { display: grid; grid-template-columns: 1fr 1fr; gap: 4mm; }
    .card { border: 1px solid #e5e7eb; border-left: 5px solid ${c}; border-radius: 8px; padding: 3mm 4mm; background: #fafafa; }
    .card b { display: block; font-size: 13px; color: #111827; margin-bottom: 1mm; }
    .card.wide { grid-column: span 2; }
    .cards3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 3.5mm; }
    .mini { border-radius: 8px; padding: 3mm; background: #f5f3ff; }
    .mini b { display: block; color: #3b2f8f; margin-bottom: 1mm; }
    .chips span { display: inline-block; background: #eef2ff; color: #3730a3; border-radius: 12px; padding: 1mm 3mm; margin: 0 2mm 2mm 0; font-size: 11px; }
    .modband { position: absolute; left: 14mm; right: 14mm; bottom: 12mm; border: 2px solid ${c}; border-radius: 12px; padding: 5mm 6mm 3mm; background: #fff; }
    .modband .mt { color: ${c}; font-weight: 700; font-size: 15px; margin-bottom: 3mm; }
    .modband .chips span { font-size: 12px; padding: 1.2mm 3.5mm; }
    .cta { position: absolute; left: 14mm; right: 14mm; bottom: 14mm; background: linear-gradient(135deg, ${c}, #1e1b4b); color: #fff; border-radius: 12px; padding: 7mm 8mm; }
    .cta h3 { margin: 0 0 2mm; font-size: 20px; }
    .cta .line { font-size: 13px; opacity: .95; }
    .cta .small { font-size: 11px; opacity: .8; margin-top: 2mm; }
    .p2head { padding: 12mm 14mm 0; display: flex; align-items: center; gap: 4mm; }
    .p2head .ic { font-size: 34px; } .p2head .t { font-size: 18px; font-weight: 700; color: ${c}; }
  `;
  const steps = f.flow.map((s, i) => `<div class="step"><span class="n">${i + 1}</span><div class="dot">${s.icon}</div><b>${esc(s.t[l])}</b></div>`).join('');
  const pts = a.points.map((p, i) => `<div class="card${i === a.points.length - 1 && a.points.length % 2 ? ' wide' : ''}"><b>${esc(p.t[l])}</b>${esc(p.d[l])}</div>`).join('');
  return `<!doctype html><html lang="${l}"><head><meta charset="utf-8"><title>Murano - ${esc(a.label[l])}</title><style>${css}</style></head><body>
    <div class="page">
      <div class="hero"><div class="brand">MURANO · KCOLTECH</div><div class="big">${f.icon}</div>
        <h1>${FT[l].for} ${esc(a.label[l])}</h1><p class="tag">${esc(a.tagline[l])}</p><p class="intro">${esc(a.intro[l])}</p></div>
      <div class="wrap">
        <h2>${FT[l].how}</h2><div class="flow">${steps}</div><div class="hub">${FT[l].all}</div>
        <h2>${FT[l].adv}</h2><div class="cards">${pts}</div>
      </div>
      <div class="modband"><div class="mt">${FT[l].mods}</div><div class="chips">${mods.map(m => `<span>${esc(modName(m, l))}</span>`).join('')}</div></div>
    </div>
    <div class="page">
      <div class="p2head"><span class="ic">${f.icon}</span><span class="t">Murano · ${esc(a.label[l])}</span></div>
      <div class="wrap">
        <h2>${FT[l].plus}</h2><div class="cards3">${a.common.map(p => `<div class="mini"><b>${esc(p.t[l])}</b>${esc(p.d[l])}</div>`).join('')}</div>
      </div>
      <div class="cta"><h3>${FT[l].demo}</h3>
        <div class="line">KcolTech · +1 732-547-5393 · info@kcoltech.com</div>
        <div class="line">kcoltech.com · murano.kcoltech.com</div>
        <div class="small">${FT[l].cloud}</div></div>
    </div></body></html>`;
}

// Preview: FLYER_HTML=<file> FLYER_TYPE=hospital FLYER_LANG=es writes one flyer's HTML and stops.
if (process.env.FLYER_HTML) {
  fs.writeFileSync(process.env.FLYER_HTML, flyerHtml(process.env.FLYER_TYPE || 'general', process.env.FLYER_LANG || 'es'));
  process.exit(0);
}

const chrome = ['C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe'].find(p => fs.existsSync(p));
let b;
try { b = await chromium.launch({ channel: 'msedge' }); } catch (e) { b = await chromium.launch(chrome ? { executablePath: chrome } : {}); }
const pg = await b.newPage({ colorScheme: 'light' });
let n = 0;
for (const type of Object.keys(types)) {
  if (!TYPES[type]) { console.log('no advantages text for', type, '- skipped'); continue; }
  for (const l of ['en', 'es']) {
    await pg.setContent(html(type, l), { waitUntil: 'load' });
    const out = path.join(outDir, `murano-${type}-${l}.pdf`);
    await pg.pdf({ path: out, format: 'A4', printBackground: true, outline: true, tagged: true, displayHeaderFooter: true,
      headerTemplate: '<span></span>',
      footerTemplate: `<div style="font-size:8px;width:100%;text-align:center;color:#888">Murano · ${esc(TYPES[type].label[l])} · ${T[l].updated} ${MANUAL_UPDATED} · ${T[l].page} <span class="pageNumber"></span> ${T[l].of} <span class="totalPages"></span></div>`,
      margin: { top: '14mm', bottom: '16mm', left: '13mm', right: '13mm' } });
    n++;
    // The flyer: exactly two pages, no header / footer.
    await pg.setContent(flyerHtml(type, l), { waitUntil: 'load' });
    await pg.pdf({ path: path.join(outDir, `flyer-${type}-${l}.pdf`), format: 'A4', printBackground: true, margin: { top: 0, bottom: 0, left: 0, right: 0 } });
    n++;
  }
}
await b.close();
console.log(n, 'PDFs in public/manual');
