// Flyer de Murano (user, 2026-11-16): 3 páginas A4 para ofrecer Murano a cualquier empresa. Solo lo ve el SuperAdmin,
// desde el libro del manual. Capturas: shots.cjs (datos de demostración de una compañía de prueba) -> crop.ps1 -> img/*.jpg.
// node KT-TOOLS/murano/flyer/build_flyer.cjs  -> Murano_Flyer.html / .pdf y public/murano-flyer.pdf (dirección pública)
const path = require('path');
const fs = require('fs');
const { chromium } = require(path.join(__dirname, '../../../node_modules/playwright'));

const NAVY = '#0b1f4d', BLUE = '#1565d8', CYAN = '#06b6d4', PURPLE = '#7c3aed', PINK = '#db2777', ORANGE = '#f97316',
  INK = '#14182b', INK2 = '#4b5168', MUTED = '#8a90a6', LINE = '#e3e7f0', SOFT = '#f3f6fc';
const GRAD = `linear-gradient(90deg, ${CYAN}, ${BLUE} 35%, ${PURPLE} 60%, ${PINK} 80%, ${ORANGE})`;
const LOGO = '../../../public/images/login/murano-logo.png';
const MARK = '../../../public/images/login/murano-mark.png';

// ---- Diagrama de un flujo: pasos en fila con flechas, y debajo los módulos que lo cubren ----
function flow(steps, color) {
  const W = 900, bw = (W - (steps.length - 1) * 22) / steps.length, H = 56;
  let s = `<svg viewBox="0 0 ${W} ${H}" width="100%" xmlns="http://www.w3.org/2000/svg" font-family="Segoe UI, Roboto, Arial">`;
  s += `<defs><marker id="ar${color.slice(1)}" markerWidth="8" markerHeight="8" refX="7" refY="4" orient="auto"><path d="M0,0 L8,4 L0,8 z" fill="${color}"/></marker></defs>`;
  steps.forEach(([t, sub], i) => {
    const x = i * (bw + 22);
    s += `<rect x="${x}" y="3" width="${bw}" height="50" rx="10" fill="${i === 0 ? color : '#fff'}" stroke="${color}" stroke-width="1.5"/>`;
    s += `<text x="${x + bw / 2}" y="25" text-anchor="middle" font-size="14" font-weight="700" fill="${i === 0 ? '#fff' : NAVY}">${t}</text>`;
    s += `<text x="${x + bw / 2}" y="42" text-anchor="middle" font-size="11.5" fill="${i === 0 ? '#eef' : INK2}">${sub}</text>`;
    if (i < steps.length - 1) s += `<line x1="${x + bw + 3}" y1="28" x2="${x + bw + 18}" y2="28" stroke="${color}" stroke-width="1.6" marker-end="url(#ar${color.slice(1)})"/>`;
  });
  return s + '</svg>';
}

// ---- Velocidad de desarrollo: antes / ahora, en una escala de tiempo (meses -> semanas -> días -> horas) ----
function speed() {
  const rows = [['Un módulo completo', 'meses', 'semanas', 0.92, 0.42], ['Un módulo mediano', 'semanas', 'días', 0.6, 0.24], ['Un ajuste o un informe', 'días', 'horas', 0.36, 0.08]];
  const W = 560, L = 132, P = W - L - 96, bh = 13;
  let s = `<svg viewBox="0 0 ${W} 150" width="100%" xmlns="http://www.w3.org/2000/svg" font-family="Segoe UI, Roboto, Arial">`;
  s += `<defs><linearGradient id="g1" x1="0" x2="1"><stop offset="0" stop-color="${CYAN}"/><stop offset=".55" stop-color="${PURPLE}"/><stop offset="1" stop-color="${PINK}"/></linearGradient></defs>`;
  rows.forEach(([t, before, now, b, n], i) => {
    const y = 8 + i * 46;
    s += `<text x="0" y="${y + 18}" font-size="11.5" font-weight="600" fill="${INK}">${t}</text>`;
    s += `<rect x="${L}" y="${y + 2}" width="${b * P}" height="${bh}" rx="4" fill="#cbd2e1"/>`;
    s += `<text x="${L + b * P + 6}" y="${y + 13}" font-size="10" fill="${INK2}">antes: ${before}</text>`;
    s += `<rect x="${L}" y="${y + 19}" width="${Math.max(n * P, 10)}" height="${bh}" rx="4" fill="url(#g1)"/>`;
    s += `<text x="${L + Math.max(n * P, 10) + 6}" y="${y + 30}" font-size="10.5" font-weight="700" fill="${PURPLE}">con IA: ${now}</text>`;
  });
  return s + '</svg>';
}

