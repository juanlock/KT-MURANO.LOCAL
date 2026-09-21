<template>
  <div class="page">
    <PageHeader :title="tf('products_sold_summary', 'Products Sold Summary')" :breadcrumb="[$t('Reports'), tf('products_sold_summary', 'Products Sold Summary')]">
      <template #actions>
        <a-space wrap>
          <!-- Multi-Currency: view amounts converted (display-only) -->
          <ViewCurrencySelect />
          <a-select
            v-model:value="paperWidth"
            style="width: 100px"
            :options="[{ value: 58, label: '58 mm' }, { value: 80, label: '80 mm' }, { value: 88, label: '88 mm' }]"
          />
          <a-button type="primary" :disabled="loading" @click="printReceipt">
            <template #icon><PrinterOutlined /></template>
            {{ tf('Print_Receipt', 'Print Receipt') }}
          </a-button>
          <a-button :loading="exporting === 'excel'" :disabled="loading" @click="doExport('excel')">
            <template #icon><FileExcelOutlined /></template>
            {{ $t('Export') }} Excel
          </a-button>
          <a-button :loading="exporting === 'pdf'" :disabled="loading" @click="doExport('pdf')">
            <template #icon><FilePdfOutlined /></template>
            {{ $t('Export') }} PDF
          </a-button>
        </a-space>
      </template>
    </PageHeader>

    <!-- Filters -->
    <a-card size="small" style="margin-bottom: 16px">
      <a-space wrap>
        <DateRangePicker v-model:value="range" allow-clear @change="fetchReport" />
        <a-select
          v-model:value="warehouseId" style="width: 180px" allow-clear show-search option-filter-prop="label"
          :placeholder="tf('Till', 'Till')" :options="opts(warehouses)" @change="fetchReport" />
        <a-select
          v-model:value="userId" style="width: 180px" allow-clear show-search option-filter-prop="label"
          :placeholder="tf('Sales_Person', 'Sales person')" :options="userOpts" @change="fetchReport" />
        <a-select
          v-model:value="categoryId" style="width: 180px" allow-clear show-search option-filter-prop="label"
          :placeholder="$t('Categorie')" :options="opts(categoriesList)" @change="fetchReport" />
        <a-select
          v-model:value="statut" style="width: 150px" allow-clear
          :placeholder="tf('Sale_Status', 'Sale status')" :options="statusOptions" @change="fetchReport" />
        <a-input-search
          v-model:value="search" style="width: 220px" allow-clear
          :placeholder="$t('Search')" @search="fetchReport" />
      </a-space>
    </a-card>

    <!-- Summary tiles -->
    <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
      <a-col v-for="k in kpiTiles" :key="k.key" :xs="12" :sm="12" :md="6">
        <a-card size="small" class="kpi-card">
          <div class="kpi-inner">
            <div class="kpi-icon" :style="{ background: k.tint, color: k.color }">
              <component :is="k.icon" />
            </div>
            <div class="kpi-text">
              <a-typography-text type="secondary" class="kpi-label">{{ k.label }}</a-typography-text>
              <div class="kpi-value">
                <a-spin v-if="loading" size="small" />
                <template v-else>{{ k.value }}</template>
              </div>
            </div>
          </div>
        </a-card>
      </a-col>
    </a-row>

    <!-- Grouped table -->
    <a-card size="small">
      <template #title>
        {{ tf('Day_Report', 'Day Report') }}
        <a-tag v-if="meta.day_status === 'open'" color="warning" style="margin-left: 8px">
          {{ tf('Day_is_still_opened', 'Day is still opened') }}
        </a-tag>
        <a-tag v-else-if="meta.day_status === 'closed'" style="margin-left: 8px">
          {{ tf('Day_is_closed', 'Day is closed') }}
        </a-tag>
      </template>
      <template #extra>
        <a-typography-text type="secondary" style="font-size: 12px">
          {{ tf('Till', 'Till') }}: {{ meta.till || $t('All') }} ·
          {{ tf('Sales_Person', 'Sales person') }}: {{ meta.sales_person || $t('All') }} ·
          {{ meta.from }} → {{ meta.to }}
        </a-typography-text>
      </template>

      <a-spin :spinning="loading">
        <a-empty v-if="!categories.length && !loading" :description="$t('NodataAvailable')" style="padding: 48px 0" />

        <div v-else class="summary-table-wrap">
          <table class="summary-table">
            <thead>
              <tr>
                <th style="width: 55%">{{ $t('ProductName') }}</th>
                <th class="num" style="width: 15%">{{ $t('Quantity') }}</th>
                <th class="num" style="width: 30%">{{ $t('Total') }}</th>
              </tr>
            </thead>
            <tbody v-for="group in categories" :key="group.category_id ?? 'none'">
              <tr class="group-row">
                <th colspan="3">{{ catName(group) }}</th>
              </tr>
              <tr v-for="item in group.items" :key="(group.category_id ?? 0) + '-' + item.product_id + '-' + item.product_name">
                <td>
                  {{ item.product_name }}
                  <a-typography-text v-if="item.product_code" type="secondary" style="display: block; font-size: 12px">
                    {{ item.product_code }}
                  </a-typography-text>
                </td>
                <td class="num">{{ qty(item.quantity) }} <a-typography-text type="secondary" style="font-size: 12px">{{ item.unit }}</a-typography-text></td>
                <td class="num">{{ money(item.total) }}</td>
              </tr>
              <tr class="subtotal-row">
                <td class="num bold">{{ tf('Sub_Total', 'Sub Total') }}</td>
                <td class="num bold">{{ qty(group.sub_total_quantity) }}</td>
                <td class="num bold">{{ money(group.sub_total) }}</td>
              </tr>
            </tbody>
            <tfoot>
              <tr class="grand-row">
                <td class="num bold">{{ tf('Grand_Total', 'Grand Total') }}</td>
                <td class="num bold">{{ qty(totals.quantity) }}</td>
                <td class="num bold">{{ money(totals.total) }}</td>
              </tr>
            </tfoot>
          </table>
        </div>
      </a-spin>
    </a-card>
  </div>
