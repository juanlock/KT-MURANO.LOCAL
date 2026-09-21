<template>
  <div class="page">
    <!-- ================= Header ================= -->
    <div class="page-header">
      <div>
        <a-typography-title :level="3" style="margin: 0; font-size: 22px">{{ greeting }}</a-typography-title>
        <a-typography-text type="secondary">{{ subtitle }}</a-typography-text>
      </div>

      <a-space v-if="!loading" wrap class="dash-filters">
        <a-segmented v-model:value="period" :options="periodOptions" @change="onPeriodChange" />
        <a-range-picker
          v-if="period === 'custom'"
          v-model:value="customRange"
          value-format="YYYY-MM-DD"
          :allow-clear="false"
          @change="load()"
        />
        <a-select
          v-model:value="warehouseId"
          class="wh-select"
          :options="warehouseOptions"
          @change="load()"
        />
        <!-- Multi-Currency: view the base-currency figures converted -->
        <ViewCurrencySelect class="wh-select" />
        <a-button type="primary" :loading="loading" @click="load()">
          <template #icon><ReloadOutlined /></template>
          {{ $t('Refresh') }}
        </a-button>
      </a-space>
    </div>

    <!-- Demo-data notice -->
    <a-alert
      v-if="demoMode && !loading"
      type="warning"
      show-icon
      style="margin-bottom: 24px"
      :message="$t('Demo_Data_Notice')"
      :description="$t('Demo_Data_Notice_Help')"
    />

    <!-- Full-page loader; content mounts only when data is ready so charts
         never render (and self-mutate their DOM) with empty options. -->
    <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <template v-else>
      <!-- ================= Stat cards (legacy's two rows of four) ========= -->
      <a-row :gutter="[16, 16]">
        <a-col v-for="card in statCards" :key="card.label" :xs="12" :sm="12" :md="8" :lg="6">
          <a-card :hoverable="true" class="stat-card" @click="card.link && $router.push(card.link)">
            <div class="stat-inner">
              <span class="stat-icon" :style="{ background: card.tint }">
                <component :is="card.icon" />
              </span>
              <div>
                <div class="stat-label">{{ card.label }}</div>
                <div class="stat-value">{{ card.money ? money(card.value) : card.value }}</div>
              </div>
            </div>
          </a-card>
        </a-col>
      </a-row>

      <!-- ================= Sales/Purchases + Top selling ================= -->
      <a-row :gutter="[16, 16]" style="margin-top: 16px">
        <a-col :xs="24" :xl="16">
          <a-card class="chart-card" :title="$t('Sales_And_Purchases')">
            <apexchart v-if="salesChart.series.length" :key="'bar-' + loadCount" type="bar" height="320" :options="salesChart.options" :series="salesChart.series" />
            <a-empty v-else :description="$t('No_data_available')" style="padding: 48px 0" />
          </a-card>
        </a-col>
        <a-col :xs="24" :xl="8">
          <a-card class="chart-card" :title="$t('Top_Selling_Products')">
            <apexchart v-if="donutChart.series.length" :key="'donut-' + loadCount" type="donut" height="320" :options="donutChart.options" :series="donutChart.series" />
            <a-empty v-else :description="$t('No_data_available')" style="padding: 48px 0" />
          </a-card>
        </a-col>
      </a-row>

      <!-- ================= Payment area + Top customers ================= -->
      <a-row :gutter="[16, 16]" style="margin-top: 16px">
        <a-col :xs="24" :xl="12">
          <a-card class="chart-card" :title="$t('Payment_Sent_Received')">
            <apexchart v-if="paymentChart.series.length" :key="'area-' + loadCount" type="area" height="300" :options="paymentChart.options" :series="paymentChart.series" />
            <a-empty v-else :description="$t('No_data_available')" style="padding: 48px 0" />
          </a-card>
        </a-col>
        <a-col :xs="24" :xl="12">
          <a-card class="chart-card" :title="$t('Top_Customers')">
            <apexchart v-if="customerChart.series.length" :key="'pie-' + loadCount" type="pie" height="300" :options="customerChart.options" :series="customerChart.series" />
            <a-empty v-else :description="$t('No_data_available')" style="padding: 48px 0" />
          </a-card>
        </a-col>
      </a-row>

      <!-- ================= Sales by payment + Stock value ================= -->
      <!-- align=stretch so the right column matches the taller left card; the
           Quick actions card then grows to fill any leftover height. -->
      <a-row :gutter="[16, 16]" align="stretch" style="margin-top: 16px">
        <a-col :xs="24" :lg="12">
          <a-card class="fill-card" :title="$t('Sales_by_Payment')">
            <div class="paylist">
              <div v-for="p in payments" :key="p.name" class="pay-row">
                <span class="pay-icon" :style="{ background: p.color }">
                  <component :is="payIcon(p.name)" />
                </span>
                <div class="pay-main">
                  <div class="pay-top">
                    <span class="pay-name">{{ p.name }}</span>
                    <span class="pay-amount">{{ money(p.amount) }}</span>
                  </div>
                  <div class="pay-track">
                    <span class="pay-fill" :style="{ width: Math.min(p.percentage, 100) + '%', background: p.color }"></span>
                  </div>
                </div>
                <span class="pay-pct">{{ Math.round(p.percentage) }}%</span>
              </div>
            </div>
            <a-empty v-if="!payments.length" :description="$t('No_data_available')" />
          </a-card>
        </a-col>
        <a-col :xs="24" :lg="12" class="dash-right">
          <a-card :title="$t('Stock_Value')">
            <template #extra>
              <a-tooltip :title="$t('Stock_Valuation_Hint')">
                <InfoCircleOutlined style="color: rgba(0,0,0,0.35)" />
              </a-tooltip>
            </template>
            <div class="stockval">
              <div
                v-for="s in stockItems" :key="s.label"
                class="stockval-row" :style="{ background: s.tint }"
              >
                <span class="stockval-icon" :style="{ background: s.color }">
                  <component :is="s.icon" />
                </span>
                <div class="stockval-text">
                  <div class="stockval-label">{{ s.label }}</div>
                  <div class="stockval-value" :style="{ color: s.color }">{{ money(s.value) }}</div>
                </div>
                <span class="stockval-bar" :style="{ background: s.color, width: s.width }"></span>
              </div>
            </div>
          </a-card>

          <!-- Quick actions — grows to fill the space under Stock value so the
               column bottom lines up with Sales by payment. Desktop take on the
               legacy dashboard's quick-module shortcuts. -->
          <a-card v-if="quickActions.length" :title="$t('Quick_Actions')" class="qa-card">
            <div class="qa-grid">
              <button
                v-for="a in quickActions" :key="a.label"
                class="qa-tile" @click="$router.push(a.to)"
              >
                <span class="qa-icon" :style="{ color: a.color, background: a.tint }">
                  <component :is="a.icon" />
                </span>
                <span class="qa-label">{{ a.label }}</span>
              </button>
            </div>
          </a-card>
        </a-col>
      </a-row>

      <!-- ================= Stock alert + Top selling ================= -->
      <a-row :gutter="[16, 16]" style="margin-top: 16px">
        <a-col :xs="24" :xl="12">
          <a-card :title="$t('StockAlert')">
            <template #extra>
              <a-button type="link" @click="$router.push('/products')">{{ $t('View_all') }}</a-button>
            </template>
            <div v-if="stockAlerts.length" class="alertlist">
              <div v-for="a in alertItems" :key="a.code" class="alert-row">
                <span class="alert-icon"><WarningOutlined /></span>
                <div class="alert-main">
                  <div class="alert-top">
                    <span class="alert-name">{{ a.name }}</span>
                    <span class="alert-qty">{{ a.quantity }} / {{ a.stock_alert }}</span>
                  </div>
                  <div class="alert-meta">
                    <span>{{ a.code }}</span>
                    <span v-if="a.warehouse" class="alert-wh"><ShopOutlined /> {{ a.warehouse }}</span>
                  </div>
                  <div class="alert-track">
                    <span class="alert-fill" :style="{ width: a.width, background: a.color }"></span>
                  </div>
                </div>
              </div>
            </div>
            <a-empty v-else :description="$t('No_data_available')" />
          </a-card>
        </a-col>
        <a-col :xs="24" :xl="12">
          <a-card :title="$t('Top_Selling_Products')">
            <div v-if="topProducts.length" class="toplist">
              <div v-for="(p, i) in topItems" :key="i" class="top-row">
                <span class="top-rank" :class="'rank-' + (i + 1)">{{ i + 1 }}</span>
                <div class="top-main">
                  <div class="top-name">{{ p.name }}</div>
                  <div class="top-track">
                    <span class="top-fill" :style="{ width: p.width }"></span>
                  </div>
                </div>
                <div class="top-figures">
                  <div class="top-amount">{{ money(p.total) }}</div>
                  <div class="top-sales">{{ p.total_sales }} {{ $t('Sold') }}</div>
                </div>
              </div>
            </div>
            <a-empty v-else :description="$t('No_data_available')" />
          </a-card>
        </a-col>
      </a-row>

      <!-- ================= Recent sales ================= -->
      <a-card style="margin-top: 16px" :title="$t('Recent_Sales')">
        <template #extra>
          <a-button type="link" @click="$router.push('/sales')">{{ $t('View_all') }}</a-button>
        </template>
        <a-table
          :columns="columns"
          :data-source="sales"
          row-key="Ref"
          size="middle"
          :pagination="{ pageSize: 5, showSizeChanger: false }"
          :scroll="{ x: 'max-content' }"
        >
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'statut'">
              <a-tag :color="tagColor(record.statut)">{{ record.statut }}</a-tag>
            </template>
            <template v-else-if="column.key === 'payment_status'">
              <a-tag :color="tagColor(record.payment_status)">{{ record.payment_status }}</a-tag>
            </template>
            <template v-else-if="MONEY_COLS.includes(column.key)">
              {{ money(record[column.key]) }}
            </template>
          </template>
        </a-table>
      </a-card>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import {
  ReloadOutlined, ShoppingCartOutlined, ShoppingOutlined, RollbackOutlined,
  UndoOutlined, DollarOutlined, WalletOutlined, FileTextOutlined, RiseOutlined,
  TagOutlined, AppstoreOutlined, InfoCircleOutlined,
  CreditCardOutlined, BankOutlined, WarningOutlined, ShopOutlined,
  CalculatorOutlined, TeamOutlined,
} from '@ant-design/icons-vue';
import { useI18n } from 'vue-i18n';
import http from '../lib/http';
import { useAuthStore } from '../stores/auth';
import { useFormat } from '../composables/useFormat';
import ViewCurrencySelect from '../components/ViewCurrencySelect.vue';

const { t } = useI18n();
const auth = useAuthStore();
// Currency/precision/separators come from the user's settings, like the legacy
// app. money() follows the shared Multi-Currency display selector
// (ViewCurrencySelect) automatically.
const { money } = useFormat();

/* ------------------------------------------------------------------ state */
const loading = ref(true);
const loadCount = ref(0); // bumps per fetch; keys charts so they remount cleanly
const demoMode = ref(false);
const period = ref('7d');
const customRange = ref([]); // ['YYYY-MM-DD', 'YYYY-MM-DD'] when period === 'custom'
const warehouseId = ref('');
const warehouses = ref([]);
const report = ref({});
const payments = ref([]);
const stockValue = ref({ by_cost: 0, by_retail: 0, by_wholesale: 0 });
const sales = ref([]);
const stockAlerts = ref([]);
const topProducts = ref([]);
const salesChart = ref({ series: [], options: {} });
const donutChart = ref({ series: [], options: {} });
const paymentChart = ref({ series: [], options: {} });
const customerChart = ref({ series: [], options: {} });

// Computed so the labels follow a live locale switch (Settings saves one).
const periodOptions = computed(() => [
  { value: 'today', label: t('Today') },
  { value: '7d', label: '7D' },
  { value: '30d', label: '30D' },
  { value: 'mtd', label: 'MTD' },
  { value: 'ytd', label: 'YTD' },
  { value: 'custom', label: t('Custom') },
]);

// Time-of-day greeting: morning < 12:00, afternoon < 18:00, evening after.
const greeting = computed(() => {
  const h = new Date().getHours();
  const part = h < 12 ? t('Good_Morning') : h < 18 ? t('Good_Afternoon') : t('Good_Evening');
  return auth.username ? `${part}, ${auth.username}` : part;
});

const subtitle = computed(() =>
  demoMode.value ? t('Dashboard_Demo_Subtitle') : t('Dashboard_Live_Subtitle')
);

const warehouseOptions = computed(() => [
  { value: '', label: t('All_Warehouses') },
  ...warehouses.value.map(w => ({ value: w.id, label: w.name })),
]);

/* ------------------------------------------------------------ formatting */
function tagColor(status) {
  const s = String(status || '').toLowerCase();
  if (['completed', 'received', 'paid'].includes(s)) return 'success';
  if (['pending', 'partial'].includes(s)) return 'warning';
  if (['ordered', 'sent'].includes(s)) return 'processing';
  if (['cancelled', 'canceled', 'unpaid'].includes(s)) return 'error';
  return 'default';
}

const PAYMENT_COLORS = { orange: '#fa8c16', blue: '#1677ff', green: '#52c41a', grey: '#8c8c8c' };

/** An icon that fits the payment method name (cash / card / bank / cheque). */
function payIcon(name) {
  const s = String(name || '').toLowerCase();
  if (s.includes('card')) return CreditCardOutlined;
  if (s.includes('bank') || s.includes('transfer')) return BankOutlined;
  if (s.includes('cheque') || s.includes('check')) return FileTextOutlined;
  return WalletOutlined; // cash and anything else
}
const MONEY_COLS = ['GrandTotal', 'paid_amount', 'due'];

/* The two legacy stat-card rows (four each), in order. `money: false` for the
   plain invoice count. Each links to the matching list page. */
const statCards = computed(() => {
  const r = report.value;
  const n = k => Number(r[k]) || 0;
  return [
    { label: t('Sales'), value: n('today_sales'), money: true, icon: ShoppingCartOutlined, tint: 'rgba(109,40,217,0.12)', link: '/sales' },
    { label: t('Purchases'), value: n('today_purchases'), money: true, icon: ShoppingOutlined, tint: 'rgba(22,119,255,0.12)', link: '/purchases' },
    { label: t('SalesReturn'), value: n('return_sales'), money: true, icon: RollbackOutlined, tint: 'rgba(250,140,22,0.12)', link: '/sale-returns' },
    { label: t('PurchasesReturn'), value: n('return_purchases'), money: true, icon: UndoOutlined, tint: 'rgba(245,34,45,0.12)', link: '/purchase-returns' },
    { label: t('Sales_Due'), value: n('sales_due'), money: true, icon: DollarOutlined, tint: 'rgba(250,140,22,0.12)', link: '/sales' },
    { label: t('Purchase_Due'), value: n('purchase_due'), money: true, icon: WalletOutlined, tint: 'rgba(22,119,255,0.12)', link: '/purchases' },
    { label: t('Invoices'), value: n('today_invoices'), money: false, icon: FileTextOutlined, tint: 'rgba(140,140,140,0.14)', link: '/sales' },
    { label: t('Profit'), value: n('today_profit'), money: true, icon: RiseOutlined, tint: 'rgba(82,196,26,0.14)', link: '/reports/profit-and-loss' },
  ];
});

const stockItems = computed(() => {
  const v = stockValue.value;
  const rows = [
    { label: t('By_Cost'), value: Number(v.by_cost) || 0, icon: DollarOutlined, color: '#6d28d9', tint: 'rgba(109,40,217,0.06)' },
    { label: t('By_Retail'), value: Number(v.by_retail) || 0, icon: TagOutlined, color: '#1677ff', tint: 'rgba(22,119,255,0.06)' },
    { label: t('By_Wholesale'), value: Number(v.by_wholesale) || 0, icon: AppstoreOutlined, color: '#52c41a', tint: 'rgba(82,196,26,0.06)' },
  ];
  // Bar width is each value relative to the largest, so the three compare visually.
  const max = Math.max(1, ...rows.map(r => r.value));
  return rows.map(r => ({ ...r, width: `${Math.round((r.value / max) * 100)}%` }));
});

// Low-stock rows with a depletion bar (qty vs alert threshold) and a severity
// colour — the emptier, the redder.
const alertItems = computed(() =>
  stockAlerts.value.map(a => {
    const qty = Number(a.quantity) || 0;
    const threshold = Number(a.stock_alert) || 0;
    const ratio = threshold > 0 ? qty / threshold : 0;
    return {
      ...a,
      width: `${Math.min(Math.round(ratio * 100), 100)}%`,
      color: ratio <= 0.34 ? '#f5222d' : '#fa8c16',
    };
  })
);

// Quick actions — only those the user can perform (auth.can fail-opens in the
// offline demo, so all show there).
const quickActions = computed(() => [
  { label: t('Pos'), perm: 'Pos_view', to: '/pos', icon: CalculatorOutlined, color: '#6d28d9', tint: 'rgba(109,40,217,0.12)' },
  { label: t('New_Sale'), perm: 'Sales_add', to: '/sales/create', icon: ShoppingCartOutlined, color: '#1677ff', tint: 'rgba(22,119,255,0.12)' },
  { label: t('New_Purchase'), perm: 'Purchases_add', to: '/purchases/create', icon: ShoppingOutlined, color: '#fa8c16', tint: 'rgba(250,140,22,0.12)' },
  { label: t('Add_Product'), perm: 'products_add', to: '/products/create', icon: AppstoreOutlined, color: '#52c41a', tint: 'rgba(82,196,26,0.12)' },
  { label: t('Add_Customer'), perm: 'Customers_add', to: '/customers/create', icon: TeamOutlined, color: '#eb2f96', tint: 'rgba(235,47,150,0.12)' },
  { label: t('Reports'), perm: 'Reports_profit', to: '/reports/profit-and-loss', icon: RiseOutlined, color: '#13c2c2', tint: 'rgba(19,194,194,0.12)' },
].filter(a => auth.can(a.perm)));

// Top products with a bar scaled to the highest amount.
const topItems = computed(() => {
  const max = Math.max(1, ...topProducts.value.map(p => Number(p.total) || 0));
  return topProducts.value.map(p => ({
    ...p,
    width: `${Math.round(((Number(p.total) || 0) / max) * 100)}%`,
  }));
});

const columns = computed(() => [
  { title: t('Reference'), dataIndex: 'Ref', key: 'Ref' },
  { title: t('Customer'), dataIndex: 'client_name', key: 'client_name' },
  { title: t('Warehouse'), dataIndex: 'warehouse_name', key: 'warehouse_name' },
  { title: t('Status'), dataIndex: 'statut', key: 'statut' },
  { title: t('Total'), dataIndex: 'GrandTotal', key: 'GrandTotal', align: 'right' },
  { title: t('Paid'), dataIndex: 'paid_amount', key: 'paid_amount', align: 'right' },
  { title: t('Due'), dataIndex: 'due', key: 'due', align: 'right' },
  { title: t('Payment'), dataIndex: 'payment_status', key: 'payment_status' },
]);

/* ------------------------------------------------------------------ dates */
function fmt(d) {
  return d.toISOString().slice(0, 10);
}
function rangeFor(key) {
  if (key === 'custom' && customRange.value?.length === 2) {
    return { from: customRange.value[0], to: customRange.value[1] };
  }
  const end = new Date();
  const start = new Date();
  if (key === '7d') start.setDate(end.getDate() - 6);
  else if (key === '30d') start.setDate(end.getDate() - 29);
  else if (key === 'mtd') start.setDate(1);
  else if (key === 'ytd') { start.setMonth(0); start.setDate(1); }
  return { from: fmt(start), to: fmt(end) };
}

// Switching to Custom pre-fills the picker with the last 7 days so the
// dashboard always has a valid range to fetch with.
function onPeriodChange() {
  if (period.value === 'custom' && customRange.value?.length !== 2) {
    const end = new Date();
    const start = new Date();
    start.setDate(end.getDate() - 6);
    customRange.value = [fmt(start), fmt(end)];
  }
  load();
}

/* ------------------------------------------------------------------ charts */
// Neutral chart chrome that reads correctly on both light and dark surfaces.
const CHART_BASE = { background: 'transparent', foreColor: '#8c8c8c', fontFamily: 'inherit', toolbar: { show: false } };
const GRID = { borderColor: 'rgba(128,128,128,0.2)', strokeDashArray: 4 };

// Cohesive, modern categorical palette shared across every dashboard chart.
const PALETTE = ['#6366f1', '#22d3ee', '#f59e0b', '#ec4899', '#10b981', '#8b5cf6', '#0ea5e9', '#f43f5e'];

function buildCharts(days, salesData, purchasesData, products, customers, pay) {
  salesChart.value = {
    series: [
      { name: t('Sales'), data: salesData },
      { name: t('Purchases'), data: purchasesData },
    ],
    options: {
      chart: { ...CHART_BASE, type: 'bar' },
      colors: ['#6366f1', '#22d3ee'],
      plotOptions: { bar: { columnWidth: '45%', borderRadius: 5, borderRadiusApplication: 'end' } },
      fill: {
        type: 'gradient',
        gradient: { shade: 'light', type: 'vertical', shadeIntensity: 0.2, opacityFrom: 0.95, opacityTo: 0.8, stops: [0, 100] },
      },
      dataLabels: { enabled: false },
      legend: { labels: { colors: '#595959' } },
      xaxis: { categories: days, axisBorder: { show: false }, axisTicks: { show: false } },
      grid: GRID,
      tooltip: { theme: 'light' },
    },
  };
  donutChart.value = {
    series: products.map(p => p.value),
    options: {
      chart: { ...CHART_BASE, type: 'donut' },
      labels: products.map(p => p.name),
      colors: PALETTE,
      stroke: { colors: ['#ffffff'], width: 3 },
      legend: { position: 'bottom', labels: { colors: '#595959' } },
      dataLabels: { enabled: false },
      plotOptions: { pie: { donut: { size: '72%' } } },
      tooltip: { theme: 'light' },
    },
  };
  // Payment sent / received — smooth area, red vs green like legacy.
  paymentChart.value = {
    series: [
      { name: t('Sent'), data: pay.sent },
      { name: t('Received'), data: pay.received },
    ],
    options: {
      chart: { ...CHART_BASE, type: 'area' },
      colors: ['#f43f5e', '#10b981'],
      stroke: { curve: 'smooth', width: 2.5 },
      fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.4, opacityTo: 0.04, stops: [0, 95] } },
      dataLabels: { enabled: false },
      legend: { labels: { colors: '#595959' } },
      xaxis: { categories: pay.days, axisBorder: { show: false }, axisTicks: { show: false } },
      grid: GRID,
      tooltip: { theme: 'light' },
    },
  };
  // Top customers — pie, purple family.
  customerChart.value = {
    series: customers.map(c => c.value),
    options: {
      chart: { ...CHART_BASE, type: 'pie' },
      labels: customers.map(c => c.name),
      colors: PALETTE,
      stroke: { colors: ['#ffffff'], width: 3 },
      legend: { position: 'bottom', labels: { colors: '#595959' } },
      dataLabels: { enabled: true, formatter: v => `${Math.floor(v)}%` },
      tooltip: { theme: 'light' },
    },
  };
}

