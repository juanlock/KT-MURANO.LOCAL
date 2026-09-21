/**
 * Renders a barcode label to a 1-bit raster at the printer's native 203 dpi,
 * ready to be sent as a TSPL BITMAP (see App\Services\TsplRasterService).
 *
 * This is the "exact preview" render mode: the layout below mirrors the
 * single-sticker preview in print_label.css (.barcode_custom) — name centred
 * on top, barcode filling the space between, price bottom-right, uppercase —
 * so the printed label matches what the user designed on screen, including
 * the chosen font family and any non-Latin characters. The alternative
 * (native) mode draws with the printer's own bitmap fonts, which are ASCII
 * only and ignore the font family.
 */
import JsBarcode from 'jsbarcode';

/**
 * Anti-aliased text leaves grey edge pixels; anything darker than this becomes
 * a printed dot. Raise it for bolder (thicker) text, lower it for finer text.
 */
const BLACK_THRESHOLD = 160;

/** Largest module (narrow bar) width in dots to try, then 3, 2, 1 until it fits. */
const MAX_BARCODE_MODULE = 4;

/**
 * Dot conversions for the printhead resolution (design.printer_dpi, 203 or
 * 300). Rendering at 203 for a 300 dpi head prints at two-thirds scale.
 */
function dotScale(design) {
  const dpi = Number(design.printer_dpi) === 300 ? 300 : 203;
  const dotsPerMm = dpi === 300 ? 12 : 8;
  return {
    mm: (value) => Math.round(value * dotsPerMm),
    px: (value) => value * (dpi / 96),
  };
}

/**
 * @param {object} label  {name, barcode, Type_barcode, Net_price, qte}
 * @param {object} design {width_mm, height_mm, show_name, show_price,
 *                        show_barcode, show_barcode_number, barcode_height,
 *                        font_size, number_font_size, bold_font, font_family,
 *                        currency}
 * @returns {{data: string, width_bytes: number, height: number, qte: number}}
 */
