/**
 * Reprint a sale's POS receipt in a popup (same layout the POS prints:
 * Settings -> POS Receipt). Shared by the Sales list and POS Sales.
 */
import http from './http';
import { buildPosReceiptHtml } from './posReceiptHtml';
import { receiptFontHeadTags, whenPrintFontsReady } from './receiptFont';

// qrcodejs loader shared by all receipt prints. Same source
// chain as PosPage.loadQRCodeLib (CDN → repaired local copy → setup copy);
// receipts still print without QRs if none of them loads.
let qrLibPromise = null;
function loadQrLib() {
  if (window.QRCode) return Promise.resolve();
  if (qrLibPromise) return qrLibPromise;
  const sources = [
    'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js',
    '/vendor/qrcode/qrcode.min.js?v=full-3',
    '/assets_setup/js/qrcode.js',
  ];
  qrLibPromise = new Promise(resolve => {
    const tryNext = i => {
      if (i >= sources.length || window.QRCode) return resolve();
      const el = document.createElement('script');
      el.src = sources[i];
      el.onload = () => (window.QRCode ? resolve() : tryNext(i + 1));
      el.onerror = () => tryNext(i + 1);
      document.head.appendChild(el);
    };
    tryNext(0);
  }).then(() => { if (!window.QRCode) qrLibPromise = null; });
  return qrLibPromise;
}

// Renders `text` as a QR into a detached container and returns the canvas
// pixels as a data URL (survives document.write, unlike a live canvas).
function qrDataUrl(text) {
  if (!window.QRCode || !text) return null;
  const host = document.createElement('div');
  host.style.cssText = 'position:absolute;left:-9999px;top:-9999px;';
  document.body.appendChild(host);
  try {
    new window.QRCode(host, {
      text: String(text),
      width: 100,
      height: 100,
      correctLevel: window.QRCode.CorrectLevel ? window.QRCode.CorrectLevel.M : 0,
    });
    const canvas = host.querySelector('canvas');
    return canvas ? canvas.toDataURL('image/png') : null;
  } catch (e) {
    return null;
  } finally {
    try { document.body.removeChild(host); } catch (e) {}
  }
}

export async function printPosReceipt(saleId, { t, number, onError } = {}) {
  try {
    const data = await http.get(`sales_print_invoice/${saleId}`);
    const s = data.sale || {};
    const setting = data.setting || {};
    const ps = data.pos_settings || {};

    // QR data URLs are rendered up-front — the popup document can't host the
    // Vue QR refs the POS uses. Skipped silently if the lib can't load.
    const onByDefault = f => Number(ps[f] ?? 1) !== 0;
    const wantZatca = setting.zatca_enabled && data.zatca_qr && onByDefault('show_zatca_qr');
    const wantInvoiceQr = onByDefault('show_barcode') && s.Ref;
    let zatcaUrl = null;
    let invoiceUrl = null;
    if (wantZatca || wantInvoiceQr) {
      await loadQrLib();
      if (wantZatca) zatcaUrl = qrDataUrl(data.zatca_qr);
      if (wantInvoiceQr) invoiceUrl = qrDataUrl(data.public_invoice_url || s.Ref);
    }

    // Same layout the POS prints (Settings → POS Receipt → layout 1-5),
    // via the shared string port of PosPage's receipt DOM.
    const body = buildPosReceiptHtml({
      t,
      sale: s,
      setting,
      ps,
      symbol: data.symbol || '',
      details: data.details || [],
      payments: data.payments || [],
      formatPrice: v => number(v),
      qr: { zatca: zatcaUrl, invoice: invoiceUrl },
    });

    const paperSize = [58, 80, 88].includes(Number(ps.receipt_paper_size)) ? Number(ps.receipt_paper_size) : 80;
    const fontTags = receiptFontHeadTags(ps, '#invoice-POS');
    const title = String(s.Ref ?? '').replace(/</g, '&lt;').replace(/>/g, '&gt;');

    const html = `<!doctype html><html><head><meta charset="utf-8"><title>${title}</title>
<link rel="stylesheet" href="/css/pos_print.css">
<style>
  #invoice-POS .receipt-qr-row{display:flex;flex-direction:row;flex-wrap:nowrap;justify-content:center;align-items:flex-start;gap:10px;width:100%;margin-top:8px;}
  #invoice-POS .receipt-qr-block{display:flex;flex-direction:column;align-items:center;flex:0 0 auto;width:100px;margin:0;}
  #invoice-POS .receipt-qr-title{font-weight:700;font-size:10px;letter-spacing:1px;text-transform:uppercase;text-align:center;margin:0 0 4px;line-height:1.2;display:block;width:100%;}
  #invoice-POS .receipt-qr-canvas img{display:block;margin:0 auto;width:100px;height:100px;}
</style>${fontTags}</head><body class="receipt-${paperSize}"><div id="invoice-POS">
<div style="max-width:400px;margin:0px auto">${body}</div>
</div></body></html>`;
    const w = window.open('', '_blank', 'width=420,height=640');
    if (!w) return;
    w.document.open();
    w.document.write(html);
    w.document.close();
    w.focus();
    whenPrintFontsReady(w, () => { try { w.print(); } catch (e) {} });
  } catch (e) {
    if (onError) onError(e);
  }
}