const pill = (t, c = NAVY) => `<span class="pill" style="border-color:${c}33;color:${c}">${t}</span>`;
const shot = (img, cap, pos) => `<figure class="shot"><img src="img/${img}.jpg"${pos ? ` style="object-position:${pos}"` : ''}><figcaption>${cap}</figcaption></figure>`;
const foot = n => `<div class="foot"><img src="${MARK}"><span>Murano · un producto de <b>KcolTech</b> · murano.kcoltech.com</span><span class="pg">${n} / 3</span></div>`;

// Una necesidad del cliente: título, frase, diagrama, módulos y (opcional) capturas.
function need({ n, color, title, lead, steps, mods, shots = [], side = false }) {
  return `<section class="need">
    <div class="need-h"><span class="num" style="background:${color}">${n}</span><div><h3>${title}</h3><p>${lead}</p></div></div>
    <div class="diag">${flow(steps, color)}</div>
    ${side ? '<div class="side">' : ''}<div class="mods"><b style="color:${color}">Tenemos:</b> ${mods.map(m => pill(m, color)).join('')}</div>
    ${shots.length ? `<div class="shots s${shots.length}">${shots.map(([i, c, p]) => shot(i, c, p)).join('')}</div>` : ''}${side ? '</div>' : ''}
  </section>`;
}

