/**
 * Transport printouts (2026-11-04), in their own window: the boarding pass with its QR, as on a plane, and the
 * parcel's guide with the QR the customer follows it by.
 */
import QRCode from 'qrcode';
import dayjs from 'dayjs';

const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const when = d => (d ? dayjs(d).format('DD/MM/YYYY HH:mm') : '');
const day = d => (d ? dayjs(d).format('DD/MM/YYYY') : '');

function open(title, css, body) {
  const w = window.open('', '_blank', 'width=420,height=640');
  if (!w) return false;
  w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>${esc(title)}</title><style>
    * { box-sizing: border-box; } body { margin: 0; font-family: system-ui, -apple-system, 'Segoe UI', Roboto, Arial, sans-serif; color: #0f172a; }
    .card { width: 80mm; margin: 0 auto; padding: 4mm; page-break-after: always; text-align: center; }
    .route { font-size: 17px; font-weight: 800; margin: 2mm 0; } .big { font-size: 26px; font-weight: 800; }
    .row { display: flex; justify-content: space-between; gap: 8px; font-size: 12px; padding: 1mm 0; border-bottom: 1px dashed #cbd5e1; text-align: left; }
    .row b { text-align: right; } img { width: 42mm; height: 42mm; } .code { font-family: monospace; font-size: 13px; letter-spacing: 1px; }
    .tag { display: inline-block; background: #0f172a; color: #fff; border-radius: 4px; padding: 1px 8px; font-size: 12px; font-weight: 700; }
    ${css}
    @media print { .noprint { display: none !important; } @page { margin: 0; } }
  </style></head><body><div class="noprint" style="text-align:center;padding:8px"><button onclick="window.print()">Imprimir</button></div>${body}
  <script>window.onload = function () { setTimeout(function () { window.print(); }, 200); };<\/script></body></html>`);
  w.document.close();
  return true;
}

/** One boarding pass per ticket. */
export async function printBoardingPasses(tickets, es = true) {
  const cards = await Promise.all(tickets.map(async t => {
    const qr = await QRCode.toDataURL(String(t.code || ''), { margin: 1, width: 240 });
    return `<div class="card">
      <div class="route">${esc(t.from_name)} → ${esc(t.to_name)}</div>
      <div class="big">${es ? 'Asiento' : 'Seat'} ${esc(t.seat_number ?? '—')}</div>
      ${t.is_vip ? '<div class="tag">VIP · snack y bebida</div>' : ''}
      <img src="${qr}" alt="">
      <div class="code">${esc(t.code)}</div>
      <div class="row"><span>${es ? 'Pasajero' : 'Passenger'}</span><b>${esc(t.first_name)} ${esc(t.last_name)}</b></div>
      <div class="row"><span>${esc(String(t.doc_type || '').toUpperCase())}</span><b>${esc(t.dni)}</b></div>
      <div class="row"><span>${es ? 'Sale' : 'Leaves'}</span><b>${esc(when(t.boards_at))}</b></div>
      <div class="row"><span>${es ? 'Bus' : 'Bus'}</span><b>${esc(t.vehicle)}${t.floor ? ` · ${es ? 'piso' : 'floor'} ${t.floor}` : ''}</b></div>
      <div class="row"><span>${es ? 'Equipaje' : 'Luggage'}</span><b>${t.luggage ? (es ? 'Sí' : 'Yes') : 'No'}</b></div>
    </div>`;
  }));
  return open(es ? 'Boleto' : 'Boarding pass', '', cards.join(''));
}

/** The parcel's guide: one for the sender, one to stick on the box. */
export async function printParcelGuide(p, es = true) {
  const qr = await QRCode.toDataURL(String(p.tracking_url || p.number || ''), { margin: 1, width: 240 });
  const card = copy => `<div class="card">
    <div class="route">${esc(p.from_name)} → ${esc(p.to_name)}</div>
    <div class="big">${esc(p.number)}</div>
    <div class="tag">${esc(copy)}</div>
    <img src="${qr}" alt="">
    <div style="font-size:11px;color:#475569">${es ? 'Escanee para seguir su encomienda' : 'Scan to follow your parcel'}</div>
    <div class="row"><span>${es ? 'Remite' : 'From'}</span><b>${esc(p.sender_name)}</b></div>
    <div class="row"><span>${es ? 'Recibe' : 'To'}</span><b>${esc(p.receiver_name)}${p.receiver_phone ? ` · ${esc(p.receiver_phone)}` : ''}</b></div>
    <div class="row"><span>${es ? 'Contenido' : 'Contents'}</span><b>${esc(p.description || '—')}${p.cargo_type === 'fragile' ? (es ? ' · FRÁGIL' : ' · FRAGILE') : ''}</b></div>
    <div class="row"><span>${es ? 'Medidas' : 'Size'}</span><b>${Number(p.length_cm)} × ${Number(p.width_cm)} × ${Number(p.height_cm)} cm${p.pieces > 1 ? ` × ${p.pieces}` : ''}</b></div>
    <div class="row"><span>${es ? 'Peso' : 'Weight'}</span><b>${Number(p.weight_kg)} kg</b></div>
    <div class="row"><span>${es ? 'Llega' : 'Arrives'}</span><b>${esc(day(p.eta_from))} – ${esc(day(p.eta_to))}</b></div>
    <div class="row"><span>${es ? 'Pago' : 'Payment'}</span><b>${p.payment === 'collect' ? (es ? 'Paga quien recibe' : 'Receiver pays') : (es ? 'Pagado' : 'Paid')} · S/ ${Number(p.price).toFixed(2)}</b></div>
  </div>`;
  return open(`${es ? 'Guía' : 'Guide'} ${p.number}`, '', card(es ? 'REMITENTE' : 'SENDER') + card(es ? 'PAQUETE' : 'PARCEL'));
}
