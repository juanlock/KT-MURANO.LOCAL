/**
 * POS receipt HTML builder — string port of the five receipt layouts rendered
 * by PosPage.vue's hidden `#invoice-POS` DOM (Standard / Compact / Detailed /
 * Bilingual / Minimal), selected by `pos_settings.receipt_layout`. Used by
 * pages that print a receipt without mounting the POS (e.g. next/sales), so a
 * layout change in Settings → POS Receipt applies everywhere.
 *
 * Markup and class names MUST stay in sync with PosPage.vue and
 * /css/pos_print.css — the print popup is styled exclusively by that file.
 *
 * The caller supplies formatters so the receipt follows the user's configured
 * price format/decimals (useFormat's `number`), and pre-rendered QR data URLs
 * (the popup has no Vue refs to mount QR canvases into).
 */

export function normalizeReceiptLayout(raw) {
  const n = Number(raw) || 1;
  return [1, 2, 3, 4, 5].includes(n) ? n : 1;
}

const esc = v => String(v ?? '')
  .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
  .replace(/"/g, '&quot;');

// Grouped quantity/percent formatter (PosPage.formatNumber): always 2 decimals
// in receipt templates, en-US separators regardless of price format.
const qty = (v, d = 2) => {
  const n = Number(v);
  const safe = Number.isFinite(n) ? n : 0;
  return safe.toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d });
};