const html = `<!doctype html><html lang="es"><head><meta charset="utf-8"><title>Murano · KcolTech</title>
<style>
  @page { size: A4; margin: 0; }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: 'Segoe UI', Roboto, Arial, sans-serif; color: ${INK}; font-size: 11.5px; line-height: 1.45; }
  .page { width: 210mm; height: 297mm; padding: 12mm 13mm 14mm; page-break-after: always; position: relative; overflow: hidden; }
  .page:last-child { page-break-after: auto; }
  .bar { height: 5px; border-radius: 3px; background: ${GRAD}; }
  h1 { font-size: 29px; line-height: 1.12; margin: 0; color: ${NAVY}; letter-spacing: -.01em; }
  h1 em { font-style: normal; background: ${GRAD}; -webkit-background-clip: text; color: transparent; }
  h2 { font-size: 19px; margin: 0 0 3mm; color: ${NAVY}; }
  h3 { font-size: 14.5px; margin: 0; color: ${NAVY}; }
  p { margin: 0; }
  .cover { display: grid; grid-template-columns: 1fr; gap: 4mm; }
  .logo { width: 108mm; display: block; margin: 0 0 3mm -2mm; }
  .lead { font-size: 13.5px; color: ${INK2}; max-width: 165mm; }
  .pillars { display: grid; grid-template-columns: repeat(4, 1fr); gap: 3mm; }
  .pillar { border: 1px solid ${LINE}; border-radius: 10px; padding: 3.2mm; background: #fff; position: relative; overflow: hidden; }
  .pillar::before { content: ''; position: absolute; left: 0; top: 0; right: 0; height: 3px; background: ${GRAD}; }
  .pillar b.big { display: block; font-size: 21px; color: ${NAVY}; line-height: 1.1; margin-bottom: 1mm; }
  .pillar span { color: ${INK2}; font-size: 10.5px; }
  .two { display: grid; grid-template-columns: 1.05fr .95fr; gap: 5mm; align-items: start; }
  .card { border: 1px solid ${LINE}; border-radius: 12px; padding: 4mm; background: ${SOFT}; }
  .card h3 { margin-bottom: 2mm; }
  .shot { margin: 0; border: 1px solid ${LINE}; border-radius: 9px; overflow: hidden; background: #fff; box-shadow: 0 4px 14px rgba(11,31,77,.08); }
  .shot img { display: block; width: 100%; max-height: 52mm; object-fit: cover; object-position: top left; }
  .side { display: grid; grid-template-columns: 1fr 1fr; gap: 4mm; align-items: start; }
  .side .shots { margin-top: 0; }
  .shots.s1 .shot img { max-height: 30mm; }
  .p2 .shot img { max-height: 66mm; }
  .shot figcaption { font-size: 9.5px; color: ${INK2}; padding: 1.6mm 2.4mm; border-top: 1px solid ${LINE}; background: #fff; }
  .gen { display: grid; grid-template-columns: repeat(3, 1fr); gap: 2.4mm 4mm; }
  .gen div { padding-left: 4mm; position: relative; font-size: 10.5px; color: ${INK2}; }
  .gen div::before { content: ''; position: absolute; left: 0; top: 5px; width: 7px; height: 7px; border-radius: 50%; background: ${GRAD}; }
  .gen b { color: ${INK}; }
  .k25 { display: flex; gap: 4mm; align-items: center; background: ${NAVY}; color: #fff; border-radius: 12px; padding: 4mm 5mm; }
  .k25 .n { font-size: 40px; font-weight: 800; line-height: 1; background: ${GRAD}; -webkit-background-clip: text; color: transparent; }
  .k25 p { color: #d6dcf0; font-size: 11px; }
  .k25 b { color: #fff; }
  .need { border: 1px solid ${LINE}; border-radius: 12px; padding: 3.6mm 4mm; margin-bottom: 3.6mm; background: #fff; }
  .need-h { display: flex; gap: 3mm; align-items: flex-start; margin-bottom: 2mm; }
  .need-h p { color: ${INK2}; font-size: 11px; }
  .num { flex: none; width: 8mm; height: 8mm; border-radius: 50%; color: #fff; font-weight: 800; display: flex; align-items: center; justify-content: center; font-size: 13px; }
  .diag { margin: 1mm 0 2mm; }
  .mods { line-height: 2; }
  .pill { display: inline-block; border: 1px solid; border-radius: 999px; padding: 0 2.4mm; margin: 0 1.2mm .4mm 0; font-size: 10px; line-height: 1.75; background: #fff; }
  .shots { display: grid; gap: 3mm; margin-top: 2.4mm; }
  .shots.s2 { grid-template-columns: 1fr 1fr; }
  .shots.s1 { grid-template-columns: 1fr; }
  .ask { font-size: 21px; font-weight: 800; color: ${NAVY}; margin: 0 0 3mm; }
  .ask em { font-style: normal; background: ${GRAD}; -webkit-background-clip: text; color: transparent; }
  .foot { position: absolute; left: 13mm; right: 13mm; bottom: 6mm; display: flex; align-items: center; gap: 2.4mm; font-size: 9.5px; color: ${MUTED}; border-top: 1px solid ${LINE}; padding-top: 2mm; }
  .foot img { height: 6mm; }
  .foot .pg { margin-left: auto; }
  .cta { display: grid; grid-template-columns: 1fr auto; gap: 5mm; align-items: center; background: ${NAVY}; color: #fff; border-radius: 12px; padding: 4.5mm 5.5mm; }
  .cta h3 { color: #fff; font-size: 16px; }
  .cta p { color: #d6dcf0; font-size: 11px; }
  .cta .addr { font-size: 15px; font-weight: 700; text-align: right; }
  .cta .addr small { display: block; font-weight: 400; color: #aeb8d8; font-size: 10.5px; }
</style></head><body>

<!-- ============ PÁGINA 1: Murano ============ -->
<div class="page">
  <div class="cover">
    <img class="logo" src="${LOGO}">
    <div class="bar"></div>
    <h1>Murano: el sistema de gestión para su empresa,<br><em>100 % creado con Inteligencia Artificial</em></h1>
    <p class="lead">Ventas, facturas, personal, almacén, tienda en línea, formularios y marketing en <b>un solo sistema en la nube</b>. Usted elige los módulos que su empresa necesita y nosotros los adaptamos a su forma de trabajar, en tiempos que antes eran imposibles.</p>
    <div class="pillars">
      <div class="pillar"><b class="big">100 % IA</b><span>Cada módulo de Murano fue diseñado, programado y probado con Inteligencia Artificial, guiada por nuestros ingenieros.</span></div>
      <div class="pillar"><b class="big">En la nube</b><span>Sin servidores ni instalaciones: entra desde la computadora, la tableta o el celular, desde donde esté.</span></div>
      <div class="pillar"><b class="big">De meses a horas</b><span>Un módulo nuevo o un cambio a su medida llega en una fracción del tiempo del desarrollo tradicional.</span></div>
      <div class="pillar"><b class="big">+95 %</b><span>de estabilidad y seguridad, con la experiencia de KcolTech en seguridad de redes.</span></div>
    </div>
    <div class="two">
      <div class="card"><h3>Desarrollo con IA: pasamos de meses a semanas, de semanas a días y de días a horas</h3>${speed()}</div>
      ${shot('dashboard', 'El tablero principal: ventas del periodo y facturas por cobrar según su antigüedad.')}
    </div>
    <div class="k25"><div class="n">25+</div><p><b>años diseñando sistemas de seguridad para redes informáticas</b> en <b>Perú</b>, <b>Estados Unidos</b> y <b>Puerto Rico</b>. KcolTech pone esa experiencia en cada capa de Murano: accesos, datos y copias de seguridad.</p></div>
    <div class="card" style="background:#fff">
      <h3>Lo que trae Murano, sea cual sea su rubro</h3>
      <div class="gen">
        <div><b>Varias empresas y locales</b> en una sola cuenta, cada uno con sus datos.</div>
        <div><b>Roles y permisos:</b> cada persona ve solo lo que su trabajo necesita.</div>
        <div><b>Español e inglés</b>, en pantallas, facturas y correos.</div>
        <div><b>Verificación en dos pasos</b> y alertas de inicio de sesión.</div>
        <div><b>Copias de seguridad diarias</b> fuera del sitio web.</div>
        <div><b>Historial de cambios:</b> quién cambió qué y cuándo.</div>
        <div><b>Portal del cliente:</b> sus facturas, citas y pagos.</div>
        <div><b>Se instala como app</b> en el celular, sin tiendas.</div>
        <div><b>Manual integrado</b> en cada pantalla.</div>
      </div>
    </div>
  </div>
  ${foot(1)}
</div>

<!-- ============ PÁGINA 2: facturas y personal ============ -->
<div class="page p2">
  <p class="ask">¿Su empresa necesita…? <em>Murano ya lo tiene.</em></p>
  ${need({ n: 1, color: BLUE, title: 'Administrar sus facturas', lead: 'De la cotización al cobro y a la contabilidad, sin volver a escribir nada: cada venta guarda su factura en PDF y su asiento contable.',
    steps: [['Cotización', 'precio y condiciones'], ['Venta / factura', 'PDF, correo, SMS'], ['Cobro', 'efectivo, tarjeta, PayPal'], ['Contabilidad', 'asientos automáticos'], ['Informes', 'ganancia, impuestos']],
    mods: ['Ventas', 'Cotizaciones', 'Devoluciones', 'Pagos y cobranza', 'Contratos', 'Suscripciones', 'Gastos', 'Contabilidad', 'Portal del cliente', 'PayPal'],
    shots: [['sale_detail', 'Detalle de una venta: cliente, productos, pagos y saldo; PDF, correo o SMS con un clic.'], ['accounting', 'Contabilidad: cuentas, asientos, balance y estado de resultados; ingresos frente a gastos.']] })}
  ${need({ n: 2, color: PURPLE, title: 'Administrar la información de su personal', lead: 'Cada empleado con su ficha y su propio acceso; la asistencia y los permisos alimentan la planilla, y la planilla pagada pasa a la contabilidad.',
    steps: [['Empleado', 'ficha y acceso propio'], ['Turnos y asistencia', 'entrada y salida'], ['Permisos', 'vacaciones, feriados'], ['Planilla', 'horas, comisión o sueldo'], ['Contabilidad', 'sueldos y comisiones']],
    mods: ['Empleados', 'Departamentos y cargos', 'Turnos', 'Asistencia', 'Permisos y vacaciones', 'Feriados', 'Planilla semanal', 'Contratos', 'Reclutamiento', 'Tareas diarias'],
    shots: [['employees', 'Empleados: departamento, cargo, turno y rol de cada uno.'], ['payrolls', 'Planilla: cada semana por horas trabajadas o por comisión, pagada y contabilizada.']] })}
  ${foot(2)}
</div>

<!-- ============ PÁGINA 3: almacén, venta en línea, formularios y marketing ============ -->
<div class="page">
  ${need({ n: 3, color: '#0891b2', title: 'Administrar un almacén', lead: 'Lo que compra entra al almacén correcto, se mueve entre locales y sale vendido; siempre sabe cuánto tiene y cuánto vale.',
    steps: [['Proveedor', 'sus datos y saldo'], ['Compra', 'entrada de mercadería'], ['Almacenes', 'uno o varios locales'], ['Transferencias', 'y ajustes'], ['Venta / POS', 'salida automática']],
    mods: ['Productos y servicios', 'Compras', 'Proveedores', 'Almacenes', 'Transferencias', 'Ajustes', 'Lotes y series', 'Códigos de barras', 'Informe de existencias', 'Activos de la empresa'],
    shots: [['products', 'Productos: costo, precio, código, categoría y existencias.'], ['stock', 'Existencias: unidades, alertas de stock bajo y por categoría.']] })}
  ${need({ n: 4, color: PINK, title: 'Vender en línea', lead: 'Su tienda y su sitio web leen los mismos productos y precios del sistema; cada pedido llega a Murano listo para despachar y facturar.',
    steps: [['Sitio web', 'su dirección propia'], ['Tienda en línea', 'carrito, cupones, ofertas'], ['Pedido', 'pago en línea o contra entrega'], ['Despacho', 'envío y seguimiento'], ['Factura', 'y su contabilidad']],
    mods: ['Tienda en línea', 'Sitio web', 'Reservas en línea', 'Catálogo en tableta', 'Punto de venta (POS)', 'PayPal', 'WooCommerce', 'Shopify'],
    side: true, shots: [['store_orders', 'Pedidos en línea: estado y forma de pago, para confirmar o cancelar.', 'center bottom']] })}
  ${need({ n: 5, color: ORANGE, title: 'Administrar los formularios de «Contáctenos» y hacer marketing', lead: 'Los formularios de su web llegan a Murano y le avisan por correo; cada contacto se vuelve un cliente al que le puede escribir por correo, SMS o WhatsApp.',
    steps: [['Formulario web', 'Contáctenos, reclamos'], ['Murano', 'respuestas y aviso'], ['Cliente', 'con su historial'], ['Campañas', 'correo, SMS, WhatsApp'], ['Mesa de ayuda', 'tickets y respuestas']],
    mods: ['Formularios', 'Marketing', 'Segmentos de clientes', 'Plantillas', 'Mesa de ayuda', 'Correo de la oficina', 'Portal del cliente'] })}
  <div class="cta"><div><h3>Murano se adapta a su empresa, no al revés.</h3><p>Cuéntenos qué necesita: le mostramos Murano con sus módulos y, si le falta algo, lo construimos con IA.</p></div>
    <div class="addr">murano.kcoltech.com<small>KcolTech · Perú · Estados Unidos · Puerto Rico</small></div></div>
  ${foot(3)}
</div>
</body></html>`;

