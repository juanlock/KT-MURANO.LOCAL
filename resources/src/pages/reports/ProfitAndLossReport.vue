<template>
  <div class="page">
    <PageHeader :title="$t('ProfitandLoss')" :breadcrumb="[$t('Reports'), $t('ProfitandLoss')]">
      <template #actions>
        <a-space :size="8" wrap>
          <!-- Multi-Currency: view amounts converted (display-only) -->
          <ViewCurrencySelect />
          <a-button :loading="exporting" @click="doExport">
            <template #icon><FileExcelOutlined /></template>
            {{ $t('Export') }} Excel
          </a-button>
          <a-button :loading="exportingPdf" @click="doExportPdf">
            <template #icon><FilePdfOutlined /></template>
            {{ $t('Export') }} PDF
          </a-button>
          <a-button @click="doPrint">
            <template #icon><PrinterOutlined /></template>
            {{ $t('print') }}
          </a-button>
        </a-space>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <a-space wrap :size="12">
        <DateRangePicker v-model:value="range" :allow-clear="false" @change="load" />
        <a-select
          v-model:value="warehouseId"
          style="width: 200px"
          allow-clear
          show-search
          option-filter-prop="label"
          :placeholder="$t('warehouse')"
          :options="warehouseOptions"
          @change="load"
        />
      </a-space>
    </a-card>

    <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <template v-else>
      <!-- ============ Profit heroes ============ -->
      <a-row :gutter="[16, 16]">
        <a-col :xs="24" :lg="10">
          <div class="hero-card hero-custom">
            <div class="hero-icon"><SlidersOutlined /></div>
            <div class="hero-meta">
              <div class="hero-label">{{ $t('Custom_Profit') }}</div>
              <div class="hero-value">{{ money(customProfit) }}</div>
              <div class="hero-sub hero-formula">{{ formulaText }}</div>
            </div>
          </div>
        </a-col>
        <a-col v-for="p in profitCards" :key="p.sub" :xs="24" :sm="12" :lg="7">
          <div class="hero-card" :class="Number(p.value) >= 0 ? 'hero-pos' : 'hero-neg'">
            <div class="hero-icon">
              <component :is="Number(p.value) >= 0 ? RiseOutlined : FallOutlined" />
            </div>
            <div class="hero-meta">
              <div class="hero-label">{{ p.label }}</div>
              <div class="hero-value">{{ money(p.value) }}</div>
              <div class="hero-sub">{{ p.sub }}</div>
            </div>
          </div>
        </a-col>
      </a-row>

      <!-- ============ Profit customizer ============ -->
      <a-card size="small" class="customizer" :title="$t('Customize_Profit')" style="margin-top: 16px">
        <template #extra>
          <a-button size="small" type="text" @click="resetBuilder">
            <template #icon><UndoOutlined /></template>
            {{ $t('Reset_to_Default') }}
          </a-button>
        </template>
        <div class="customizer-hint">{{ $t('Custom_Profit_Hint') }}</div>
        <a-row :gutter="[24, 16]">
          <a-col :xs="24" :md="14">
            <div
              v-for="c in components"
              :key="c.id"
              class="comp-row"
              :class="{ 'comp-row--off': !isEnabled(c.id) }"
            >
              <a-checkbox :checked="isEnabled(c.id)" @change="toggle(c.id)" />
              <a-tag :color="c.sign > 0 ? 'success' : 'error'" class="sign-tag">{{ c.sign > 0 ? '+' : '−' }}</a-tag>
              <span class="comp-label">{{ c.label }}</span>
              <a-radio-group
                v-if="c.id === 'cogs'"
                v-model:value="cogsMethod"
                size="small"
                button-style="solid"
                class="cogs-radio"
              >
                <a-radio-button value="fifo">FIFO</a-radio-button>
                <a-radio-button value="avg">{{ $t('AverageCost') }}</a-radio-button>
              </a-radio-group>
              <span class="comp-value">{{ money(c.value) }}</span>
            </div>
          </a-col>
          <a-col :xs="24" :md="10">
            <div class="builder-panel">
              <div class="builder-title">{{ $t('Profit_Formula') }}</div>
              <div v-for="c in enabledComponents" :key="c.id" class="builder-line">
                <span>{{ c.label }}</span>
                <span :class="c.sign > 0 ? 'pos' : 'neg'">{{ c.sign > 0 ? '+' : '−' }} {{ money(c.value) }}</span>
              </div>
              <a-empty v-if="!enabledComponents.length" :image="undefined" :description="$t('NodataAvailable')" />
              <a-divider style="margin: 10px 0" />
              <div class="builder-total">
                <span>{{ $t('Custom_Profit') }}</span>
                <span :class="customProfit >= 0 ? 'pos' : 'neg'">{{ money(customProfit) }}</span>
              </div>
            </div>
          </a-col>
        </a-row>
      </a-card>

      <!-- ============ Income statement ============ -->
      <div class="section-title">{{ $t('Income_Statement') }}</div>
      <a-card size="small" class="statement-card">
        <div class="statement">
          <!-- Revenue -->
          <div class="st-section">{{ $t('Revenue') }}</div>
          <div class="st-row"><span>{{ $t('Gross_Sales') }} <a-tag class="count-tag">{{ num(infos.sales_count) }}</a-tag></span><span>{{ money(stmt.grossSales) }}</span></div>
          <div class="st-row"><span>{{ $t('Service_Revenue') }} <a-tag class="count-tag">{{ num(infos.service_jobs_count) }}</a-tag></span><span>{{ money(stmt.serviceRev) }}</span></div>
          <div class="st-row"><span>{{ $t('SalesReturn') }} <a-tag class="count-tag">{{ num(infos.returns_sales_count) }}</a-tag></span><span class="neg">− {{ money(stmt.saleRet) }}</span></div>
          <div class="st-row st-total"><span>{{ $t('Net_Revenue') }}</span><span>{{ money(stmt.netRevenue) }}</span></div>

          <!-- COGS -->
          <div class="st-section">{{ $t('COGS') }}</div>
          <div class="st-row"><span>{{ $t('COGS') }} ({{ cogsMethod === 'fifo' ? 'FIFO' : $t('AverageCost') }})</span><span class="neg">− {{ money(stmt.cogs) }}</span></div>
          <div class="st-row"><span>{{ $t('Service_Parts_Cost') }}</span><span class="neg">− {{ money(stmt.parts) }}</span></div>
          <div class="st-row st-total">
            <span>{{ $t('Gross_Profit') }} <span class="pct">{{ pct(stmt.grossMargin) }}</span></span>
            <span :class="stmt.grossProfit >= 0 ? 'pos' : 'neg'">{{ money(stmt.grossProfit) }}</span>
          </div>

          <!-- Expenses -->
          <div class="st-section">{{ $t('Expenses') }}</div>
          <div v-for="cat in expenseCategories" :key="cat.name" class="st-row st-sub">
            <span>{{ cat.name }}</span><span class="neg">− {{ money(cat.amount) }}</span>
          </div>
          <div class="st-row st-total"><span>{{ $t('Expenses') }}</span><span class="neg">− {{ money(stmt.expenses) }}</span></div>

          <div class="st-row st-grand" :class="stmt.netProfit >= 0 ? 'st-grand--pos' : 'st-grand--neg'">
            <span>{{ $t('ProfitNet') }} <span class="pct">{{ pct(stmt.netMargin) }}</span></span>
            <span>{{ money(stmt.netProfit) }}</span>
          </div>

          <!-- Additional information -->
          <div class="st-section">{{ $t('Additional_Information') }}</div>
          <a-row :gutter="[8, 4]">
            <a-col v-for="row in infoRows" :key="row.label" :xs="24" :sm="12">
              <div class="st-row st-info"><span>{{ row.label }}</span><span>{{ money(row.value) }}</span></div>
            </a-col>
          </a-row>
        </div>
      </a-card>

      <!-- ============ Documents ============ -->
      <div class="section-title">{{ $t('Documents') }}</div>
      <a-row :gutter="[16, 16]">
        <a-col v-for="tile in docTiles" :key="tile.label" :xs="12" :sm="12" :md="6">
          <a-card size="small" class="stat-card">
            <div class="stat-inner">
              <div class="stat-icon" :style="{ background: tile.bg, color: tile.color }">
                <component :is="tile.icon" />
              </div>
              <div class="stat-meta">
                <div class="stat-label">
                  {{ tile.label }}
                  <a-tag class="count-tag">{{ num(tile.count) }}</a-tag>
                </div>
                <div class="stat-value">{{ money(tile.sum) }}</div>
              </div>
            </div>
          </a-card>
        </a-col>
      </a-row>

      <!-- ============ Cash flow ============ -->
      <div class="section-title">{{ $t('cash_flow') }}</div>
      <a-row :gutter="[16, 16]">
        <a-col v-for="tile in flowTiles" :key="tile.label" :xs="12" :sm="12" :md="8" :xl="tile.wide ? 24 : 8">
          <a-card size="small" class="stat-card" :class="{ 'stat-card--accent': tile.wide }">
            <div class="stat-inner">
              <div class="stat-icon" :style="{ background: tile.bg, color: tile.color }">
                <component :is="tile.icon" />
              </div>
              <div class="stat-meta">
                <div class="stat-label">{{ tile.label }}</div>
                <div class="stat-value" :style="{ color: tile.valueColor }">{{ money(tile.value) }}</div>
              </div>
            </div>
          </a-card>
        </a-col>
      </a-row>

      <!-- ============ Charts ============ -->
      <a-row :gutter="[16, 16]" style="margin-top: 8px">
        <a-col :xs="24">
          <a-card :title="$t('Daily_Trend')" size="small">
            <apexchart
              v-if="timeseries.length"
              type="line" height="340" :key="'trend-' + chartKey"
              :options="trendChart.options" :series="trendChart.series"
            />
            <a-empty v-else :description="$t('NodataAvailable')" style="padding: 48px 0" />
          </a-card>
        </a-col>
        <a-col :xs="24" :lg="14">
          <a-card :title="$t('Financial_Overview')" size="small">
            <apexchart type="bar" height="330" :key="'bar-' + chartKey" :options="barChart.options" :series="barChart.series" />
          </a-card>
        </a-col>
        <a-col :xs="24" :lg="10">
          <a-card :title="$t('Documents')" size="small">
            <apexchart
              v-if="docTotal > 0"
              type="donut" height="330" :key="'donut-' + chartKey"
              :options="donutChart.options" :series="donutChart.series"
            />
            <a-empty v-else :description="$t('NodataAvailable')" style="padding: 48px 0" />
          </a-card>
        </a-col>
        <a-col :xs="24">
          <a-card :title="$t('Expenses_By_Category')" size="small">
            <apexchart
              v-if="expenseCategories.length"
              type="bar" :height="expenseChartHeight" :key="'exp-' + chartKey"
              :options="expenseChart.options" :series="expenseChart.series"
            />
            <a-empty v-else :description="$t('NodataAvailable')" style="padding: 48px 0" />
          </a-card>
        </a-col>
      </a-row>
    </template>
  </div>