export function renderLabelRaster(label, design) {
  const { mm, px } = dotScale(design);
  const width = mm(clamp(design.width_mm || 50, 20, 200));
  const height = mm(clamp(design.height_mm || 30, 10, 200));

  const canvas = document.createElement('canvas');
  canvas.width = width;
  canvas.height = height;
  const ctx = canvas.getContext('2d', { willReadFrequently: true });
  ctx.fillStyle = '#fff';
  ctx.fillRect(0, 0, width, height);
  ctx.fillStyle = '#000';
  ctx.textBaseline = 'top';

  // User position adjustment, baked into the bitmap itself — clone printers
  // ignore the TSPL REFERENCE/SHIFT commands, so shifting pixels is the only
  // approach that works everywhere. Applied after the background fill so the
  // whole design (frame included) moves together.
  const clampOffset = (v) => Math.max(-10, Math.min(10, Number(v) || 0));
  const offY = mm(clampOffset(design.offset_y_mm));
  ctx.translate(mm(clampOffset(design.offset_x_mm)), offY);

  // A positive vertical offset means printing starts that far before the
  // sticker, so the sticker's last rows are beyond the print window — lay
  // the design out within the height that actually reaches the sticker.
  const layoutHeight = height - Math.max(0, offY);

  // Alignment frame for test prints (never set on product labels): a border
  // at the exact label edges makes any offset or size mismatch measurable
  // from a single photo.
  // Inset 1 mm — at the extreme edges the frame would land on the liner of
  // die-cut stickers and stay invisible.
  if (design.test_frame) {
    const inset = mm(1);
    ctx.lineWidth = 2;
    ctx.strokeStyle = '#000';
    ctx.strokeRect(inset, inset, width - 2 * inset, layoutHeight - 2 * inset);
  }

  const family = design.font_family || 'Arial, Helvetica, sans-serif';
  const weight = design.bold_font ? 'bold' : 'normal';
  const fontPx = Math.max(6, Number(design.font_size) || 10);
  const fontDots = Math.round(px(fontPx));
  const lineDots = Math.round(px(Math.round(fontPx * 1.25)));
  // 2.5 mm side margin for text (the CSS preview uses 1 mm): real stickers
  // sit 1-2 mm off the print span, so a name wrapped to the full width gets
  // its first/last characters shaved. Matches native mode's 3 mm margin.
  const sideMargin = mm(2.5);

  let top = mm(1);                 // .barcode-item padding-top: 1mm
  let bottom = layoutHeight;

  // Non-ASCII currency symbols (د.إ, ₹…) render as thin anti-aliased strokes
  // that the 1-bit threshold wipes out — the price then prints bare even
  // though the on-screen preview shows the symbol. Print the ASCII code
  // ("AED") instead, like dedicated label software does.
  let currency = String(design.currency || '');
  if (/[^\x20-\x7E]/.test(currency)) {
    currency = String(design.currency_ascii || '');
  }
  const price = design.show_price !== false
    ? upper(`${currency} ${label.Net_price ?? ''}`.trim())
    : '';

  // ---- Product name: centred, as many lines as the space allows (up to 3)
  // after reserving the price line and a minimum-height barcode. A fixed
  // 2-line clamp truncated longer product names even with room to spare.
  if (design.show_name !== false) {
    const priceReserve = price !== '' ? mm(0.5) + fontDots : 0;
    const numberDots = Math.round(px(Math.max(6, Number(design.number_font_size) || 10)));
    const barcodeReserve = design.show_barcode !== false && label.barcode
      ? 24 + (design.show_barcode_number !== false ? numberDots + 4 : 0)
      : 0;
    const maxLines = Math.max(1, Math.min(3,
      Math.floor((layoutHeight - top - priceReserve - barcodeReserve) / lineDots)));

    ctx.font = `${weight} ${fontDots}px ${family}`;
    const lines = wrapLines(ctx, upper(label.name || ''), width - 2 * sideMargin, maxLines);
    ctx.textAlign = 'center';
    lines.forEach((line) => {
      ctx.fillText(line, width / 2, top, width - 2 * sideMargin);
      top += lineDots;
    });
  }

  // ---- Price: bottom-right. Inset 3 mm from the side — die-cut stickers
  // have rounded corners, and less inset puts the last character inside the
  // corner radius where there is no paper. ----
  if (price !== '') {
    ctx.font = `${weight} ${fontDots}px ${family}`;
    ctx.textAlign = 'right';
    bottom -= mm(0.5) + fontDots;
    // Right edge aligned with the barcode's right edge (10% inset): scales
    // with the label and keeps the price well clear of the sticker edge and
    // corner radius on any roll.
    const priceInset = Math.max(mm(3), Math.round(width * 0.1));
    ctx.fillText(price, width - priceInset, bottom, width - 2 * sideMargin);
  }

  // ---- Barcode: right below the name (vertically centering it in the
  // leftover space, like the on-screen flexbox does, reads as a large gap on
  // paper). Height is capped at 45% of the label so an oversized
  // barcode-height design value cannot swallow the sticker. ----
  if (design.show_barcode !== false && label.barcode) {
    const area = { top, height: Math.max(0, bottom - top) };
    const maxBarHeight = Math.round(layoutHeight * 0.45);
    // Target ~80% of the label width (the proportion dedicated label software
    // uses): the module-width search would otherwise let short codes balloon
    // to edge-to-edge bars. Longer codes may still exceed this at the
    // smallest module width and are centered/clipped as before.
    const bc = renderBarcode(label, design, Math.round(width * 0.8), area.height, px, maxBarHeight);
    if (bc) {
      ctx.drawImage(
        bc,
        Math.round((width - bc.width) / 2),
        Math.round(area.top + 4)
      );
    }
  }

  return {
    ...packMonochrome(ctx, width, height),
    qte: Math.max(1, Math.min(1000, Number(label.qte) || 1)),
  };
}

/**
 * Barcode drawn by JsBarcode — the same library the on-screen preview uses.
 * The module width is picked as wide as will fit (bolder bars scan better and
 * fill the label the way vendor label software does), shrinking only when the
 * code would otherwise overflow.
 */