(async () => {
  const out = path.join(__dirname, 'Murano_Flyer.html');
  fs.writeFileSync(out, html);
  const chrome = ['C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe'].find(p => fs.existsSync(p));
  let b;
  try { b = await chromium.launch(); } catch (e) { b = await chromium.launch({ executablePath: chrome }); }
  const pg = await b.newPage({ colorScheme: 'light', viewport: { width: 794, height: 1123 } });
  await pg.goto('file:///' + out.replace(/\\/g, '/'), { waitUntil: 'load' });
  await pg.waitForTimeout(500);
  // Revisión: cuánto ocupa cada página (no debe pasar de 297 mm) y una imagen de cada una.
  const over = await pg.$$eval('.page', ps => ps.map(p => Math.max(...[...p.children].filter(c => !c.classList.contains('foot')).map(c => c.getBoundingClientRect().bottom)) - p.getBoundingClientRect().top));
  console.log('content bottom (px, page = 1123, foot at ~1080):', over.map(Math.round).join(', '));
  const pages = await pg.$$('.page');
  for (const [i, p] of pages.entries()) await p.screenshot({ path: path.join(__dirname, `page_${i + 1}.png`) });
  const pdf = path.join(__dirname, 'Murano_Flyer.pdf');
  await pg.pdf({ path: pdf, format: 'A4', printBackground: true, margin: { top: 0, bottom: 0, left: 0, right: 0 } });
  // Public, no sign-in (user, 2026-11-16: shared with prospective customers): https://murano.kcoltech.com/murano-flyer.pdf
  fs.copyFileSync(pdf, path.join(__dirname, '../../../public/murano-flyer.pdf'));
  console.log('ok', fs.statSync(pdf).size);
  await b.close();
})().catch(e => { console.error(e); process.exit(1); });