</template>

<script setup>
/**
 * Not a table — a financial statement + KPI page, so ReportPage doesn't apply. Contract:
 * GET report/profit_and_loss?from&to&warehouse_id →
 *   { data: { sales/purchases sums+counts+tax/discount/shipping, returns_*, COGS (fifo/avg),
 *             payments incl. opening balances, service job totals, expenses_by_category,
 *             profit_fifo, profit_average_cost },
 *     warehouses, timeseries: [{date, sales, purchases, returns, expenses}] }
 *
 * The profit customizer is client-side only: every signed component comes from the same
 * payload, the user picks which ones count, and the choice persists in localStorage.
 */
import { ref, computed, watch, onMounted } from 'vue';
import dayjs from 'dayjs';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  FileExcelOutlined, FilePdfOutlined, PrinterOutlined, RiseOutlined, FallOutlined,
  ShoppingCartOutlined, ShoppingOutlined, RollbackOutlined, UndoOutlined, DollarOutlined,
  WalletOutlined, CreditCardOutlined, FundOutlined, ToolOutlined, InboxOutlined,
  SlidersOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { useFormat } from '../../composables/useFormat';
import { useUiStore } from '../../stores/ui';
import { exportExcel, exportPdf, printRows } from '../../lib/exporters';
import http from '../../lib/http';
import DateRangePicker from '../../components/DateRangePicker.vue';
import ViewCurrencySelect from '../../components/ViewCurrencySelect.vue';

