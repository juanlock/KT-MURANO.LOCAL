/**
 * Hospital printouts (user's analysis, 2026-10-25), opened in their own window
 * and sent to the printer:
 *  - the visitor pass: visitor, whom they visit, room / bed / floor, badge;
 *  - the patient wristband: name, record number as a barcode, birth date,
 *    blood group, ALLERGIES, where the patient is.
 * s = the page's translator (useHospitalText).
 */
import JsBarcode from 'jsbarcode';
import dayjs from 'dayjs';

const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

function barcodeSvg(value) {
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    try {
        JsBarcode(svg, String(value), { format: 'CODE128', displayValue: true, fontSize: 14, height: 46, margin: 0, width: 1.6 });
    } catch (e) {
        return '';
    }
    return new XMLSerializer().serializeToString(svg);
}

function openAndPrint(title, css, body, printLabel = 'Print') {
    const w = window.open('', '_blank', 'width=720,height=640');
    if (!w) return false;
    w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>${esc(title)}</title><style>
        * { box-sizing: border-box; } body { margin: 0; font-family: system-ui, -apple-system, 'Segoe UI', Roboto, Arial, sans-serif; color: #0f172a; }
        ${css}
        @media print { .noprint { display: none !important; } }
        .noprint { margin: 16px; } .noprint button { font: inherit; padding: 8px 16px; border-radius: 8px; border: 1px solid #94a3b8; background: #fff; cursor: pointer; }
    </style></head><body>${body}
    <div class="noprint"><button onclick="window.print()">${esc(printLabel)}</button></div>
    <script>window.onload = function () { setTimeout(function () { window.print(); }, 250); };<\/script></body></html>`);
    w.document.close();
    return true;
}

const where = (d, s) => [d.floor && `${s('Floor')}: ${d.floor}`, d.room && `${s('Room')}: ${d.room}`, d.bed && `${s('Bed')}: ${d.bed}`, d.ward && !d.room ? `${s('Ward')}: ${d.ward}` : null]
    .filter(Boolean);

/** A visitor pass the size of a badge (85 x 55 mm, fits a lanyard pouch). */
export function printVisitorPass(d, s) {
    const loc = where(d, s);
    const css = `
        .pass { width: 85mm; min-height: 55mm; margin: 10mm; border: 1.5px solid #0f172a; border-radius: 4mm; overflow: hidden; }
        .top { background: #0b4fa0; color: #fff; padding: 2.5mm 4mm; display: flex; align-items: center; gap: 3mm; }
        .top img { height: 8mm; max-width: 20mm; object-fit: contain; background: #fff; border-radius: 1mm; padding: 0.5mm; }
        .top b { font-size: 11pt; flex: 1; } .top span { font-size: 9pt; font-weight: 700; letter-spacing: .06em; }
        .in { padding: 3mm 4mm; }
        .who { font-size: 15pt; font-weight: 800; line-height: 1.1; }
        .sub { font-size: 8.5pt; color: #475569; margin-top: 1mm; }
        .visits { margin-top: 2.5mm; font-size: 9pt; color: #475569; }
        .pat { font-size: 12pt; font-weight: 700; }
        .loc { margin-top: 2mm; display: flex; flex-wrap: wrap; gap: 1.5mm; }
        .loc i { font-style: normal; background: #e0ecfb; color: #0b4fa0; border-radius: 1.5mm; padding: 0.8mm 2mm; font-size: 9.5pt; font-weight: 700; }
        .foot { display: flex; justify-content: space-between; align-items: center; border-top: 1px dashed #94a3b8; padding: 2mm 4mm; font-size: 8.5pt; }
        .badge { font-size: 12pt; font-weight: 800; letter-spacing: .04em; }`;
    const body = `<div class="pass">
        <div class="top">${d.logo ? `<img src="${esc(d.logo)}" alt="">` : ''}<b>${esc(d.company)}</b><span>${esc(s('VISITOR'))}</span></div>
        <div class="in">
            <div class="who">${esc(d.visitor)}</div>
            <div class="sub">${esc([d.document, d.relation].filter(Boolean).join(' · '))}</div>
            <div class="visits">${esc(s('Visits'))}</div>
            <div class="pat">${esc(d.patient)}</div>
            <div class="loc">${loc.length ? loc.map(l => `<i>${esc(l)}</i>`).join('') : `<i>${esc(s('Ask at reception'))}</i>`}</div>
        </div>
        <div class="foot"><span>${esc(d.checked_in_at ? dayjs(d.checked_in_at).format('DD/MM/YYYY HH:mm') : '')}</span><span class="badge">${esc(d.badge)}</span></div>
    </div>`;
    return openAndPrint(`${s('Visitor pass')} ${d.badge}`, css, body, s('Print'));
}

/** A patient wristband strip (about 250 x 25 mm): name, record number barcode, allergies in red. */
export function printWristband(d, s) {
    const loc = where(d, s);
    const dob = d.date_of_birth ? dayjs(d.date_of_birth).format('DD/MM/YYYY') : '';
    const css = `
        .band { width: 250mm; height: 28mm; margin: 10mm; border: 1.5px solid #0f172a; border-radius: 14mm; display: flex; align-items: center; gap: 5mm; padding: 0 9mm; overflow: hidden; }
        .id { flex: 1; min-width: 0; }
        .name { font-size: 15pt; font-weight: 800; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .meta { font-size: 9pt; color: #334155; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .alg { flex: none; max-width: 55mm; background: #dc2626; color: #fff; border-radius: 2mm; padding: 1mm 2.5mm; font-size: 8.5pt; font-weight: 800; line-height: 1.15; }
        .bc svg { height: 21mm; width: auto; display: block; }
        .co { flex: none; font-size: 7.5pt; color: #64748b; writing-mode: vertical-rl; transform: rotate(180deg); }`;
    const meta = [dob && `${s('Born')}: ${dob}`, d.gender && s(d.gender[0].toUpperCase() + d.gender.slice(1)), d.blood_group && `${s('Blood')}: ${d.blood_group}`, ...loc].filter(Boolean);
    const body = `<div class="band">
        <div class="co">${esc(d.company)}</div>
        <div class="id"><div class="name">${esc(d.name)}</div><div class="meta">${esc(meta.join(' · '))}</div></div>
        ${d.allergies ? `<div class="alg">⚠ ${esc(s('ALLERGIES'))}: ${esc(d.allergies)}</div>` : ''}
        <div class="bc">${barcodeSvg(d.mrn)}</div>
    </div>`;
    return openAndPrint(`${s('Wristband')} ${d.mrn}`, css, body, s('Print'));
}