function renderBarcode(label, design, maxWidth, maxHeight, px, maxBarHeight) {
  const barHeight = Math.round(px(clamp(design.barcode_height || 28, 10, 100)));
  const numberDots = Math.round(px(Math.max(6, Number(design.number_font_size) || 10)));
  const showNumber = design.show_barcode_number !== false;
  // Keep the bars inside the area once the human-readable line is allowed
  // for, and inside the proportional cap.
  const height = Math.max(24, Math.min(
    barHeight,
    maxBarHeight || barHeight,
    maxHeight - (showNumber ? numberDots + 4 : 0)
  ));
  if (height <= 0 || maxWidth <= 0) return null;

  const options = (format, moduleWidth) => ({
    format,
    width: moduleWidth,
    height,
    displayValue: showNumber,
    fontSize: numberDots,
    font: design.font_family || 'monospace',
    fontOptions: design.bold_font ? 'bold' : '',
    textMargin: 0,
    margin: 0,
  });

  const format = String(label.Type_barcode || 'CODE128').toUpperCase();
  let best = null;
  for (let moduleWidth = MAX_BARCODE_MODULE; moduleWidth >= 1; moduleWidth--) {
    const canvas = document.createElement('canvas');
    try {
      JsBarcode(canvas, String(label.barcode), options(format, moduleWidth));
    } catch (e) {
      // Value doesn't fit the chosen symbology (an 8-digit code with EAN13
      // selected, lowercase in CODE39…). CODE128 accepts anything, so the
      // label still carries a scannable barcode instead of coming out blank.
      try {
        JsBarcode(canvas, String(label.barcode), options('CODE128', moduleWidth));
      } catch (e2) {
        return null;
      }
    }
    best = canvas;
    if (canvas.width <= maxWidth) return canvas;
  }
  return best; // Even 1-dot modules overflow: centred and clipped by the label.
}

/** Greedy word wrap, then hard-break anything still too wide for one line. */
function wrapLines(ctx, text, maxWidth, maxLines) {
  const clean = String(text).replace(/\s+/g, ' ').trim();
  if (clean === '') return [];

  const lines = [];
  let current = '';
  for (const word of clean.split(' ')) {
    const candidate = current === '' ? word : `${current} ${word}`;
    if (ctx.measureText(candidate).width <= maxWidth || current === '') {
      current = candidate;
    } else {
      lines.push(current);
      current = word;
      if (lines.length === maxLines) break;
    }
  }
  if (lines.length < maxLines && current !== '') lines.push(current);

  // A single word wider than the label still has to be cut somewhere.
  return lines.slice(0, maxLines).map((line) => truncate(ctx, line, maxWidth));
}

function truncate(ctx, line, maxWidth) {
  if (ctx.measureText(line).width <= maxWidth) return line;
  let cut = line;
  while (cut.length > 1 && ctx.measureText(`${cut}.`).width > maxWidth) {
    cut = cut.slice(0, -1);
  }
  return `${cut}.`;
}

/**
 * Pack the canvas into TSPL BITMAP rows: one bit per dot, MSB leftmost, and
 * a CLEARED bit prints a dot — so white is 1 and black is 0.
 */
function packMonochrome(ctx, width, height) {
  const pixels = ctx.getImageData(0, 0, width, height).data;
  const widthBytes = Math.ceil(width / 8);
  const bytes = new Uint8Array(widthBytes * height).fill(0xff);

  for (let y = 0; y < height; y++) {
    for (let x = 0; x < width; x++) {
      const i = (y * width + x) * 4;
      // Canvas is drawn pure black on white, so the red channel is enough
      // once transparency (fully transparent = white paper) is accounted for.
      const luminance = pixels[i + 3] === 0 ? 255 : pixels[i];
      if (luminance < BLACK_THRESHOLD) {
        bytes[y * widthBytes + (x >> 3)] &= ~(0x80 >> (x & 7));
      }
    }
  }

  return { data: toBase64(bytes), width_bytes: widthBytes, height };
}

function toBase64(bytes) {
  let binary = '';
  const CHUNK = 0x8000; // avoid blowing the argument limit on large labels
  for (let i = 0; i < bytes.length; i += CHUNK) {
    binary += String.fromCharCode.apply(null, bytes.subarray(i, i + CHUNK));
  }
  return btoa(binary);
}

/** .barcode_custom .barcode-item sets text-transform: uppercase. */
function upper(value) {
  return String(value ?? '').toUpperCase();
}

function clamp(value, min, max) {
  return Math.max(min, Math.min(max, Number(value) || min));
}