const { t } = useI18n();
const { money, number } = useFormat();
const ui = useUiStore();
const num = v => number(v, 0);
const n = v => Number(v) || 0;
const pct = v => `${n(v).toFixed(1)}%`;

const loading = ref(true);
const exporting = ref(false);
const exportingPdf = ref(false);
const range = ref([dayjs().subtract(29, 'day'), dayjs()]);
const warehouseId = ref(undefined);
const infos = ref({});
const warehouses = ref([]);
const timeseries = ref([]);

const warehouseOptions = computed(() =>
  warehouses.value.map(w => ({ value: w.id, label: w.name }))
);

async function load() {
  loading.value = true;
  try {
    const data = await http.get('report/profit_and_loss', {
      from: range.value?.[0]?.format?.('YYYY-MM-DD') || '',
      to: range.value?.[1]?.format?.('YYYY-MM-DD') || '',
      warehouse_id: warehouseId.value || '',
    });
    infos.value = data?.data || {};
    warehouses.value = data?.warehouses || [];
    timeseries.value = data?.timeseries || [];
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    loading.value = false;
  }
}

/* --------------------------- profit customizer ---------------------------- */
const STORAGE_KEY = 'pl_profit_builder_v1';
const DEFAULT_ENABLED = ['sales', 'service', 'cogs', 'expenses'];