</template>

<script setup>
/**
 * Per-item day report — the classic POS "DAY REPORT" receipt: every product
 * sold in the range, grouped by category with SUB TOTAL lines and a GRAND
 * TOTAL, plus an 58/80/88mm thermal printout that mirrors the paper original.
 *
 * Payload: { categories:[{category_id, category_name, items, sub_total_quantity,
 * sub_total}], totals, meta, company, warehouses, users, categories_list }.
 * Grouped shape, so it renders its own table instead of ReportPage/DataTable.
 */
import { ref, computed, onMounted } from 'vue';
import dayjs from 'dayjs';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  PrinterOutlined, FileExcelOutlined, FilePdfOutlined,
  DollarOutlined, ShoppingOutlined, NumberOutlined, TeamOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DateRangePicker from '../../components/DateRangePicker.vue';
import ViewCurrencySelect from '../../components/ViewCurrencySelect.vue';
import { useFormat } from '../../composables/useFormat';
import { exportExcel, exportPdf } from '../../lib/exporters';
import { t as tf } from '../../i18n';
import http from '../../lib/http';

const { t } = useI18n();
const { money, number } = useFormat();

/* ---------------------------------------------------------------- state */
// A day report defaults to today.
const range = ref([dayjs().startOf('day'), dayjs()]);
const warehouseId = ref(undefined);
const userId = ref(undefined);
const categoryId = ref(undefined);
const statut = ref(undefined);
const search = ref('');
const paperWidth = ref(80);

const loading = ref(true);
const exporting = ref('');
const categories = ref([]);
const totals = ref({ quantity: 0, total: 0, items: 0 });
const meta = ref({});
const company = ref({});
const warehouses = ref([]);
const users = ref([]);
const categoriesList = ref([]);

const statusOptions = computed(() => [
  { value: 'completed', label: tf('Completed', 'Completed') },
  { value: 'pending', label: tf('Pending', 'Pending') },
  { value: 'ordered', label: tf('Ordered', 'Ordered') },
]);