/* ------------------------------------------------------------------ data */
const DEMO = {
  report: {
    today_sales: 48290, today_purchases: 21400, return_sales: 1240, return_purchases: 860,
    sales_due: 6120, purchase_due: 3400, today_invoices: 37, today_profit: 17830,
  },
  payments: [
    { name: 'Cash', amount: 21730, percentage: 45, color: 'orange' },
    { name: 'Card', amount: 15450, percentage: 32, color: 'blue' },
    { name: 'Bank transfer', amount: 8210, percentage: 17, color: 'green' },
    { name: 'Cheque', amount: 2900, percentage: 6, color: 'grey' },
  ],
  stock: { by_cost: 128400, by_retail: 186900, by_wholesale: 152300 },
  days: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
  sales: [4200, 5800, 4700, 6300, 7100, 8900, 5400],
  purchases: [2100, 3200, 2600, 3900, 3300, 4100, 2800],
  paymentSent: [1800, 2400, 2000, 3100, 2700, 3300, 2500],
  paymentReceived: [3900, 5100, 4300, 5900, 6200, 7800, 4900],
  products: [
    { name: 'Wireless Earbuds Pro', value: 38 },
    { name: 'Smart Watch S9', value: 26 },
    { name: 'Organic Coffee 1kg', value: 18 },
    { name: 'Yoga Mat Premium', value: 11 },
    { name: 'Other', value: 7 },
  ],
  customers: [
    { name: 'Sarah Miller', value: 32 },
    { name: 'Omar Khalil', value: 24 },
    { name: 'Lina Berrada', value: 19 },
    { name: 'James Chen', value: 15 },
    { name: 'Other', value: 10 },
  ],
  stockAlerts: [
    { code: 'PRD-1042', name: 'Wireless Earbuds Pro', warehouse: 'Main', quantity: 3, stock_alert: 10 },
    { code: 'PRD-0917', name: 'Organic Coffee 1kg', warehouse: 'Depot 2', quantity: 5, stock_alert: 15 },
    { code: 'PRD-0733', name: 'Yoga Mat Premium', warehouse: 'Main', quantity: 2, stock_alert: 8 },
  ],
  topProducts: [
    { name: 'Wireless Earbuds Pro', total_sales: 142, total: 40280 },
    { name: 'Smart Watch S9', total_sales: 98, total: 29400 },
    { name: 'Organic Coffee 1kg', total_sales: 76, total: 6080 },
    { name: 'Yoga Mat Premium', total_sales: 54, total: 4320 },
  ],
  lastSales: [
    { Ref: 'SL-2941', client_name: 'Sarah Miller', warehouse_name: 'Main', statut: 'completed', GrandTotal: 284, paid_amount: 284, due: 0, payment_status: 'paid' },
    { Ref: 'SL-2940', client_name: 'Omar Khalil', warehouse_name: 'Main', statut: 'pending', GrandTotal: 1120.5, paid_amount: 800.5, due: 320, payment_status: 'partial' },
    { Ref: 'SL-2939', client_name: 'Lina Berrada', warehouse_name: 'Depot 2', statut: 'completed', GrandTotal: 96.2, paid_amount: 96.2, due: 0, payment_status: 'paid' },
    { Ref: 'SL-2938', client_name: 'James Chen', warehouse_name: 'Main', statut: 'ordered', GrandTotal: 452.75, paid_amount: 0, due: 452.75, payment_status: 'unpaid' },
    { Ref: 'SL-2937', client_name: 'Fatima Zahra', warehouse_name: 'Depot 2', statut: 'cancelled', GrandTotal: 61.9, paid_amount: 61.9, due: 0, payment_status: 'paid' },
  ],
};