const enabled = ref([...DEFAULT_ENABLED]);
const cogsMethod = ref('fifo');

try {
  const saved = JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null');
  if (saved && Array.isArray(saved.enabled)) enabled.value = saved.enabled;
  if (saved && (saved.cogsMethod === 'fifo' || saved.cogsMethod === 'avg')) cogsMethod.value = saved.cogsMethod;
} catch (e) { /* corrupted storage — keep defaults */ }

watch([enabled, cogsMethod], () => {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify({ enabled: enabled.value, cogsMethod: cogsMethod.value }));
  } catch (e) { /* storage full/blocked — the builder still works for this session */ }
}, { deep: true });

const isEnabled = id => enabled.value.includes(id);
function toggle(id) {
  enabled.value = isEnabled(id) ? enabled.value.filter(x => x !== id) : [...enabled.value, id];
}
function resetBuilder() {
  enabled.value = [...DEFAULT_ENABLED];
  cogsMethod.value = 'fifo';
}

// Default set (sales + service − COGS FIFO − expenses) reproduces the standard ProfitNet (FIFO).
const components = computed(() => [
  { id: 'sales', sign: 1, label: t('Gross_Sales'), value: n(infos.value.sales_sum) },
  { id: 'service', sign: 1, label: t('Service_Profit'), value: n(infos.value.service_profit) },
  { id: 'cogs', sign: -1, label: t('COGS'), value: cogsMethod.value === 'fifo' ? n(infos.value.product_cost_fifo) : n(infos.value.averagecost) },
  { id: 'expenses', sign: -1, label: t('Expenses'), value: n(infos.value.expenses_sum) },
  { id: 'sale_returns', sign: -1, label: t('SalesReturn'), value: n(infos.value.returns_sales_sum) },
  { id: 'purchase_returns', sign: 1, label: t('PurchasesReturn'), value: n(infos.value.returns_purchases_sum) },
  { id: 'sales_tax', sign: -1, label: t('Taxes_Collected'), value: n(infos.value.sales_tax_sum) },
]);

const enabledComponents = computed(() => components.value.filter(c => isEnabled(c.id)));
const customProfit = computed(() => enabledComponents.value.reduce((s, c) => s + c.sign * c.value, 0));
const formulaText = computed(() =>
  enabledComponents.value
    .map((c, i) => (i === 0 && c.sign > 0 ? c.label : `${c.sign > 0 ? '+' : '−'} ${c.label}`))
    .join(' ') || t('NodataAvailable')
);

/* ---------------------------- income statement ---------------------------- */
const stmt = computed(() => {
  const s = infos.value;
  const grossSales = n(s.sales_sum);
  const serviceRev = n(s.service_revenue_sum);
  const saleRet = n(s.returns_sales_sum);
  const netRevenue = grossSales + serviceRev - saleRet;
  const cogs = cogsMethod.value === 'fifo' ? n(s.product_cost_fifo) : n(s.averagecost);
  const parts = n(s.service_parts_cost);
  const grossProfit = netRevenue - cogs - parts;
  const expenses = n(s.expenses_sum);
  const netProfit = grossProfit - expenses;
  return {
    grossSales, serviceRev, saleRet, netRevenue, cogs, parts, grossProfit, expenses, netProfit,
    grossMargin: netRevenue > 0 ? (grossProfit / netRevenue) * 100 : 0,
    netMargin: netRevenue > 0 ? (netProfit / netRevenue) * 100 : 0,
  };
});

const expenseCategories = computed(() => infos.value.expenses_by_category || []);

const infoRows = computed(() => [
  { label: t('Taxes_Collected'), value: infos.value.sales_tax_sum },
  { label: t('Taxes_Paid'), value: infos.value.purchases_tax_sum },
  { label: t('Discounts_Given'), value: infos.value.sales_discount_sum },
  { label: t('Discounts_Received'), value: infos.value.purchases_discount_sum },
  { label: t('Shipping_Charged'), value: infos.value.sales_shipping_sum },
  { label: t('Shipping_Paid'), value: infos.value.purchases_shipping_sum },
  { label: t('PurchasesReturn'), value: infos.value.returns_purchases_sum },
  { label: `${t('Service_Jobs')} – ${t('PaiementsReceived')}`, value: infos.value.paiement_service_jobs },
]);