const opts = list => (list || []).map(x => ({ value: x.id, label: x.name }));
const userOpts = computed(() => (users.value || []).map(x => ({ value: x.id, label: x.username })));

/* ------------------------------------------------------------- helpers */
// Quantities keep decimals only when they carry information.
function qty(v) {
  const n = Number(v || 0);
  return Number.isInteger(n) ? String(n) : n.toFixed(2);
}

// The backend labels category-less products in English; translate it here.
function catName(group) {
  return group.category_id === null ? tf('Uncategorized', 'Uncategorized') : group.category_name;
}

const kpiTiles = computed(() => [
  { key: 'items', label: tf('Items_Sold', 'Items sold'), value: totals.value.items, icon: ShoppingOutlined, color: '#1677ff', tint: 'rgba(22, 119, 255, 0.12)' },
  { key: 'qty', label: tf('Total_Qty', 'Total qty'), value: qty(totals.value.quantity), icon: NumberOutlined, color: '#13c2c2', tint: 'rgba(19, 194, 194, 0.12)' },
  { key: 'total', label: tf('Grand_Total', 'Grand total'), value: money(totals.value.total), icon: DollarOutlined, color: '#6d28d9', tint: 'rgba(109, 40, 217, 0.12)' },
  { key: 'trans', label: `${tf('Transactions', 'Transactions')} / ${tf('Cust_Count', 'Customers')}`, value: `${meta.value.transaction_count ?? 0} / ${meta.value.customer_count ?? 0}`, icon: TeamOutlined, color: '#22c55e', tint: 'rgba(34, 197, 94, 0.12)' },
]);

/* ---------------------------------------------------------------- data */
const filterParams = () => ({
  ...(range.value?.[0] ? { from: range.value[0].format('YYYY-MM-DD') } : {}),
  ...(range.value?.[1] ? { to: range.value[1].format('YYYY-MM-DD') } : {}),
  ...(warehouseId.value ? { warehouse_id: warehouseId.value } : {}),
  ...(userId.value ? { user_id: userId.value } : {}),
  ...(categoryId.value ? { category_id: categoryId.value } : {}),
  ...(statut.value ? { statut: statut.value } : {}),
  ...(search.value ? { search: search.value } : {}),
});

async function fetchReport() {
  loading.value = true;
  try {
    const data = await http.get('report/products_sold_summary', filterParams());
    categories.value = Array.isArray(data.categories) ? data.categories : [];
    totals.value = data.totals || { quantity: 0, total: 0, items: 0 };
    meta.value = data.meta || {};
    company.value = data.company || {};
    warehouses.value = data.warehouses || [];
    users.value = data.users || [];
    categoriesList.value = data.categories_list || [];
  } catch (e) {
    message.error(e?.message || 'Error');
  } finally {
    loading.value = false;
  }
}

/* -------------------------------------------------------------- export */
// Flat rows: one line per item plus subtotal/grand-total lines.
function flatRows() {
  const out = [];
  categories.value.forEach(group => {
    group.items.forEach(item => out.push({
      category_name: catName(group),
      product_code: item.product_code || '',
      product_name: item.product_name,
      unit: item.unit || '',
      quantity: item.quantity,
      total: item.total,
    }));
    out.push({
      category_name: catName(group), product_code: '', product_name: tf('Sub_Total', 'Sub Total'),
      unit: '', quantity: group.sub_total_quantity, total: group.sub_total,
    });
  });
  if (out.length) {
    out.push({
      category_name: '', product_code: '', product_name: tf('Grand_Total', 'Grand Total'),
      unit: '', quantity: totals.value.quantity, total: totals.value.total,
    });
  }
  return out;
}

const exportColumns = () => [
  { title: t('Categorie'), dataIndex: 'category_name' },
  { title: t('Code'), dataIndex: 'product_code' },
  { title: t('ProductName'), dataIndex: 'product_name' },
  { title: tf('Unit', 'Unit'), dataIndex: 'unit' },
  { title: t('Quantity'), dataIndex: 'quantity' },
  { title: t('Total'), dataIndex: 'total', exportValue: r => money(r.total) },
];

