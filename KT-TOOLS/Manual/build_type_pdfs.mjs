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
const { TYPES, advantagesFor } = await load('resources/src/manual/advantages.js');
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
  }
}
await b.close();
console.log(n, 'PDFs in public/manual');