export function buildPosReceiptHtml(opts) {
  const {
    t,                      // i18n translate fn
    sale: s = {},           // data.sale from sales_print_invoice
    setting = {},           // company/receipt header info
    ps = {},                // pos_settings
    symbol = '',
    details = [],
    payments = [],
    formatPrice,            // (v) => price string per user settings (no symbol)
    qr = {},                // { zatca: dataUrl|null, invoice: dataUrl|null }
  } = opts;

  const layout = normalizeReceiptLayout(ps.receipt_layout);
  const price = v => formatPrice ? formatPrice(v) : qty(v);
  const cash = v => (symbol ? `${symbol} ${price(v)}` : price(v));

  // Two flag conventions, mirroring PosPage exactly: legacy columns rendered
  // with `!== 0` default ON when absent; the rest are plain truthy checks.
  const on = f => Number(ps[f] ?? 1) !== 0;
  const set = f => Number(ps[f] || 0) !== 0;

  // ---- shared derived amounts (PosPage computeds) ----
  const subtotal = details.reduce((sum, d) => sum + (Number(d.total) || 0), 0);
  const itemsTax = details.reduce((sum, d) => sum + (Number(d.taxe) || 0) * (Number(d.quantity) || 0), 0);
  const itemsTaxRate = subtotal - itemsTax > 0 ? (itemsTax / (subtotal - itemsTax)) * 100 : 0;
  const shownSubtotal = on('show_items_tax') ? subtotal - itemsTax : subtotal;
  const isPercentDiscount = String(s.discount_Method || '2') === '1';
  const manualDiscount = isPercentDiscount
    ? subtotal * (Number(s.discount) || 0) / 100
    : Math.min(Number(s.discount) || 0, subtotal);
  const previousDues = Number.isFinite(Number(s.previous_dues)) ? Number(s.previous_dues) : 0;
  const showPrevRow = previousDues > 0 && on('show_previous_dues');
  const showNetRow = previousDues > 0 && on('show_net_balance');
  const netBalance = previousDues + (Number(s.GrandTotal || 0) - Number(s.paid_amount || 0));
  const promotions = (Array.isArray(s.promotions) ? s.promotions : []).filter(p => Number(p && p.amount) > 0);
  const packUnit = d => (d && d.pack_name && Number(d.pack_multiplier) > 1) ? d.pack_name : (d.unit_sale || '');
  const pcs = d => esc(d.unit_sale || t('Pcs') || 'pcs');
  const hasPack = d => d.pack_name && Number(d.pack_multiplier) > 1;
  const lineDiscount = d => on('show_product_discount') && Number(d.DiscountNet || 0) > 0;
  const discountAmt = d => price(Number(d.DiscountNet) * Number(d.quantity));
  const hasImei = d => d.is_imei && d.imei_number != null;

  const logo = on('show_logo') && setting.logo
    ? `<img src="/images/${esc(setting.logo)}" alt width="${Number(ps.logo_size) || 60}" height="${Number(ps.logo_size) || 60}">`
    : '';

  const qrRow = () => {
    const wantZatca = setting.zatca_enabled && qr.zatca && on('show_zatca_qr');
    const wantInvoice = on('show_barcode') && s.Ref && qr.invoice;
    if (!wantZatca && !wantInvoice) return '';
    const block = (title, url) => url
      ? `<div class="receipt-qr-block"><div class="receipt-qr-title">${title}</div>
         <div class="receipt-qr-canvas"><img src="${url}" width="100" height="100" alt=""></div></div>`
      : '';
    return `<div class="receipt-qr-row mt-2">${block('ZATCA QR', wantZatca ? qr.zatca : null)}${block('Invoice QR', wantInvoice ? qr.invoice : null)}</div>`;
  };

  const saleNote = () => s.notes
    ? `<p style="font-size:9px;font-style:italic;padding-bottom:4px;white-space:pre-line;margin:0;">${t('sale_note')}: ${esc(s.notes)}</p>`
    : '';
  const customerNote = () => set('show_note') && ps.note_customer
    ? `<p class="legal" style="white-space:pre-line;"><strong>${esc(ps.note_customer)}</strong></p>`
    : '';

  // Payment rows share structure; only classes/formatters differ per layout.
  const paymentsVisible = on('show_payments') && Number(s.paid_amount) > 0 && payments.length;
  const classicPayments = (tableClass, amountFmt) => paymentsVisible
    ? `<table class="${tableClass}" style="font-size:10px;width:100%;">
        <thead><tr style="background:#eee;">
          <th style="text-align:left;" colspan="1">${t('PayeBy')}:</th>
          <th style="text-align:center;" colspan="2">${t('Amount')}:</th>
          <th style="text-align:right;" colspan="1">${t('Change')}:</th>
        </tr></thead><tbody>${payments.map(p =>
          `<tr><td style="text-align:left;" colspan="1">${p.payment_method ? esc(p.payment_method.name) : '---'}</td>
           <td style="text-align:center;" colspan="2">${amountFmt(p.montant)}</td>
           <td style="text-align:right;" colspan="1">${amountFmt(p.change)}</td></tr>` +
          (p.notes ? `<tr><td colspan="4" style="font-size:9px;font-style:italic;padding-bottom:4px;white-space:pre-line;">${t('Payment_note')}: ${esc(p.notes)}</td></tr>` : '')
        ).join('')}</tbody></table>`
    : '';

  // ---- classic totals rows (layouts 1-3; layout 1 uses colspan=3 labels) ----
  const totalsRows = colspan => {
    const row = (label, value) =>
      `<tr><td colspan="${colspan}" class="total">${label}</td><td style="text-align:right;" class="total">${value}</td></tr>`;
    return [
      row(t('pos.Subtotal'), cash(shownSubtotal)),
      on('show_items_tax') && itemsTax > 0
        ? row(t('TotalProductTax'), `${cash(itemsTax)} (${qty(itemsTaxRate)} %)`) : '',
      set('show_tax')
        ? row(t('OrderTax'), `${cash(s.taxe)} (${qty(s.tax_rate)} %)`) : '',
      set('show_discount')
        ? row(t('Discount'), isPercentDiscount
            ? `${qty(s.discount)}% (${cash(manualDiscount)})`
            : cash(manualDiscount)) : '',
      set('show_discount') && Number(s.discount_from_points || 0) > 0
        ? row(t('Discount_from_Points'), cash(s.discount_from_points)) : '',
      ...(promotions.length
        ? promotions.map(p => row(
            `${t('Promotions')} — ${esc(p.name || '')}${p.code ? ` (${esc(p.code)})` : ''}`,
            `−${cash(p.amount)}`))
        : (Number(s.promotion_discount || 0) > 0
            ? [row(`${t('Promotions')}${s.promotion_code ? ` (${esc(s.promotion_code)})` : ''}`, `−${cash(s.promotion_discount)}`)]
            : [])),
      set('show_shipping') ? row(t('Shipping'), cash(s.shipping)) : '',
      row(t('Total'), cash(s.GrandTotal)),
      on('show_paid') ? row(t('Paid'), cash(s.paid_amount)) : '',
      on('show_due') ? row(t('Due'), cash(Number(s.GrandTotal) - Number(s.paid_amount))) : '',
      showPrevRow ? row(t('Previous_Dues'), cash(previousDues)) : '',
      showNetRow ? row(t('Net_Balance'), cash(netBalance)) : '',
    ].filter(Boolean).join('');
  };

  const legalcopy = extraClass =>
    `<div id="legalcopy" class="${extraClass}">${saleNote()}${customerNote()}${qrRow()}</div>`;

  // ------------------------- Layout 1 — Standard -------------------------
  const layout1 = () => {
    const header = [
      on('show_store_name') && setting.CompanyName ? `<strong>${esc(setting.CompanyName)}</strong>` : '',
      on('show_reference') && s.Ref ? `${t('Reference')} : ${esc(s.Ref)}` : '',
      on('show_date') ? `${t('date')} : ${esc(s.date)}` : '',
      on('show_seller') ? `${t('Seller')} : ${esc(s.seller_name)}` : '',
      set('show_address') ? `${t('Adress')} : ${esc(setting.CompanyAdress)}` : '',
      set('show_email') ? `${t('Email')} : ${esc(setting.email)}` : '',
      set('show_phone') ? `${t('Phone')} : ${esc(setting.CompanyPhone)}` : '',
      set('show_customer') ? `${t('Customer')} : ${esc(s.client_name)}` : '',
      set('show_Warehouse') ? `${t('warehouse')} : ${esc(s.warehouse_name)}` : '',
    ].filter(Boolean).join('<br>');

    const line = d => {
      const q = Number(d.quantity) || 1;
      return `<tr><td colspan="3">${esc(d.name)}${hasImei(d) ? `<br><span>${t('IMEI_SN')} : ${esc(d.imei_number)}</span>` : ''}<br>
        <span>${qty(q)} ${esc(packUnit(d))} x ${price(Number(d.total) / q)}</span>
        ${hasPack(d) ? `<br><small style="color:#666;">(×${esc(d.pack_multiplier)}) = ${qty(q * Number(d.pack_multiplier))} ${pcs(d)}</small>` : ''}
        ${lineDiscount(d) ? `<br><small style="color:#888;font-style:italic;">${t('Discount')}: -${discountAmt(d)}</small>` : ''}</td>
        <td style="text-align:right;vertical-align:bottom">${price(d.total)}</td></tr>`;
    };

    return `<div class="info">
      ${logo ? `<div class="invoice_logo text-center mb-2">${logo}</div>` : ''}
      <p>${header}</p>
    </div>
    <table class="table_data" style="width:100%;"><tbody>
      ${details.map(line).join('')}
      ${totalsRows(3)}
    </tbody></table>
    ${classicPayments('change mt-3', v => price(v))}
    ${legalcopy('ml-2')}`;
  };

  // ------------------------- Layout 2 — Compact --------------------------
  const layout2 = () => {
    const line = d => {
      const q = Number(d.quantity) || 1;
      return `<tr><td>${esc(d.name)}${hasImei(d) ? `<br><small>${t('IMEI_SN')} : ${esc(d.imei_number)}</small>` : ''}
          ${hasPack(d) ? `<br><small style="color:#666;">(×${esc(d.pack_multiplier)}) = ${qty(q * Number(d.pack_multiplier))} ${pcs(d)}</small>` : ''}</td>
        <td style="text-align:center">${qty(q)} ${esc(packUnit(d))}</td>
        <td style="text-align:right">${qty(Number(d.total) / q)}</td>
        <td style="text-align:right">${qty(d.total)}</td></tr>` +
        (lineDiscount(d)
          ? `<tr><td colspan="4" style="color:#888;font-style:italic;font-size:10px;padding-left:8px;">${t('Discount')}: -${discountAmt(d)}</td></tr>`
          : '');
    };

    return `<div class="info text-center">
      ${logo ? `<div class="invoice_logo mb-1">${logo}</div>` : ''}
      <div>
        ${on('show_store_name') ? `<div>${esc(setting.CompanyName)}</div>` : ''}
        ${set('show_address') ? `<div>${esc(setting.CompanyAdress)}</div>` : ''}
        ${set('show_phone') ? `<div>${esc(setting.CompanyPhone)}</div>` : ''}
        ${set('show_email') ? `<div>${esc(setting.email)}</div>` : ''}
      </div>
      <div class="mt-1">
        ${on('show_reference') && s.Ref ? `<small>${t('Reference')} : ${esc(s.Ref)}</small><br>` : ''}
        ${on('show_date') ? `<small>${t('date')} : ${esc(s.date)}</small><br>` : ''}
        ${on('show_seller') ? `<small>${t('Seller')} : ${esc(s.seller_name)}</small><br>` : ''}
        ${set('show_customer') ? `<small>${t('Customer')} : ${esc(s.client_name)}</small><br>` : ''}
        ${set('show_Warehouse') ? `<small>${t('warehouse')} : ${esc(s.warehouse_name)}</small>` : ''}
      </div>
    </div>
    <table class="table_data mt-2" style="width:100%;font-size:11px;">
      <thead><tr>
        <th style="text-align:left">${t('ProductName')}</th>
        <th style="text-align:center">${t('Quantity')}</th>
        <th style="text-align:right">${t('Price')}</th>
        <th style="text-align:right">${t('Total')}</th>
      </tr></thead>
      <tbody>${details.map(line).join('')}</tbody>
    </table>
    <table class="table_data mt-2" style="width:100%;font-size:11px;"><tbody>${totalsRows(1)}</tbody></table>
    ${classicPayments('change mt-2', v => qty(v))}
    ${legalcopy('ml-2')}`;
  };

  // ------------------------- Layout 3 — Detailed -------------------------
  const layout3 = () => {
    const line = d => {
      const q = Number(d.quantity) || 1;
      return `<tr><td colspan="2"><strong>${esc(d.name)}</strong>${hasImei(d) ? `<br><span>${t('IMEI_SN')} : ${esc(d.imei_number)}</span>` : ''}<br>
          <small>${qty(q)} ${esc(packUnit(d))} x ${price(Number(d.total) / q)}</small>
          ${hasPack(d) ? `<br><small style="color:#666;">(×${esc(d.pack_multiplier)}) = ${qty(q * Number(d.pack_multiplier))} ${pcs(d)}</small>` : ''}
          ${lineDiscount(d) ? `<br><small style="color:#888;font-style:italic;">${t('Discount')}: -${discountAmt(d)}</small>` : ''}</td>
        <td style="text-align:right;vertical-align:bottom">${cash(d.total)}</td></tr>`;
    };

    return `<div class="info mb-2">
      <div class="d-flex justify-content-between">
        <div>
          ${on('show_store_name') ? `<strong>${esc(setting.CompanyName)}</strong><br>` : ''}
          ${set('show_address') ? `<span>${esc(setting.CompanyAdress)}</span><br>` : ''}
          ${set('show_phone') ? `<span>${esc(setting.CompanyPhone)}</span><br>` : ''}
          ${set('show_email') ? `<span>${esc(setting.email)}</span>` : ''}
        </div>
        ${logo ? `<div class="invoice_logo text-center mb-2">${logo}</div>` : ''}
      </div>
      <div class="mt-2" style="font-size:11px;">
        ${on('show_reference') && s.Ref ? `<div>${t('Reference')} : ${esc(s.Ref)}</div>` : ''}
        ${on('show_date') ? `<div>${t('date')} : ${esc(s.date)}</div>` : ''}
        ${on('show_seller') ? `<div>${t('Seller')} : ${esc(s.seller_name)}</div>` : ''}
        ${set('show_customer') ? `<div>${t('Customer')} : ${esc(s.client_name)}</div>` : ''}
        ${set('show_Warehouse') ? `<div>${t('warehouse')} : ${esc(s.warehouse_name)}</div>` : ''}
      </div>
    </div>
    <table class="table_data w-100 mb-2" style="font-size:11px;"><tbody>${details.map(line).join('')}</tbody></table>
    <table class="table_data w-100 mt-2" style="font-size:11px;"><tbody>${totalsRows(1)}</tbody></table>
    ${classicPayments('change mt-3', v => qty(v))}
    ${legalcopy('ml-2')}`;
  };

  // -------------------- Layout 4 — Bilingual (AR + EN) --------------------
  const layout4 = () => {
    const line = d => {
      const q = Number(d.quantity) || 1;
      const vatRate = Number(d.tax_percent || d.tax_rate || 0);
      return `<tr class="bl4-item-row"><td>
          <div class="bl4-item-name">${esc(d.name)}</div>
          ${vatRate > 0 ? `<div class="bl4-item-sub">VAT @ ${qty(vatRate)}% (${price(Number(d.total) * vatRate / 100)})</div>` : ''}
          ${lineDiscount(d) ? `<div class="bl4-item-sub">Discount / خصم: -${discountAmt(d)}</div>` : ''}
          ${hasImei(d) ? `<div class="bl4-item-sub">IMEI/SN الرقم التسلسلي : ${esc(d.imei_number)}</div>` : ''}
          ${hasPack(d) ? `<div class="bl4-item-sub">(×${esc(d.pack_multiplier)}) = ${qty(q * Number(d.pack_multiplier))} ${pcs(d)}</div>` : ''}
        </td>
        <td class="bl4-td-center">${qty(q)} ${esc(packUnit(d))}</td>
        <td class="bl4-td-right">${price(Number(d.total) / q)}</td>
        <td class="bl4-td-right">${price(d.total)}</td></tr>`;
    };

    const t3 = (en, val, ar) =>
      `<tr><td class="bl4-t-en">${en}</td><td class="bl4-t-val">${val}</td><td class="bl4-t-ar">${ar}</td></tr>`;

    const totals = [
      t3('Sub Total', cash(shownSubtotal), 'المجموع الفرعي'),
      on('show_items_tax') && itemsTax > 0 ? t3('Total Items Tax', cash(itemsTax), 'إجمالي ضريبة الأصناف') : '',
      set('show_discount')
        ? t3('Discount', isPercentDiscount
            ? `${qty(s.discount)}% (${cash(manualDiscount)})`
            : cash(manualDiscount), 'الخصم') : '',
      set('show_discount') && Number(s.discount_from_points || 0) > 0
        ? t3('Discount from Points', cash(s.discount_from_points), 'خصم من النقاط') : '',
      ...(promotions.length
        ? promotions.map(p => t3(
            `Promotion — ${esc(p.name || '')}${p.code ? ` (${esc(p.code)})` : ''}`,
            `−${cash(p.amount)}`, 'العروض'))
        : (Number(s.promotion_discount || 0) > 0
            ? [t3(`Promotion${s.promotion_code ? ` (${esc(s.promotion_code)})` : ''}`, `−${cash(s.promotion_discount)}`, 'العروض')]
            : [])),
      set('show_tax') && Number(s.taxe || 0) > 0 ? t3('VAT', cash(s.taxe), 'ضريبة القيمة المضافة') : '',
      set('show_shipping') ? t3('Shipping', cash(s.shipping), 'الشحن') : '',
    ].filter(Boolean).join('');

    const pays = [
      on('show_paid') ? t3('Paid Amount', cash(s.paid_amount), 'المبلغ المدفوع') : '',
      on('show_due') ? t3('Balance Due', cash(Number(s.GrandTotal) - Number(s.paid_amount)), 'المبلغ المتبقي') : '',
      showPrevRow ? t3('Previous Dues', cash(previousDues), 'المستحقات السابقة') : '',
      showNetRow ? t3('Net Balance', cash(netBalance), 'الرصيد الصافي') : '',
    ].filter(Boolean).join('');

    const cols = '<colgroup><col style="width:32%"><col style="width:36%"><col style="width:32%"></colgroup>';

    const paymentsTable = paymentsVisible
      ? `<table class="bl4-payments">
          <thead><tr>
            <th class="bl4-th-left">Paid By<br>طريقة الدفع</th>
            <th class="bl4-th-center">Amount<br>المبلغ</th>
            <th class="bl4-th-right">Change<br>الباقي</th>
          </tr></thead><tbody>${payments.map(p =>
            `<tr><td class="bl4-td-left">${p.payment_method ? esc(p.payment_method.name) : '---'}</td>
             <td class="bl4-td-center">${price(p.montant)}</td>
             <td class="bl4-td-right">${price(p.change)}</td></tr>` +
            (p.notes ? `<tr><td colspan="3" class="bl4-pay-note">${t('Payment_note')} / ملاحظة الدفع: ${esc(p.notes)}</td></tr>` : '')
          ).join('')}</tbody></table>`
      : '';

    return `<div class="receipt-layout-4">
      <div class="info text-center bl4-header">
        ${logo ? `<div class="invoice_logo mb-1">${logo}</div>` : ''}
        ${setting.company_name_ar ? `<div class="bl4-company-ar">${esc(setting.company_name_ar)}</div>` : ''}
        <div class="bl4-company-en">${esc(setting.CompanyName)}</div>
        ${setting.CompanyAdress ? `<div class="bl4-contact">${esc(setting.CompanyAdress)}</div>` : ''}
        ${setting.CompanyPhone ? `<div class="bl4-contact">${esc(setting.CompanyPhone)}</div>` : ''}
        ${setting.email && set('show_email') ? `<div class="bl4-contact">${esc(setting.email)}</div>` : ''}
        ${setting.vat_number ? `<div class="bl4-trn">الرقم الضريبي / TRN : ${esc(setting.vat_number)}</div>` : ''}
        <div class="bl4-title">
          <div class="bl4-title-ar">فاتورة ضريبية مبسطة</div>
          <div class="bl4-title-en">SIMPLIFIED TAX INVOICE</div>
        </div>
      </div>
      <div class="bl4-meta">
        ${on('show_reference') && s.Ref ? `<div class="bl4-meta-row"><span class="bl4-meta-en">Invoice No</span><span class="bl4-meta-val">${esc(s.Ref)}</span><span class="bl4-meta-ar">رقم الفاتورة</span></div>` : ''}
        ${on('show_date') ? `<div class="bl4-meta-row"><span class="bl4-meta-en">Date</span><span class="bl4-meta-val">${esc(s.date)}</span><span class="bl4-meta-ar">التاريخ</span></div>` : ''}
        ${on('show_seller') ? `<div class="bl4-meta-row"><span class="bl4-meta-en">Seller</span><span class="bl4-meta-val">${esc(s.seller_name)}</span><span class="bl4-meta-ar">البائع</span></div>` : ''}
        ${set('show_customer') ? `<div class="bl4-meta-row"><span class="bl4-meta-en">Customer</span><span class="bl4-meta-val">${esc(s.client_name)}</span><span class="bl4-meta-ar">العميل</span></div>` : ''}
        ${set('show_Warehouse') ? `<div class="bl4-meta-row"><span class="bl4-meta-en">Warehouse</span><span class="bl4-meta-val">${esc(s.warehouse_name)}</span><span class="bl4-meta-ar">المستودع</span></div>` : ''}
      </div>
      <table class="bl4-items">
        <colgroup><col style="width:44%"><col style="width:14%"><col style="width:20%"><col style="width:22%"></colgroup>
        <thead><tr>
          <th class="bl4-th-left">Product<br>المنتج</th>
          <th class="bl4-th-center">Qty<br>الكمية</th>
          <th class="bl4-th-right">Rate<br>السعر</th>
          <th class="bl4-th-right">Amount<br>الإجمالي</th>
        </tr></thead>
        <tbody>${details.map(line).join('')}</tbody>
      </table>
      <table class="bl4-totals">${cols}<tbody>${totals}</tbody></table>
      <table class="bl4-grand">${cols}<tbody>${t3('Grand Total', cash(s.GrandTotal), 'المبلغ الإجمالي')}</tbody></table>
      <table class="bl4-pays">${cols}<tbody>${pays}</tbody></table>
      ${paymentsTable}
      <div id="legalcopy" class="bl4-footer">
        ${s.notes ? `<div class="bl4-sale-note">${t('sale_note')} / ملاحظة البيع: ${esc(s.notes)}</div>` : ''}
        <div class="bl4-thanks">
          <div class="bl4-thanks-ar">شكراً لتسوقكم معنا</div>
          <div class="bl4-thanks-en">Thank You For Shopping With Us!</div>
        </div>
        ${set('show_note') && ps.note_customer ? `<div class="bl4-policy">${esc(ps.note_customer)}</div>` : ''}
        ${(setting.CompanyPhone || setting.email)
          ? `<div class="bl4-footer-contact">${setting.CompanyPhone ? `<span>${esc(setting.CompanyPhone)}</span>` : ''}${setting.CompanyPhone && setting.email ? '<span> &middot; </span>' : ''}${setting.email ? `<span>${esc(setting.email)}</span>` : ''}</div>`
          : ''}
        ${qrRow()}
      </div>
    </div>`;
  };

  // ------------------------- Layout 5 — Minimal --------------------------
  const layout5 = () => {
    const line = d => {
      const q = Number(d.quantity) || 1;
      return `<tr><td>
          <div class="minimal-item-name">${esc(d.name)}</div>
          <div class="minimal-item-qty">${qty(q)} ${esc(packUnit(d))} &times; ${price(Number(d.total) / q)}</div>
          ${hasPack(d) ? `<div class="minimal-item-qty">(×${esc(d.pack_multiplier)}) = ${qty(q * Number(d.pack_multiplier))} ${pcs(d)}</div>` : ''}
          ${lineDiscount(d) ? `<div class="minimal-item-discount">${t('Discount')} &minus;${discountAmt(d)}</div>` : ''}
          ${hasImei(d) ? `<div class="minimal-item-qty">IMEI/SN: ${esc(d.imei_number)}</div>` : ''}
        </td>
        <td class="minimal-item-total">${price(d.total)}</td></tr>`;
    };

    const row = (label, value, cls = '') =>
      `<tr${cls ? ` class="${cls}"` : ''}><td>${label}</td><td>${value}</td></tr>`;

    const totals = [
      row(t('Subtotal'), cash(shownSubtotal)),
      on('show_items_tax') && itemsTax > 0
        ? row(t('TotalItemsTax'), `${cash(itemsTax)} (${qty(itemsTaxRate)} %)`) : '',
      set('show_tax') && Number(s.taxe || 0) > 0 ? row(t('Tax'), cash(s.taxe)) : '',
      set('show_discount')
        ? row(t('Discount'), isPercentDiscount ? `${qty(s.discount)}%` : cash(manualDiscount)) : '',
      set('show_discount') && Number(s.discount_from_points || 0) > 0
        ? row(t('Discount_from_Points'), cash(s.discount_from_points)) : '',
      ...(promotions.length
        ? promotions.map(p => row(
            `${t('Promotions')} — ${esc(p.name || '')}${p.code ? ` (${esc(p.code)})` : ''}`,
            `−${cash(p.amount)}`))
        : (Number(s.promotion_discount || 0) > 0
            ? [row(`${t('Promotions')}${s.promotion_code ? ` (${esc(s.promotion_code)})` : ''}`, `−${cash(s.promotion_discount)}`)]
            : [])),
      set('show_shipping') ? row(t('Shipping'), cash(s.shipping)) : '',
      row(t('Total'), cash(s.GrandTotal), 'minimal-grand'),
      on('show_paid') ? row(t('Paid'), cash(s.paid_amount)) : '',
      on('show_due') ? row(t('Due'), cash(Number(s.GrandTotal) - Number(s.paid_amount))) : '',
      showPrevRow ? row(t('Previous_Dues'), cash(previousDues)) : '',
      showNetRow ? row(t('Net_Balance'), cash(netBalance)) : '',
    ].filter(Boolean).join('');

    const paymentsTable = paymentsVisible
      ? `<table class="minimal-payments">
          <thead><tr><th>${t('PayeBy')}</th><th>${t('Amount')}</th><th>${t('Change')}</th></tr></thead>
          <tbody>${payments.map(p =>
            `<tr><td>${p.payment_method ? esc(p.payment_method.name) : '---'}</td>
             <td>${price(p.montant)}</td><td>${price(p.change)}</td></tr>`).join('')}</tbody></table>`
      : '';

    return `<div class="receipt-layout-5">
      <div class="info text-center mb-2">
        ${logo ? `<div class="invoice_logo mb-2">${logo}</div>` : ''}
        ${on('show_store_name') ? `<div class="minimal-store-name">${esc(setting.CompanyName)}</div>` : ''}
        ${(setting.CompanyAdress || setting.CompanyPhone)
          ? `<div class="minimal-contact">${set('show_address') ? `<span>${esc(setting.CompanyAdress)}</span>` : ''}${set('show_address') && set('show_phone') ? '<span> &middot; </span>' : ''}${set('show_phone') ? `<span>${esc(setting.CompanyPhone)}</span>` : ''}</div>`
          : ''}
        ${set('show_email') && setting.email ? `<div class="minimal-contact">${esc(setting.email)}</div>` : ''}
      </div>
      <div class="minimal-divider"></div>
      <div class="minimal-meta">
        ${on('show_reference') && s.Ref ? `<div class="minimal-meta-row"><span>${t('Reference')}</span><span>${esc(s.Ref)}</span></div>` : ''}
        ${on('show_date') ? `<div class="minimal-meta-row"><span>${t('date')}</span><span>${esc(s.date)}</span></div>` : ''}
        ${on('show_seller') ? `<div class="minimal-meta-row"><span>${t('Seller')}</span><span>${esc(s.seller_name)}</span></div>` : ''}
        ${set('show_customer') ? `<div class="minimal-meta-row"><span>${t('Customer')}</span><span>${esc(s.client_name)}</span></div>` : ''}
        ${set('show_Warehouse') ? `<div class="minimal-meta-row"><span>${t('warehouse')}</span><span>${esc(s.warehouse_name)}</span></div>` : ''}
      </div>
      <div class="minimal-divider"></div>
      <table class="minimal-items"><tbody>${details.map(line).join('')}</tbody></table>
      <div class="minimal-divider"></div>
      <table class="minimal-totals"><tbody>${totals}</tbody></table>
      ${paymentsTable}
      ${set('show_note') && ps.note_customer ? `<p class="minimal-note" style="white-space:pre-line;">${esc(ps.note_customer)}</p>` : ''}
      ${qrRow()}
    </div>`;
  };

  switch (layout) {
    case 2: return layout2();
    case 3: return layout3();
    case 4: return layout4();
    case 5: return layout5();
    default: return layout1();
  }
}