async function doExport(kind) {
  exporting.value = kind;
  try {
    const name = `products-sold-summary_${meta.value.from}_${meta.value.to}`;
    if (kind === 'excel') await exportExcel(name, exportColumns(), flatRows());
    else await exportPdf(tf('products_sold_summary', 'Products Sold Summary'), exportColumns(), flatRows());
  } catch (e) {
    message.error(e?.message || 'Export failed');
  } finally {
    exporting.value = '';
  }
}

/* --------------------------------------------------------- thermal print */
function printReceipt() {
  const w = window.open('', '_blank', 'width=420,height=640');
  if (!w) {
    message.error('Please allow popups to print');
    return;
  }

  const esc = s => String(s == null ? '' : s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

  const width = Number(paperWidth.value) || 80;
  const numFmt = v => number(v);
  const dayLine = meta.value.day_status === 'open'
    ? tf('Day_is_still_opened', 'Day is still opened')
    : (meta.value.day_status === 'closed' ? tf('Day_is_closed', 'Day is closed') : '');

  const headRow = (label, value) =>
    `<div class="kv"><span class="k">${esc(label)}</span><span class="s">:</span><span class="v">${esc(value)}</span></div>`;

  let body = '';
  categories.value.forEach(group => {
    body += `<div class="cat">${esc(catName(group))}</div>`;
    body += '<table class="items">';
    group.items.forEach(item => {
      body += `<tr>
        <td class="name">${esc(item.product_name)}</td>
        <td class="qty">${esc(qty(item.quantity))}</td>
        <td class="amt">${esc(numFmt(item.total))}</td>
      </tr>`;
    });
    body += `<tr class="sub">
        <td class="name">${esc(tf('Sub_Total', 'Sub Total'))}</td>
        <td class="qty">${esc(qty(group.sub_total_quantity))}</td>
        <td class="amt">${esc(numFmt(group.sub_total))}</td>
      </tr>`;
    body += '</table>';
  });

  const dateLabel = meta.value.from === meta.value.to
    ? meta.value.from
    : `${meta.value.from} — ${meta.value.to}`;

  // Stamped when the receipt is actually printed, not when the data was fetched.
  const printedOn = dayjs().format('DD-MM-YYYY HH:mm:ss');

  const doc = w.document;
  doc.open();
  doc.write(`<!doctype html>
<html>
  <head>
    <meta charset="utf-8" />
    <title>${esc(tf('Day_Report', 'Day Report'))}</title>
    <style>
      @page { size: ${width}mm auto; margin: 0; }
      @media print { body, body * { visibility: visible !important; } }
      * { box-sizing: border-box; }
      body {
        width: ${width}mm;
        margin: 0;
        padding: 3mm 2mm;
        font-family: "Courier New", Courier, monospace;
        font-size: 11px;
        line-height: 1.35;
        color: #000;
        background: #fff;
      }
      .center { text-align: center; }
      .company { font-weight: bold; font-size: 12px; }
      .rule { border-top: 1px dashed #000; margin: 4px 0; }
      .title { font-weight: bold; font-size: 14px; margin: 4px 0 6px; }
      .kv { display: flex; }
      .kv .k { flex: 0 0 42%; text-transform: uppercase; }
      .kv .s { flex: 0 0 4%; }
      .kv .v { flex: 1 1 auto; }
      .banner { text-align: center; font-weight: bold; text-transform: uppercase; margin: 2px 0; }
      .section { font-weight: bold; text-transform: uppercase; margin: 6px 0 2px; }
      .cat { font-weight: bold; text-decoration: underline; margin-top: 6px; }
      table.items { width: 100%; border-collapse: collapse; table-layout: fixed; }
      table.items td { vertical-align: top; padding: 1px 0; word-wrap: break-word; }
      td.name { width: 58%; }
      td.qty  { width: 12%; text-align: right; padding-right: 2mm; }
      td.amt  { width: 30%; text-align: right; }
      tr.sub td { border-top: 1px solid #000; font-weight: bold; padding-top: 2px; }
      .grand { display: flex; justify-content: space-between; font-weight: bold;
               font-size: 12px; border-top: 1px solid #000; border-bottom: 1px solid #000;
               padding: 3px 0; margin-top: 6px; }
      .foot { text-align: center; margin-top: 8px; font-size: 10px; }
    </style>
  </head>
  <body>
    <div class="center company">${esc(company.value.name)}</div>
    <div class="center">${esc(company.value.address)}</div>
    <div class="center">${esc(company.value.email)}</div>
    <div class="center">${esc(company.value.phone)}</div>

    <div class="title">${esc(tf('Day_Report', 'Day Report'))}</div>

    ${headRow(tf('Till', 'Till'), meta.value.till || t('All'))}
    ${headRow(tf('Sales_Person', 'Sales person'), meta.value.sales_person || t('All'))}
    ${headRow(tf('Cust_Count', 'Cust. count'), meta.value.customer_count ?? 0)}
    ${headRow(tf('Transactions', 'Transactions'), meta.value.transaction_count ?? 0)}
    ${headRow(tf('Trans_Date', 'Trans. date'), dateLabel)}
    ${headRow(tf('Printed_On', 'Printed on'), printedOn)}
    ${headRow(tf('Printed_By', 'Printed by'), meta.value.printed_by || '')}

    ${dayLine ? `<div class="rule"></div><div class="banner">${esc(dayLine)}</div>` : ''}
    <div class="rule"></div>

    <div class="section">${esc(t('Sales'))}</div>
    ${body || '<div class="center">—</div>'}

    <div class="grand">
      <span>${esc(tf('Grand_Total', 'Grand Total'))}</span>
      <span>${esc(numFmt(totals.value.total))}</span>
    </div>

    <div class="foot">${esc(tf('Items_Sold', 'Items sold'))}: ${esc(totals.value.items)} &nbsp;·&nbsp; ${esc(tf('Total_Qty', 'Total qty'))}: ${esc(qty(totals.value.quantity))}</div>
  </body>
</html>`);
  doc.close();

  w.focus();

  // Close only after the print dialog resolves; a fixed timer races the dialog.
  let closed = false;
  const closeOnce = () => {
    if (closed) return;
    closed = true;
    try { w.close(); } catch (e) { /* popup already gone */ }
  };
  try { w.onafterprint = closeOnce; } catch (e) { /* ignore */ }

  setTimeout(() => {
    try { w.print(); } catch (e) { /* ignore */ }
    setTimeout(closeOnce, 60000); // fallback for browsers that never fire onafterprint
  }, 400);
}

onMounted(fetchReport);
</script>

<style scoped>
.kpi-card { border-radius: 10px; }
.kpi-inner {
  display: flex;
  align-items: center;
  gap: 12px;
}
.kpi-icon {
  width: 44px;
  height: 44px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  flex: 0 0 auto;
}
.kpi-text { min-width: 0; flex: 1 1 auto; }
.kpi-label {
  display: block;
  font-size: 12px;
  margin-bottom: 2px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.kpi-value {
  font-size: 20px;
  font-weight: 700;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.summary-table-wrap { overflow-x: auto; }
.summary-table {
  width: 100%;
  border-collapse: collapse;
}
.summary-table th,
.summary-table td {
  padding: 8px 12px;
  border-bottom: 1px solid rgba(128, 128, 128, 0.18);
  text-align: left;
}
.summary-table .num { text-align: right; }
.summary-table .bold { font-weight: 600; }
.summary-table .group-row th {
  background: rgba(128, 128, 128, 0.08);
  text-transform: uppercase;
  letter-spacing: 0.03em;
  font-size: 12px;
}
.summary-table .subtotal-row td { border-top: 2px solid rgba(128, 128, 128, 0.35); }
.summary-table .grand-row td {
  border-top: 3px double rgba(128, 128, 128, 0.55);
  font-size: 15px;
}
@media (max-width: 575px) {
  .kpi-value { font-size: 16px; }
}
</style>
