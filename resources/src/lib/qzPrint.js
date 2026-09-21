/**
 * Silent label printing through QZ Tray (https://qz.io) — a small free agent
 * the user installs on their own computer. The browser hands raw TSPL bytes
 * to it over a local websocket, so labels print to a USB/local printer on the
 * CLIENT machine even when the app is hosted on a remote server (where the
 * server-side transports in LabelPrinterTransport can never reach it).
 *
 * Requests are signed via the server (GET qz/certificate + POST qz/sign,
 * self-signed cert generated in storage/app/qz). QZ Tray refuses to remember
 * the Allow decision for unsigned sites, so signing is what makes silent
 * printing possible: the user downloads the certificate from the settings
 * page, installs it in QZ Tray as override.crt, and no prompt appears again.
 * If the server can't sign (no openssl), everything falls back to unsigned —
 * printing still works but QZ asks for permission once per session.
 */
import qz from 'qz-tray';
import http from './http';

let configured = false;

function configure() {
  if (configured) return;
  configured = true;
  qz.security.setSignatureAlgorithm('SHA512');
  // Resolving null on any failure = unsigned fallback (per-session prompt).
  qz.security.setCertificatePromise((resolve) => {
    http.get('qz/certificate')
      .then((res) => resolve(res && res.certificate ? res.certificate : null))
      .catch(() => resolve(null));
  });
  qz.security.setSignaturePromise((toSign) => (resolve) => {
    http.post('qz/sign', { request: toSign })
      .then((res) => resolve(res && res.signature ? res.signature : null))
      .catch(() => resolve(null));
  });
}

async function ensureConnected() {
  configure();
  if (!qz.websocket.isActive()) {
    await qz.websocket.connect({ retries: 1, delay: 1 });
  }
}

/**
 * QZ Tray always runs on the same machine as the browser (the connection is a
 * localhost websocket), so the user agent identifies the OS QZ is printing on.
 */
function isWindowsClient() {
  const platform = (navigator.userAgentData && navigator.userAgentData.platform)
    || navigator.platform || navigator.userAgent || '';
  return /win/i.test(platform);
}

/** True when the error means QZ Tray isn't installed/running locally. */
export function isQzConnectError(e) {
  const msg = (e && (e.message || String(e))) || '';
  return /establish connection|unable to connect|connection refused|websocket/i.test(msg);
}

/** @returns {Promise<string[]>} printer names known to the local QZ Tray */
export async function qzListPrinters() {
  await ensureConnected();
  const found = await qz.printers.find();
  return (Array.isArray(found) ? found : [found]).filter(Boolean);
}

/**
 * Print raw TSPL to a printer on this computer. The payload arrives base64
 * encoded because a raster label is binary and could not survive JSON; QZ
 * decodes it back to bytes. Empty name → the computer's default printer.
 * Resolves with the printer name actually used.
 */
export async function qzPrintRaw(printerName, base64Payload) {
  await ensureConnected();
  const printer = (printerName || '').trim() || (await qz.printers.getDefault());

  // forceRaw sends the bytes straight to the device, skipping the OS print
  // driver. Without it on macOS/Linux, CUPS runs the queue's filter chain and
  // the printer prints the TSPL commands as readable text (TEXT/BARCODE lines,
  // wrong feed) instead of executing them. It is not supported on Windows —
  // and not needed there, since QZ already spools RAW through winspool.
  // qz-tray renames it to altPrinting automatically for QZ Tray < 2.2.
  const config = qz.configs.create(printer, { forceRaw: !isWindowsClient() });

  await qz.print(config, [{
    type: 'raw', format: 'command', flavor: 'base64', data: base64Payload,
  }]);
  return printer;
}