function applyDemo() {
  demoMode.value = true;
  report.value = DEMO.report;
  payments.value = DEMO.payments.map(p => ({ ...p, color: PAYMENT_COLORS[p.color] || '#6d28d9' }));
  stockValue.value = DEMO.stock;
  sales.value = DEMO.lastSales;
  stockAlerts.value = DEMO.stockAlerts;
  topProducts.value = DEMO.topProducts;
  buildCharts(
    DEMO.days, DEMO.sales, DEMO.purchases, DEMO.products, DEMO.customers,
    { days: DEMO.days, sent: DEMO.paymentSent, received: DEMO.paymentReceived }
  );
}

async function load() {
  loading.value = true;
  try {
    const { from, to } = rangeFor(period.value);
    const data = await http.get('dashboard_data', {
      warehouse_id: warehouseId.value,
      to,
      from,
    });

    demoMode.value = false;
    const dash = data.report_dashboard.original;
    const r = dash.report;
    report.value = {
      today_sales: Number(r.today_sales) || 0,
      today_purchases: Number(r.today_purchases) || 0,
      return_sales: Number(r.return_sales) || 0,
      return_purchases: Number(r.return_purchases) || 0,
      sales_due: Number(r.sales_due) || 0,
      purchase_due: Number(r.purchase_due) || 0,
      today_invoices: Number(r.today_invoices) || 0,
      today_profit: Number(r.today_profit) || 0,
    };
    warehouses.value = data.warehouses || [];
    sales.value = dash.last_sales || [];
    stockAlerts.value = dash.stock_alert || [];
    topProducts.value = dash.products || [];
    payments.value = (data.sales_by_payment || []).map(p => ({
      name: p.name,
      amount: Number(p.amount) || 0,
      percentage: Number(p.percentage) || 0,
      color: PAYMENT_COLORS[p.color] || '#6d28d9',
    }));
    if (data.stock_value) {
      stockValue.value = {
        by_cost: Number(data.stock_value.by_cost) || 0,
        by_retail: Number(data.stock_value.by_retail) || 0,
        by_wholesale: Number(data.stock_value.by_wholesale) || 0,
      };
    }
    const pay = data.payments?.original || {};
    buildCharts(
      data.sales.original.days,
      data.sales.original.data,
      data.purchases.original.data,
      data.product_report.original || [],
      data.customers?.original || [],
      { days: pay.days || [], sent: pay.payment_sent || [], received: pay.payment_received || [] }
    );
  } catch (e) {
    applyDemo();
  } finally {
    loadCount.value++;
    loading.value = false;
  }
}