/* --------------------------------- tiles ---------------------------------- */
const docTiles = computed(() => [
  { label: t('Sales'), count: infos.value.sales_count, sum: infos.value.sales_sum, icon: ShoppingCartOutlined, color: '#1677ff', bg: 'rgba(22, 119, 255, 0.12)' },
  { label: t('Purchases'), count: infos.value.purchases_count, sum: infos.value.purchases_sum, icon: ShoppingOutlined, color: '#6d28d9', bg: 'rgba(109, 40, 217, 0.12)' },
  { label: t('SalesReturn'), count: infos.value.returns_sales_count, sum: infos.value.returns_sales_sum, icon: RollbackOutlined, color: '#d48806', bg: 'rgba(212, 136, 6, 0.14)' },
  { label: t('PurchasesReturn'), count: infos.value.returns_purchases_count, sum: infos.value.returns_purchases_sum, icon: UndoOutlined, color: '#eb2f96', bg: 'rgba(235, 47, 150, 0.12)' },
  // Repair work is counted on delivery, when its parts leave stock.
  { label: t('Service_Jobs'), count: infos.value.service_jobs_count, sum: infos.value.service_revenue_sum, icon: ToolOutlined, color: '#13c2c2', bg: 'rgba(19, 194, 194, 0.12)' },
]);

const flowTiles = computed(() => [
  { label: t('Revenue'), value: infos.value.total_revenue, icon: DollarOutlined, color: '#1677ff', bg: 'rgba(22, 119, 255, 0.12)' },
  { label: t('PaiementsReceived'), value: infos.value.payment_received, icon: WalletOutlined, color: '#52c41a', bg: 'rgba(82, 196, 26, 0.14)', valueColor: '#52c41a' },
  { label: t('PaiementsSent'), value: infos.value.payment_sent, icon: CreditCardOutlined, color: '#ff4d4f', bg: 'rgba(255, 77, 79, 0.12)', valueColor: '#ff4d4f' },
  // Opening-balance settlements: already inside PaiementsReceived / PaiementsSent,
  // broken out here so old-debt collection is visible next to regular payments.
  { label: t('OpeningBalanceReceived'), value: infos.value.paiement_client_opening, icon: WalletOutlined, color: '#52c41a', bg: 'rgba(82, 196, 26, 0.14)', valueColor: '#52c41a' },
  { label: t('OpeningBalancePaid'), value: infos.value.paiement_provider_opening, icon: CreditCardOutlined, color: '#ff4d4f', bg: 'rgba(255, 77, 79, 0.12)', valueColor: '#ff4d4f' },
  { label: t('Expenses'), value: infos.value.expenses_sum, icon: FallOutlined, color: '#ff4d4f', bg: 'rgba(255, 77, 79, 0.12)', valueColor: '#ff4d4f' },
  { label: `${t('Service_Jobs')} – ${t('Product_Cost')}`, value: infos.value.service_parts_cost, icon: InboxOutlined, color: '#6d28d9', bg: 'rgba(109, 40, 217, 0.12)' },
  { label: `${t('Service_Jobs')} – ${t('ProfitNet')}`, value: infos.value.service_profit, icon: ToolOutlined, color: '#13c2c2', bg: 'rgba(19, 194, 194, 0.12)', valueColor: Number(infos.value.service_profit) >= 0 ? '#52c41a' : '#ff4d4f' },
  {
    label: t('PaiementsNet'), value: infos.value.paiement_net, icon: FundOutlined, wide: true,
    color: '#6d28d9', bg: 'rgba(109, 40, 217, 0.12)',
    valueColor: Number(infos.value.paiement_net) >= 0 ? '#52c41a' : '#ff4d4f',
  },
]);

const profitCards = computed(() => [
  { label: t('ProfitNet'), value: infos.value.profit_fifo, sub: 'FIFO' },
  { label: t('ProfitNet'), value: infos.value.profit_average_cost, sub: t('AverageCost') },
]);

/* --------------------------------- charts --------------------------------- */
// Categorical palette (Sales / Purchases / Returns / Expenses), validated with the
// dataviz six-checks script for each mode's surface. Slot hues match across modes.
const chartPalette = computed(() => (ui.dark
  ? ['#1677ff', '#eb2f96', '#c8830a', '#0f9e9e']
  : ['#1677ff', '#c41d7f', '#d48806', '#0d9488']));

// Remount apexcharts (it manages its own DOM) whenever the figures change.
const chartKey = computed(() =>
  [infos.value.total_revenue, infos.value.expenses_sum, infos.value.profit_fifo,
    infos.value.sales_sum, infos.value.purchases_sum, timeseries.value.length,
    ui.dark ? 1 : 0].map(n).join('_')
);

