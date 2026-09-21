/**
 * Report exports — Excel and PDF, matching what the legacy report pages offer.
 * Both libraries are heavy and only reports need them, so they're imported
 * dynamically: the cost lands in a chunk fetched on the first export click,
 * not in the page bundle.
 *
 * `columns` are DataTable columns: { title, dataIndex|key, exportValue? }.
 * Provide `exportValue(record)` on a column whose cell is rendered with a slot
 * (tags, images, …) so the export gets real text instead of "[object Object]".
 */

import dayjs from 'dayjs';
import { useAuthStore } from '../stores/auth';

/**
 * Global export defaults (System Settings → Export tab), stored in
 * settings.export_settings and served with get_user_auth. Read at call time —
 * the store getter merges the built-in defaults for missing keys.
 */
export function exportPrefs() {
    try {
        return useAuthStore().exportSettings;
    } catch (e) {
        // Pinia unavailable (tests, early calls) — historical behavior.
        return { scope: 'all', totals: true, pdf_orientation: 'landscape', filename_date: false, pdf_meta: false };
    }
}

function fileStamp(prefs) {
    return prefs.filename_date ? `_${dayjs().format('YYYY-MM-DD')}` : '';
}

function headerOf(col) {
    return String(col.title ?? col.key ?? '');
}

function valueOf(col, row) {
    if (typeof col.exportValue === 'function') return col.exportValue(row);
    const key = col.dataIndex || col.key;
    const v = key ? row[key] : '';
    if (v === null || v === undefined) return '';
    return typeof v === 'object' ? JSON.stringify(v) : v;
}

/** Columns that can't produce text (action buttons, images) are skipped. */
export function exportable(columns) {
    return columns.filter(c => c.key !== 'actions' && c.key !== 'image' && c.exportable !== false);
}

/**
 * Totals footer over the EXPORTED rows (the full set, not the page on screen),
 * built from the same `sum: true | 'money'` column flags DataTable's footer
 * uses. `formatSum(col, total)` supplies the app's money/number formatting
 * (exporters stay store-free). Returns null when no column is flagged.
 */
export function totalsRow(columns, rows, formatSum, label = 'Total') {
    const cols = exportable(columns);
    if (!cols.some(c => c.sum)) return null;
    return cols.map((c, i) => {
        if (c.sum) {
            const key = c.dataIndex ?? c.key;
            const total = rows.reduce((acc, r) => acc + (Number(r?.[key]) || 0), 0);
            return formatSum(c, total);
        }
        return i === 0 ? label : '';
    });
}

/**
 * Arabic, Hebrew, Persian, Urdu … — anything needing shaping or bidi.
 * jsPDF's built-in fonts are cp1252 (no glyphs at all for these) and
 * jspdf-autotable calls doc.text() with no RTL option, so such content cannot
 * be drawn correctly by the jsPDF path at any price. Detected content is
 * routed through the browser's print engine instead, which shapes and reorders
 * it natively — including mixed Arabic/Latin/number cells.
 */
const RTL_RE = /[֐-׿؀-ۿ܀-ݏݐ-ݿࢠ-ࣿיִ-﷿ﹰ-﻿]/;

function containsRtl(title, columns, rows) {
    if (RTL_RE.test(String(title ?? ''))) return true;
    const cols = exportable(columns);
    if (cols.some(c => RTL_RE.test(headerOf(c)))) return true;
    return rows.some(r => cols.some(c => RTL_RE.test(String(valueOf(c, r) ?? ''))));
}

/** The print markup shared by printRows() and the RTL PDF path. */
function buildPrintHtml(title, columns, rows, { subtitle = [], landscape = true, foot = null, rtl = false } = {}) {
    const cols = exportable(columns);
    const esc = s => String(s ?? '').replace(/[&<>"]/g, c => (
        { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]
    ));
    const styles = Array.from(document.querySelectorAll('link[rel="stylesheet"]'))
        .map(l => l.outerHTML).join('\n');

    return `<!doctype html><html${rtl ? ' dir="rtl"' : ''}><head>
    <base href="${window.location.origin}/" />
    <title>${esc(title)}</title>
    ${styles}
    <style>
      @page { size: A4 ${landscape ? 'landscape' : 'portrait'}; margin: 0.3cm; }
      @media print { body, body * { visibility: visible !important; } }
      body { margin: 0.3cm; font-family: Arial, sans-serif; }
      .print-header { font-weight: 600; margin-bottom: 6px; font-size: 14px; }
      .print-sub { font-size: 11px; line-height: 1.5; margin-bottom: 10px; }
      table { width: 100%; border-collapse: collapse; }
      /* Cells may carry newlines (a variant product stacks one value per line);
         without pre-line the browser would collapse them onto one row. */
      th, td { border: 1px solid #ddd; padding: 6px 8px; font-size: 10px; text-align: ${rtl ? 'right' : 'left'}; white-space: pre-line; }
      th { background: #f5f5f5; font-weight: bold; }
      tr:nth-child(even) { background: #f9f9f9; }
    </style></head><body>
    <div class="print-header">${esc(title)}</div>
    ${subtitle.length ? `<div class="print-sub">${subtitle.map(esc).join(' &nbsp;·&nbsp; ')}</div>` : ''}
    <table>
      <thead><tr>${cols.map(c => `<th>${esc(headerOf(c))}</th>`).join('')}</tr></thead>
      <tbody>${rows.map(r => `<tr>${cols.map(c => `<td>${esc(valueOf(c, r))}</td>`).join('')}</tr>`).join('')}</tbody>
      ${foot ? `<tfoot><tr>${foot.map(v => `<td style="font-weight:bold;background:#f0f0f0">${esc(v)}</td>`).join('')}</tr></tfoot>` : ''}
    </table></body></html>`;
}

