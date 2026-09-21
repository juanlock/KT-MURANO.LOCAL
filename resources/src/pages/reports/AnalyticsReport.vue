<template>
  <div class="page">
    <PageHeader :title="$t('Analytics_Report')" :breadcrumb="[$t('Reports'), $t('Analytics_Report')]">
      <template #actions>
        <a-space>
          <!-- Multi-Currency: view amounts converted (display-only) -->
          <ViewCurrencySelect />
          <a-button :loading="loading" @click="load">
            <template #icon><ReloadOutlined /></template>
            {{ $t('Refresh') }}
          </a-button>
          <a-button @click="printReport">
            <template #icon><PrinterOutlined /></template>
            {{ $t('print') }}
          </a-button>
        </a-space>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <a-space wrap align="end">
        <div>
          <div class="filter-label">{{ $t('DateRange') }}</div>
          <DateRangePicker v-model:value="range" value-format="YYYY-MM-DD" @change="load" />
        </div>
        <div>
          <div class="filter-label">{{ $t('QuickRanges') }}</div>
          <a-space wrap :size="4">
            <a-button v-for="q in QUICK_RANGES" :key="q.key" size="small" @click="applyQuick(q.key)">
              {{ q.labelKey ? $t(q.labelKey) : q.label }}
            </a-button>
          </a-space>
        </div>
        <div>
          <div class="filter-label">{{ $t('warehouse') }}</div>
          <a-select
            v-model:value="warehouseId"
            style="min-width: 220px"
            allow-clear
            show-search
            option-filter-prop="label"
            :placeholder="$t('Choose_Warehouse')"
            :options="warehouseOptions"
            @change="load"
          />
        </div>
      </a-space>
    </a-card>

    <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <template v-else>
      <a-alert type="info" show-icon style="margin-bottom: 16px">
        <template #message>
          <strong>{{ range[0] }}</strong> — <strong>{{ range[1] }}</strong>
          <a-tag v-if="warehouseLabel" style="margin-left: 8px">{{ warehouseLabel }}</a-tag>
        </template>
      </a-alert>

      <a-row :gutter="[16, 16]">
        <a-col :xs="24" :lg="12">
          <a-card size="small" :title="$t('Opening_Purchase')">
            <div v-for="row in leftRows" :key="row.label" class="metric-row">
              <span class="metric-label">{{ row.label }}</span>
              <span class="metric-value">{{ money(row.value) }}</span>
            </div>
          </a-card>
        </a-col>
        <a-col :xs="24" :lg="12">
          <a-card size="small" :title="$t('Closing_Sales')">
            <div v-for="row in rightRows" :key="row.label" class="metric-row">
              <span class="metric-label">{{ row.label }}</span>
              <span class="metric-value">{{ money(row.value) }}</span>
            </div>
          </a-card>
        </a-col>
        <a-col :span="24">
          <a-card size="small">
            <div class="profit-row">
              <strong>{{ $t('Gross_Profit') }}:</strong>
              <strong :style="{ color: grossProfit >= 0 ? '#52c41a' : '#ff4d4f', fontSize: '16px' }">
                {{ money(grossProfit) }}
              </strong>
            </div>
            <div class="profit-formula">{{ $t('Formula_Gross_Profit') }}</div>
            <a-divider style="margin: 12px 0" />
            <div class="profit-row">
              <strong>{{ $t('Net_Profit') }}:</strong>
              <strong :style="{ color: netProfit >= 0 ? '#52c41a' : '#ff4d4f', fontSize: '16px' }">
                {{ money(netProfit) }}
              </strong>
            </div>
            <div class="profit-formula">
              {{ $t('Formula_Net_Profit') }}
            </div>
          </a-card>
        </a-col>
      </a-row>
    </template>
  </div>
</template>