// Daily trend — all money series on one axis, datetime x so gaps stay honest.
const trendChart = computed(() => {
  const pts = key => timeseries.value.map(r => [new Date(`${r.date}T00:00:00`).getTime(), n(r[key])]);
  return {
    series: [
      { name: t('Sales'), data: pts('sales') },
      { name: t('Purchases'), data: pts('purchases') },
      { name: t('SalesReturn'), data: pts('returns') },
      { name: t('Expenses'), data: pts('expenses') },
    ],
    options: {
      chart: { type: 'line', background: 'transparent', toolbar: { show: false }, zoom: { enabled: false } },
      theme: { mode: ui.dark ? 'dark' : 'light' },
      colors: chartPalette.value,
      stroke: { width: 2, curve: 'straight' },
      legend: { position: 'bottom' },
      dataLabels: { enabled: false },
      grid: { borderColor: ui.dark ? 'rgba(255,255,255,0.12)' : 'rgba(0,0,0,0.06)' },
      xaxis: { type: 'datetime', labels: { datetimeUTC: false } },
      yaxis: { labels: { formatter: v => money(v) } },
      tooltip: { shared: true, x: { format: 'dd MMM yyyy' }, y: { formatter: v => money(v) } },
    },
  };
});

// Financial overview — a colour-per-bar column chart of the headline figures.
const barChart = computed(() => ({
  series: [{
    name: t('Total'),
    data: [
      n(infos.value.total_revenue),
      n(infos.value.payment_received),
      n(infos.value.payment_sent),
      n(infos.value.expenses_sum),
      n(infos.value.profit_fifo),
    ],
  }],
  options: {
    chart: { type: 'bar', background: 'transparent', toolbar: { show: false } },
    theme: { mode: ui.dark ? 'dark' : 'light' },
    colors: ['#1677ff', '#52c41a', '#ff4d4f', '#d48806', '#6d28d9'],
    plotOptions: { bar: { distributed: true, borderRadius: 6, columnWidth: '55%' } },
    dataLabels: { enabled: true, formatter: v => money(v) },
    legend: { show: false },
    grid: { borderColor: ui.dark ? 'rgba(255,255,255,0.12)' : 'rgba(0,0,0,0.06)' },
    xaxis: {
      categories: [
        t('Revenue'), t('PaiementsReceived'), t('PaiementsSent'), t('Expenses'), `${t('ProfitNet')} (FIFO)`,
      ],
      labels: { style: { fontSize: '11px' }, trim: true, hideOverlappingLabels: false, rotate: -12 },
    },
    yaxis: { labels: { formatter: v => money(v) } },
    tooltip: { y: { formatter: v => money(v) } },
  },
}));

// Documents — proportional donut of the four document totals.
const docTotal = computed(() =>
  n(infos.value.sales_sum) + n(infos.value.purchases_sum) +
  n(infos.value.returns_sales_sum) + n(infos.value.returns_purchases_sum)
);
const donutChart = computed(() => ({
  series: [
    n(infos.value.sales_sum), n(infos.value.purchases_sum),
    n(infos.value.returns_sales_sum), n(infos.value.returns_purchases_sum),
  ],
  options: {
    chart: { type: 'donut', background: 'transparent' },
    theme: { mode: ui.dark ? 'dark' : 'light' },
    labels: [t('Sales'), t('Purchases'), t('SalesReturn'), t('PurchasesReturn')],
    colors: chartPalette.value,
    stroke: { width: 2, colors: [ui.dark ? '#141414' : '#ffffff'] },
    legend: { position: 'bottom' },
    dataLabels: { enabled: true, formatter: v => `${Number(v).toFixed(1)}%` },
    plotOptions: { pie: { donut: { size: '62%' } } },
    tooltip: { y: { formatter: v => money(v) } },
  },
}));

// Expenses by category — magnitude by bar length, one hue; all categories shown.
const expenseChartHeight = computed(() => Math.max(220, expenseCategories.value.length * 34 + 60));
const expenseChart = computed(() => ({
  series: [{ name: t('Expenses'), data: expenseCategories.value.map(c => n(c.amount)) }],
  options: {
    chart: { type: 'bar', background: 'transparent', toolbar: { show: false } },
    theme: { mode: ui.dark ? 'dark' : 'light' },
    colors: ['#1677ff'],
    plotOptions: { bar: { horizontal: true, borderRadius: 4, barHeight: '55%' } },
    dataLabels: { enabled: false },
    grid: { borderColor: ui.dark ? 'rgba(255,255,255,0.12)' : 'rgba(0,0,0,0.06)' },
    xaxis: { categories: expenseCategories.value.map(c => c.name), labels: { formatter: v => money(v) } },
    tooltip: { y: { formatter: v => money(v) } },
  },
}));