/**
 * Arabic-capable PDF face. jsPDF's built-in fonts are cp1252 and have no glyphs
 * for Arabic/Persian/Urdu/Hebrew, so text in those scripts renders as garbage
 * without an embedded TTF. Shaping itself needs no work here — jsPDF runs
 * processArabic on a "preProcessText" hook, so the contextual letter forms are
 * applied automatically once the face is present.
 *
 * addFont() is given a URL: when the file isn't already in the virtual FS jsPDF
 * fetches it itself at render time, so there is nothing to preload.
 */
const PDF_FONT = 'Vazirmatn';
const PDF_FONT_URL = '/fonts/Vazirmatn-Bold.ttf';
const RTL_LOCALES = ['ar', 'fa', 'ur', 'he'];

function usePdfFont(doc) {
    try {
        // Only the Bold weight ships with the app, so both styles map to it.
        doc.addFont(PDF_FONT_URL, PDF_FONT, 'normal');
        doc.addFont(PDF_FONT_URL, PDF_FONT, 'bold');
        doc.setFont(PDF_FONT, 'normal');
        return PDF_FONT;
    } catch (e) {
        // Font missing/unreachable — fall back rather than lose the export.
        return 'helvetica';
    }
}

/** RTL layout follows the admin's language, as the legacy export did. */
function isRtlUi() {
    if (typeof document === 'undefined') return false;
    if (document.documentElement.dir === 'rtl') return true;
    return RTL_LOCALES.includes(String(document.documentElement.lang || '').slice(0, 2));
}

export async function exportExcel(filename, columns, rows, { foot = null } = {}) {
    const XLSX = await import('xlsx');
    const cols = exportable(columns);
    const data = [
        cols.map(headerOf),
        ...rows.map(r => cols.map(c => valueOf(c, r))),
        ...(foot ? [foot] : []),
    ];
    const ws = XLSX.utils.aoa_to_sheet(data);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Report');
    XLSX.writeFile(wb, `${filename}${fileStamp(exportPrefs())}.xlsx`);
}

export async function exportPdf(title, columns, rows, { landscape = null, foot = null } = {}) {
    const [{ jsPDF }, autoTableMod] = await Promise.all([
        import('jspdf'),
        import('jspdf-autotable'),
    ]);
    const autoTable = autoTableMod.default || autoTableMod.autoTable;
    const cols = exportable(columns);
    const prefs = exportPrefs();
    // Explicit caller option wins; otherwise the admin's global default.
    const isLandscape = landscape ?? prefs.pdf_orientation !== 'portrait';

    const doc = new jsPDF({ orientation: isLandscape ? 'landscape' : 'portrait' });
    // Must precede any text() call so the title uses it too.
    const font = usePdfFont(doc);
    const rtl = isRtlUi();
    // In an RTL UI the heading hangs off the right margin instead of the left.
    const headX = rtl ? doc.internal.pageSize.getWidth() - 14 : 14;
    const headAlign = rtl ? { align: 'right' } : undefined;

    doc.setFontSize(14);
    doc.text(String(title), headX, 15, headAlign);

    // Optional "Company — generated YYYY-MM-DD HH:mm" line under the title.
    let startY = 22;
    if (prefs.pdf_meta) {
        let company = '';
        try { company = useAuthStore().user?.company || ''; } catch (e) { /* store unavailable */ }
        doc.setFontSize(9);
        doc.setTextColor(120);
        doc.text(`${company ? company + ' — ' : ''}${dayjs().format('YYYY-MM-DD HH:mm')}`, headX, 21, headAlign);
        doc.setTextColor(0);
        startY = 26;
    }

    autoTable(doc, {
        head: [cols.map(headerOf)],
        body: rows.map(r => cols.map(c => String(valueOf(c, r)))),
        startY,
        // autoTable would otherwise fall back to helvetica per cell and lose the
        // Arabic glyphs, whatever font the doc itself is set to.
        styles: { fontSize: 8, font, halign: rtl ? 'right' : 'left' },
        headStyles: { fillColor: [109, 40, 217], font, fontStyle: 'bold' }, // brand purple
        ...(foot ? {
            foot: [foot.map(v => String(v))],
            footStyles: { fillColor: [240, 240, 240], textColor: 20, fontStyle: 'bold', font },
            showFoot: 'lastPage', // grand total once, at the end
        } : {}),
    });

    doc.save(`${String(title).replace(/\s+/g, '_')}${fileStamp(prefs)}.pdf`);
}

/**
 * Browser print of a table — the legacy report pages' `printTableOnly`, once
 * instead of copy-pasted into every page. Same column contract as the
 * exporters, so `exportValue` decides what a slot-rendered cell prints.
 *
 * `subtitle` lines render under the heading (legacy printed batch/product meta
 * there). Values are HTML-escaped, which legacy did not do.
 */
export function printRows(title, columns, rows, { subtitle = [], landscape = true, win = null, foot = null } = {}) {
    // Callers that fetch rows first should open the window synchronously (inside
    // the click handler) and pass it here, so pop-up blockers don't kill it.
    const w = win || window.open('', '_blank');
    if (!w) {
        window.alert('Please allow popups to print');
        return;
    }
    // RTL content needs dir="rtl" here too, not just on the PDF path.
    const rtl = containsRtl(title, columns, rows);

    w.document.open();
    w.document.write(buildPrintHtml(title, columns, rows, { subtitle, landscape, foot, rtl }));
    w.document.close();
    w.focus();
    // 400 ms lets the cloned stylesheets load before the dialog opens.
    setTimeout(() => { w.print(); w.close(); }, 400);
}