<script setup>
/**
 * GET report/analytics_summary?from&to[&warehouse_id] → a flat object of 16
 * money fields + warehouses. Gross/Net profit are computed here, exactly as
 * legacy does: gross = (closing stock at sale price + sales) - (opening stock
 * at purchase price + purchases); net deducts expenses + transfer shipping
 * (everything else is already inside those aggregates). Print opens a
 * self-contained window like legacy's print view.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { ReloadOutlined, PrinterOutlined } from '@ant-design/icons-vue';
import dayjs from 'dayjs';
import PageHeader from '../../components/PageHeader.vue';
import { useFormat } from '../../composables/useFormat';
import http from '../../lib/http';
import DateRangePicker from '../../components/DateRangePicker.vue';
import ViewCurrencySelect from '../../components/ViewCurrencySelect.vue';

const { t } = useI18n();
const { money } = useFormat();

const QUICK_RANGES = [
  { key: 'today', labelKey: 'Today' },
  { key: 'yesterday', labelKey: 'Yesterday' },
  { key: '7d', label: '7D' },
  { key: '30d', label: '30D' },
  { key: '90d', label: '90D' },
  { key: 'mtd', labelKey: 'MTD' },
  { key: 'ytd', labelKey: 'YTD' },
];

const loading = ref(true);
const range = ref([dayjs().format('YYYY-MM-DD'), dayjs().format('YYYY-MM-DD')]);
const warehouseId = ref(undefined);
const warehouses = ref([]);
const data = ref({});

const warehouseOptions = computed(() => warehouses.value.map(w => ({ value: w.id, label: w.name })));
const warehouseLabel = computed(() => warehouses.value.find(w => w.id === warehouseId.value)?.name || null);

const n = v => Number(v) || 0;

const leftRows = computed(() => [
  { label: t('Opening_Stock_By_Purchase_Price'), value: n(data.value.opening_stock_purchase_price) },
  { label: t('Opening_Stock_By_Sale_Price'), value: n(data.value.opening_stock_sale_price) },
  { label: t('Total_Purchase_Excl_Tax_Discount'), value: n(data.value.total_purchase_excl_tax) },
  { label: t('Total_Stock_Adjustment'), value: n(data.value.total_stock_adjustment) },
  { label: t('Total_Expense'), value: n(data.value.total_expense) },
  { label: t('Total_Purchase_Shipping_Charge'), value: n(data.value.total_purchase_shipping_charge) },
  { label: t('Total_Transfer_Shipping_Charge'), value: n(data.value.total_transfer_shipping_charge) },
  { label: t('Total_Sell_Discount'), value: n(data.value.total_sell_discount) },
  { label: t('Total_Customer_Reward'), value: n(data.value.total_customer_reward) },
  { label: t('Total_Sell_Return'), value: n(data.value.total_sell_return) },
]);

const rightRows = computed(() => [
  { label: t('Closing_Stock_By_Purchase_Price'), value: n(data.value.closing_stock_purchase_price) },
  { label: t('Closing_Stock_By_Sale_Price'), value: n(data.value.closing_stock_sale_price) },
  { label: t('Total_Sales_Excl_Tax_Discount'), value: n(data.value.total_sales_excl_tax) },
  { label: t('Total_Sell_Shipping_Charge'), value: n(data.value.total_sell_shipping_charge) },
  { label: t('Total_Purchase_Return'), value: n(data.value.total_purchase_return) },
  { label: t('Total_Purchase_Discount'), value: n(data.value.total_purchase_discount) },
]);

const grossProfit = computed(() => {
  const totalPurchasePrice = n(data.value.opening_stock_purchase_price) + n(data.value.total_purchase_excl_tax);
  const totalSellPrice = n(data.value.closing_stock_sale_price) + n(data.value.total_sales_excl_tax);
  return totalSellPrice - totalPurchasePrice;
});

const netProfit = computed(() =>
  grossProfit.value - (n(data.value.total_expense) + n(data.value.total_transfer_shipping_charge))
);

function applyQuick(kind) {
  const now = dayjs();
  const today = now.format('YYYY-MM-DD');
  const map = {
    today: [today, today],
    yesterday: [now.subtract(1, 'day').format('YYYY-MM-DD'), now.subtract(1, 'day').format('YYYY-MM-DD')],
    '7d': [now.subtract(6, 'day').format('YYYY-MM-DD'), today],
    '30d': [now.subtract(29, 'day').format('YYYY-MM-DD'), today],
    '90d': [now.subtract(89, 'day').format('YYYY-MM-DD'), today],
    mtd: [now.startOf('month').format('YYYY-MM-DD'), today],
    ytd: [now.startOf('year').format('YYYY-MM-DD'), today],
  };
  range.value = map[kind];
  load();
}

async function load() {
  if (!range.value || !range.value[0]) return;
  loading.value = true;
  try {
    const params = { from: range.value[0], to: range.value[1] };
    if (warehouseId.value) params.warehouse_id = warehouseId.value;
    const payload = await http.get('report/analytics_summary', params);
    data.value = payload || {};
    warehouses.value = payload?.warehouses || [];
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    loading.value = false;
  }
}

function printReport() {
  const title = `${t('Reports')} / ${t('Analytics_Report')}`;
  const meta = `${range.value[0]} — ${range.value[1]}${warehouseLabel.value ? ` (${warehouseLabel.value})` : ''}`;

  const table = rows => rows.map(r =>
    `<tr><td>${r.label}</td><td style="text-align:right">${money(r.value)}</td></tr>`
  ).join('');

  const html = `<!doctype html>
<html><head><meta charset="utf-8"><title>${title}</title>
<style>
  body { font-family: Arial, sans-serif; margin: 0.5cm; color: #333; }
  h1 { font-size: 15px; margin: 0 0 2px; }
  .meta { font-size: 11px; color: #666; margin-bottom: 14px; }
  .card { border: 1px solid #ddd; border-radius: 8px; padding: 14px; margin-bottom: 14px; page-break-inside: avoid; }
  h2 { font-size: 13px; margin: 0 0 8px; border-bottom: 1px solid #e0e0e0; padding-bottom: 6px; }
  table { width: 100%; border-collapse: collapse; font-size: 12px; }
  td { padding: 6px 4px; border-bottom: 1px solid #eee; }
  .profit { display: flex; justify-content: space-between; font-weight: 700; font-size: 13px; margin-bottom: 2px; }
  .formula { font-size: 10px; color: #666; font-style: italic; margin-bottom: 10px; }
  @page { size: A4; margin: 1cm; }
</style></head><body>
<h1>${title}</h1><div class="meta">${meta}</div>
<div class="card"><h2>${t('Opening_Purchase')}</h2><table>${table(leftRows.value)}</table></div>
<div class="card"><h2>${t('Closing_Sales')}</h2><table>${table(rightRows.value)}</table></div>
<div class="card">
  <div class="profit"><span>${t('Gross_Profit')}:</span><span>${money(grossProfit.value)}</span></div>
  <div class="formula">${t('Formula_Gross_Profit')}</div>
  <div class="profit"><span>${t('Net_Profit')}:</span><span>${money(netProfit.value)}</span></div>
  <div class="formula">${t('Formula_Net_Profit')}</div>
</div>
</body></html>`;

  const w = window.open('', '_blank');
  if (!w) { window.print(); return; }
  w.document.open();
  w.document.write(html);
  w.document.close();
  w.focus();
  setTimeout(() => { w.print(); w.close(); }, 400);
}

onMounted(load);
</script>

<style scoped>
.filter-label {
  margin-bottom: 4px;
  color: rgba(0, 0, 0, 0.55);
  font-size: 13px;
}
.metric-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 9px 0;
  border-bottom: 1px solid rgba(5, 5, 5, 0.06);
}
.metric-row:last-child {
  border-bottom: none;
}
.metric-label {
  color: rgba(0, 0, 0, 0.65);
  font-size: 13px;
}
.metric-value {
  font-weight: 500;
}
.profit-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.profit-formula {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.45);
  font-style: italic;
  margin-top: 2px;
}
</style>