/* --------------------------------- export --------------------------------- */
const exportColumns = () => [
  { title: t('Description'), dataIndex: 'label', key: 'label' },
  { title: t('Amount'), dataIndex: 'value', key: 'value' },
];

// One row set feeds Excel, PDF, and Print, so the three outputs always agree.
function buildExportRows() {
  const s = stmt.value;
  return [
    { label: `— ${t('Income_Statement')} —`, value: '' },
    { label: t('Gross_Sales'), value: money(s.grossSales) },
    { label: t('Service_Revenue'), value: money(s.serviceRev) },
    { label: t('SalesReturn'), value: `- ${money(s.saleRet)}` },
    { label: t('Net_Revenue'), value: money(s.netRevenue) },
    { label: `${t('COGS')} (${cogsMethod.value === 'fifo' ? 'FIFO' : t('AverageCost')})`, value: `- ${money(s.cogs)}` },
    { label: t('Service_Parts_Cost'), value: `- ${money(s.parts)}` },
    { label: `${t('Gross_Profit')} (${pct(s.grossMargin)})`, value: money(s.grossProfit) },
    ...expenseCategories.value.map(c => ({ label: `${t('Expenses')}: ${c.name}`, value: `- ${money(c.amount)}` })),
    { label: t('Expenses'), value: `- ${money(s.expenses)}` },
    { label: `${t('ProfitNet')} (${pct(s.netMargin)})`, value: money(s.netProfit) },
    { label: '', value: '' },
    { label: `— ${t('Custom_Profit')} —`, value: '' },
    ...enabledComponents.value.map(c => ({ label: c.label, value: `${c.sign > 0 ? '+' : '-'} ${money(c.value)}` })),
    { label: t('Custom_Profit'), value: money(customProfit.value) },
    { label: '', value: '' },
    { label: `— ${t('Documents')} —`, value: '' },
    ...docTiles.value.map(x => ({ label: `${x.label} (${num(x.count)})`, value: money(x.sum) })),
    { label: '', value: '' },
    { label: `— ${t('cash_flow')} —`, value: '' },
    // flowTiles already includes Payments Net (the `wide` tile).
    ...flowTiles.value.map(x => ({ label: x.label, value: money(x.value) })),
    { label: '', value: '' },
    { label: `— ${t('Additional_Information')} —`, value: '' },
    ...infoRows.value.map(x => ({ label: x.label, value: money(x.value) })),
    { label: `${t('ProfitNet')} (FIFO)`, value: money(infos.value.profit_fifo) },
    { label: `${t('ProfitNet')} (${t('AverageCost')})`, value: money(infos.value.profit_average_cost) },
  ];
}

// "Period · warehouse" context line: printRows shows it as a subtitle; the PDF
// path has no subtitle slot, so it goes in as the first table row instead.
function periodLine() {
  const from = range.value?.[0]?.format?.('YYYY-MM-DD') || '';
  const to = range.value?.[1]?.format?.('YYYY-MM-DD') || '';
  const wh = warehouses.value.find(w => w.id === warehouseId.value)?.name;
  return `${from} → ${to}${wh ? ` · ${wh}` : ''}`;
}

async function doExport() {
  exporting.value = true;
  try {
    await exportExcel(t('ProfitandLoss'), exportColumns(), buildExportRows());
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    exporting.value = false;
  }
}

async function doExportPdf() {
  exportingPdf.value = true;
  try {
    const rows = [{ label: t('Period'), value: periodLine() }, ...buildExportRows()];
    await exportPdf(t('ProfitandLoss'), exportColumns(), rows, { landscape: false });
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    exportingPdf.value = false;
  }
}

function doPrint() {
  printRows(t('ProfitandLoss'), exportColumns(), buildExportRows(), {
    subtitle: [periodLine()],
    landscape: false,
  });
}

onMounted(load);
</script>