onMounted(load);
</script>

<style scoped>
.page-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  flex-wrap: wrap;
  margin-bottom: 24px;
}
.wh-select {
  min-width: 180px;
}
/* Soft elevation on every dashboard card (stat tiles, charts, lists, table). */
.page :deep(.ant-card) {
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
  border-radius: 10px;
}
.stat-card {
  cursor: pointer;
  transition: box-shadow 0.2s ease, transform 0.2s ease;
}
.stat-card:hover {
  box-shadow: 0 6px 16px rgba(0, 0, 0, 0.1);
  transform: translateY(-2px);
}
.stat-inner {
  display: flex;
  align-items: center;
  gap: 14px;
}
.stat-icon {
  width: 44px;
  height: 44px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  color: #6d28d9;
  flex: none;
}
.stat-label {
  color: rgba(0, 0, 0, 0.45);
  font-size: 13px;
}
.stat-value {
  font-size: 20px;
  font-weight: 700;
  margin-top: 2px;
}
.chart-card :deep(.ant-card-body) {
  padding-top: 8px;
}
.stockval {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.stockval-row {
  position: relative;
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 14px 16px;
  border-radius: 12px;
  overflow: hidden;
}
.stockval-icon {
  width: 40px;
  height: 40px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #fff;
  font-size: 18px;
  flex: none;
}
.stockval-label {
  color: rgba(0, 0, 0, 0.5);
  font-size: 13px;
}
.stockval-value {
  font-size: 20px;
  font-weight: 700;
  line-height: 1.2;
}
/* Thin proportional bar pinned to the bottom of each row. */
.stockval-bar {
  position: absolute;
  left: 0;
  bottom: 0;
  height: 3px;
  border-radius: 0 3px 3px 0;
  opacity: 0.55;
  transition: width 0.4s ease;
}
.paylist {
  display: flex;
  flex-direction: column;
  gap: 16px;
}
.pay-row {
  display: flex;
  align-items: center;
  gap: 14px;
}
.pay-icon {
  width: 40px;
  height: 40px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #fff;
  font-size: 18px;
  flex: none;
}
.pay-main {
  flex: 1;
  min-width: 0;
}
.pay-top {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 6px;
}
.pay-name {
  color: rgba(0, 0, 0, 0.65);
  font-size: 13px;
}
.pay-amount {
  font-weight: 700;
}
.pay-track {
  height: 6px;
  border-radius: 3px;
  background: rgba(128, 128, 128, 0.15);
  overflow: hidden;
}
.pay-fill {
  display: block;
  height: 100%;
  border-radius: 3px;
  transition: width 0.4s ease;
}
.pay-pct {
  flex: none;
  width: 40px;
  text-align: right;
  font-size: 13px;
  color: rgba(0, 0, 0, 0.45);
}

/* ---- Stock alert list ---- */
.alertlist,
.toplist {
  display: flex;
  flex-direction: column;
  gap: 16px;
}
.alert-row {
  display: flex;
  align-items: flex-start;
  gap: 12px;
}
.alert-icon {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(245, 34, 45, 0.12);
  color: #f5222d;
  font-size: 16px;
  flex: none;
}
.alert-main {
  flex: 1;
  min-width: 0;
}
.alert-top {
  display: flex;
  justify-content: space-between;
  gap: 12px;
}
.alert-name {
  font-weight: 600;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.alert-qty {
  flex: none;
  font-weight: 700;
  color: #f5222d;
}
.alert-meta {
  display: flex;
  gap: 12px;
  font-size: 12px;
  color: rgba(0, 0, 0, 0.45);
  margin: 2px 0 6px;
}
.alert-wh {
  display: inline-flex;
  align-items: center;
  gap: 4px;
}
.alert-track {
  height: 5px;
  border-radius: 3px;
  background: rgba(128, 128, 128, 0.15);
  overflow: hidden;
}
.alert-fill {
  display: block;
  height: 100%;
  border-radius: 3px;
  transition: width 0.4s ease;
}

/* ---- Top selling products list ---- */
.top-row {
  display: flex;
  align-items: center;
  gap: 12px;
}
.top-rank {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 13px;
  font-weight: 700;
  flex: none;
  background: rgba(128, 128, 128, 0.14);
  color: rgba(0, 0, 0, 0.55);
}
.top-rank.rank-1 { background: #f6c454; color: #7a5a00; }
.top-rank.rank-2 { background: #d5d9df; color: #4a4f57; }
.top-rank.rank-3 { background: #e6b183; color: #7a4410; }
.top-main {
  flex: 1;
  min-width: 0;
}
.top-name {
  font-weight: 600;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  margin-bottom: 6px;
}
.top-track {
  height: 5px;
  border-radius: 3px;
  background: rgba(128, 128, 128, 0.15);
  overflow: hidden;
}
.top-fill {
  display: block;
  height: 100%;
  border-radius: 3px;
  background: linear-gradient(90deg, #6d28d9, #a78bfa);
  transition: width 0.4s ease;
}
.top-figures {
  flex: none;
  text-align: right;
}
.top-amount {
  font-weight: 700;
  color: #52c41a;
}
.top-sales {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.45);
}

/* Right column stacks Stock value + Quick actions and fills the row height so
   its bottom aligns with the taller Sales-by-payment card on the left. */
.dash-right {
  display: flex;
  flex-direction: column;
  gap: 16px;
}
.fill-card {
  height: 100%;
}
.qa-card {
  flex: 1;
  display: flex;
  flex-direction: column;
}
.qa-card :deep(.ant-card-body) {
  flex: 1;
  display: flex;
  align-items: center;
}

/* ---- Quick actions ---- */
.qa-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 12px;
  width: 100%;
}
.qa-tile {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  padding: 14px 8px;
  border: 1px solid rgba(5, 5, 5, 0.06);
  border-radius: 12px;
  background: none;
  cursor: pointer;
  transition: border-color 0.15s, transform 0.15s;
}
.qa-tile:hover {
  border-color: #6d28d9;
  transform: translateY(-2px);
}
.qa-icon {
  width: 40px;
  height: 40px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
}
.qa-label {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.7);
  text-align: center;
}

/* ============================ Responsive ============================ */
@media (max-width: 767px) {
  /* Greeting stacks above the filters, which take the full width. */
  .page-header {
    flex-direction: column;
    align-items: stretch;
    gap: 12px;
    margin-bottom: 16px;
  }
  .dash-filters {
    width: 100%;
  }
  .dash-filters :deep(.ant-space-item:nth-child(2)) {
    flex: 1;
  }
  .wh-select {
    width: 100%;
    min-width: 0;
  }
  /* Two quick-action tiles per row instead of three. */
  .qa-grid {
    grid-template-columns: repeat(2, 1fr);
  }
}

/* Phones (2 stat cards per row): stack icon over label/value so the amount
   gets the full card width and never wraps, and trim the card padding. */
@media (max-width: 575px) {
  .stat-card :deep(.ant-card-body) {
    padding: 14px;
  }
  .stat-inner {
    flex-direction: column;
    align-items: flex-start;
    gap: 8px;
  }
  .stat-icon {
    width: 36px;
    height: 36px;
    font-size: 16px;
  }
  .stat-label {
    font-size: 12px;
  }
  .stat-value {
    font-size: 17px;
    white-space: nowrap;
  }
}
.kpi-delta-up {
  color: #52c41a;
}
.kpi-delta-down {
  color: #f5222d;
}
</style>