<style scoped>
/* ---------------- Net-profit hero ---------------- */
.hero-card {
  display: flex;
  align-items: center;
  gap: 18px;
  padding: 22px 24px;
  border-radius: 14px;
  color: #fff;
  min-height: 108px;
  height: 100%;
}
.hero-pos {
  background: linear-gradient(135deg, #16a34a, #22c55e);
  box-shadow: 0 10px 28px rgba(22, 163, 74, 0.28);
}
.hero-neg {
  background: linear-gradient(135deg, #dc2626, #f97316);
  box-shadow: 0 10px 28px rgba(220, 38, 38, 0.28);
}
.hero-custom {
  background: linear-gradient(135deg, #4c1d95, #6d28d9);
  box-shadow: 0 10px 28px rgba(109, 40, 217, 0.3);
}
.hero-icon {
  width: 56px;
  height: 56px;
  border-radius: 14px;
  background: rgba(255, 255, 255, 0.18);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 28px;
  flex: none;
}
.hero-meta {
  min-width: 0;
}
.hero-label {
  font-size: 14px;
  opacity: 0.92;
}
.hero-value {
  font-size: 30px;
  font-weight: 800;
  line-height: 1.15;
  white-space: nowrap;
}
.hero-sub {
  font-size: 12px;
  opacity: 0.8;
  text-transform: uppercase;
  letter-spacing: 0.6px;
}
.hero-formula {
  text-transform: none;
  letter-spacing: 0;
  white-space: normal;
  line-height: 1.4;
}

/* ---------------- Profit customizer ---------------- */
.customizer-hint {
  opacity: 0.65;
  font-size: 13px;
  margin-bottom: 14px;
}
.comp-row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 10px;
  border-radius: 8px;
  transition: opacity 0.2s;
}
.comp-row:hover {
  background: rgba(109, 40, 217, 0.06);
}
.comp-row--off {
  opacity: 0.45;
}
.sign-tag {
  width: 26px;
  text-align: center;
  font-weight: 700;
  margin: 0;
  flex: none;
}
.comp-label {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.cogs-radio {
  flex: none;
}
.comp-value {
  font-weight: 600;
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
}
.builder-panel {
  border: 1px dashed rgba(109, 40, 217, 0.4);
  border-radius: 10px;
  padding: 14px 16px;
  height: 100%;
}
.builder-title {
  font-size: 12px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  opacity: 0.65;
  margin-bottom: 10px;
}
.builder-line {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  padding: 3px 0;
  font-size: 13px;
}
.builder-total {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  font-size: 16px;
  font-weight: 800;
}

/* ---------------- Income statement ---------------- */
.statement {
  max-width: 860px;
  margin: 0 auto;
}
.st-section {
  font-size: 12px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  opacity: 0.6;
  margin: 18px 0 6px;
  padding-bottom: 4px;
  border-bottom: 1px solid rgba(128, 128, 128, 0.2);
}
.st-section:first-child {
  margin-top: 0;
}
.st-row {
  display: flex;
  justify-content: space-between;
  gap: 16px;
  padding: 5px 0;
  font-size: 14px;
  font-variant-numeric: tabular-nums;
}
.st-sub {
  padding-inline-start: 18px;
  opacity: 0.75;
  font-size: 13px;
}
.st-total {
  font-weight: 700;
  border-top: 1px solid rgba(128, 128, 128, 0.25);
  margin-top: 4px;
  padding-top: 8px;
}
.st-grand {
  font-size: 17px;
  font-weight: 800;
  border-radius: 10px;
  padding: 12px 16px;
  margin-top: 16px;
}
.st-grand--pos {
  background: rgba(82, 196, 26, 0.12);
  color: #389e0d;
}
.st-grand--neg {
  background: rgba(255, 77, 79, 0.12);
  color: #cf1322;
}
.st-info {
  opacity: 0.8;
  font-size: 13px;
}
.pct {
  font-size: 12px;
  font-weight: 600;
  opacity: 0.65;
  margin-inline-start: 6px;
}
.pos {
  color: #389e0d;
}
.neg {
  color: #cf1322;
}

/* ---------------- Section titles ---------------- */
.section-title {
  font-size: 13px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  opacity: 0.65;
  margin: 24px 0 12px;
}

/* ---------------- Stat cards ---------------- */
.stat-card {
  border-radius: 10px;
  height: 100%;
}
.stat-card--accent {
  border: 1px solid rgba(109, 40, 217, 0.35);
}
.stat-inner {
  display: flex;
  align-items: center;
  gap: 12px;
}
.stat-icon {
  width: 44px;
  height: 44px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  flex: none;
}
.stat-meta {
  min-width: 0;
}
.stat-label {
  opacity: 0.65;
  font-size: 13px;
  margin-bottom: 2px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.count-tag {
  margin-inline-start: 4px;
  font-size: 11px;
  line-height: 16px;
  padding: 0 5px;
}
.stat-value {
  font-size: 20px;
  font-weight: 700;
  white-space: nowrap;
}
@media (max-width: 575px) {
  .hero-value {
    font-size: 24px;
  }
  .stat-icon {
    width: 36px;
    height: 36px;
    font-size: 16px;
  }
  .stat-value {
    font-size: 16px;
  }
}
</style>
