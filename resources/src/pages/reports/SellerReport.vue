<template>
  <div class="page">
    <PageHeader :title="$t('Seller_report')" :breadcrumb="[$t('Reports'), $t('Seller_report')]">
      <template #actions>
        <a-space wrap>
          <!-- Multi-Currency: view amounts converted (display-only) -->
          <ViewCurrencySelect />
          <a-button :loading="exporting === 'pdf'" @click="exportList('pdf')">
            <template #icon><FilePdfOutlined /></template>
            {{ $t('PDF') }}
          </a-button>
          <a-button :loading="exporting === 'xlsx'" @click="exportList('xlsx')">
            <template #icon><FileExcelOutlined /></template>
            {{ $t('EXCEL') }}
          </a-button>
          <a-button @click="doPrint">
            <template #icon><PrinterOutlined /></template>
            {{ $t('print') }}
          </a-button>
        </a-space>
      </template>
    </PageHeader>

    <!-- Filters: date range WITH time (legacy sent start/end time too) -->
    <a-card size="small" style="margin-bottom: 16px">
      <a-row :gutter="[16, 8]">
        <a-col :xs="24" :md="10" :xl="8">
          <div class="filter-label">{{ $t('date') }}</div>
          <DateRangePicker
            v-model:value="range"
            show-time
            style="width: 100%"
            :allow-clear="false"
            @change="crud.reload()"
          />
        </a-col>
        <a-col :xs="24" :md="8" :xl="6">
          <div class="filter-label">{{ $t('warehouse') }}</div>
          <a-select
            v-model:value="warehouseId" style="width: 100%" allow-clear show-search
            option-filter-prop="label" :placeholder="$t('Choose_Warehouse')" :options="warehouseOptions"
            @change="crud.reload()"
          />
        </a-col>
      </a-row>
    </a-card>

    <!-- KPI tiles -->
    <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
      <a-col v-for="k in kpiTiles" :key="k.label" :xs="12" :sm="12" :md="6">
        <a-card size="small" class="kpi-card">
          <div class="kpi-inner">
            <div class="kpi-icon" :style="{ background: k.tint, color: k.color }">
              <component :is="k.icon" />
            </div>
            <div class="kpi-text">
              <div class="kpi-label">{{ k.label }}</div>
              <div class="kpi-value">
                <a-spin v-if="crud.loading.value" size="small" />
                <template v-else>{{ k.value }}</template>
              </div>
            </div>
          </div>
        </a-card>
      </a-col>
    </a-row>

    <!-- Charts -->
    <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
      <a-col :xs="24" :xl="16">
        <a-card size="small" class="chart-card" :title="`${$t('Seller')} — ${$t('TotalSales')}`">
          <ReportChart
            :data="topSellers"
            :fields="[{ key: 'sales', label: t('TotalSales') }]"
            :title="$t('Seller_report')"
            type="bar"
            x-key="name"
            :format="money"
            :height="320"
          />
          <!-- Top-5 listing, like legacy -->
          <div v-if="topSellers.length" class="top-list">
            <div class="top-list-head">
              <span>{{ tf('TopSeller', 'Top seller') }}</span>
              <b>{{ topSellers[0]?.name }} · {{ money(topSellers[0]?.sales || 0) }}</b>
            </div>
            <div v-for="(s, i) in topSellers.slice(0, 5)" :key="s.name" class="top-list-row">
              <span>{{ i + 1 }}. {{ s.name }}</span>
              <span>{{ money(s.sales) }}</span>
            </div>
          </div>
        </a-card>
      </a-col>
      <a-col :xs="24" :xl="8">
        <a-card size="small" class="chart-card" :title="tf('SalesByPaymentMethod', 'Sales by payment method')">
          <ReportChart
            :data="methodTotals"
            :fields="[{ key: 'amount', label: t('Amount') }]"
            :title="tf('SalesByPaymentMethod', 'Sales by payment method')"
            type="bar"
            x-key="name"
            :format="money"
            :height="320"
          />
        </a-card>
      </a-col>
    </a-row>

    <!-- Table: seller + total + one column per payment method, with sums -->
    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'username'">
          <span style="font-weight: 500">{{ record.username }}</span>
        </template>
        <template v-else>{{ money(parse(record[column.key])) }}</template>
      </template>
      <template #summary>
        <a-table-summary-row>
          <a-table-summary-cell :index="0">
            <b>{{ $t('Total') }}</b>
          </a-table-summary-cell>
          <a-table-summary-cell v-for="(c, i) in columns.slice(1)" :key="c.key" :index="i + 1" align="right">
            <b>{{ money(pageSum(c.key)) }}</b>
          </a-table-summary-cell>
        </a-table-summary-row>
      </template>
    </DataTable>
  </div>
</template>

<script setup>
/**
 * Seller report — full parity with legacy seller_report.vue:
 * date+TIME range, warehouse filter, PDF/Excel/Print, four KPI tiles, the
 * top-sellers bar chart with a top-5 listing, the sales-by-payment-method
 * chart, and a table of one row per seller with a DYNAMIC column per payment
 * method plus a totals row. Backend GET report/seller_report answers
 * {report, totalRows, paymentMethods, warehouses}; money figures arrive as
 * number_format strings, so parse() strips the thousands commas.
 */
import { ref, computed, onMounted } from 'vue';
import dayjs from 'dayjs';
import { useI18n } from 'vue-i18n';
import {
  FilePdfOutlined, FileExcelOutlined, PrinterOutlined,
  TeamOutlined, DollarOutlined, BarChartOutlined, StarOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import ReportChart from '../../components/ReportChart.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { exportExcel, exportPdf, printRows } from '../../lib/exporters';
import DateRangePicker from '../../components/DateRangePicker.vue';
import ViewCurrencySelect from '../../components/ViewCurrencySelect.vue';

const { t } = useI18n();
const tf = (key, fallback) => {
  const out = t(key);
  return out === key ? fallback : out;
};
const { money } = useFormat();

const range = ref([dayjs().startOf('day'), dayjs().endOf('day')]);
const warehouseId = ref(undefined);

const filterParams = () => ({
  start_date: range.value?.[0]?.format?.('YYYY-MM-DD') || '',
  end_date: range.value?.[1]?.format?.('YYYY-MM-DD') || '',
  start_time: range.value?.[0]?.format?.('HH:mm:ss') || '',
  end_time: range.value?.[1]?.format?.('HH:mm:ss') || '',
  ...(warehouseId.value ? { warehouse_id: warehouseId.value } : {}),
});

// Payload: { report, totalRows, paymentMethods, warehouses } — rowsKey required.
const crud = useCrudTable('report/seller_report', {
  rowsKey: 'report',
  sortField: 'total_sales',
  sortType: 'desc',
  params: filterParams,
});

const parse = v => parseFloat(String(v ?? 0).replace(/,/g, '')) || 0;

const warehouseOptions = computed(() =>
  (crud.payload.value?.warehouses || []).map(w => ({ value: w.id, label: w.name }))
);
const paymentMethods = computed(() => crud.payload.value?.paymentMethods || []);

/* ------------------------------------------------------------- columns */
const columns = computed(() => [
  { title: t('Seller'), dataIndex: 'username', key: 'username', sorter: true },
  { title: t('TotalSales'), dataIndex: 'total_sales', key: 'total_sales', sorter: true, align: 'right', exportValue: r => money(parse(r.total_sales)) },
  ...paymentMethods.value.map(m => ({
    title: m, dataIndex: m, key: m, sorter: true, align: 'right', exportValue: r => money(parse(r[m])),
  })),
]);

const pageSum = key => crud.rows.value.reduce((s, r) => s + parse(r[key]), 0);

/* ------------------------------------------------- KPIs (page-scoped sums) */
const kpiTiles = computed(() => {
  const rows = crud.rows.value;
  const total = pageSum('total_sales');
  const top = [...rows].sort((a, b) => parse(b.total_sales) - parse(a.total_sales))[0];
  return [
    { label: tf('Sellers', 'Sellers'), value: crud.total.value, icon: TeamOutlined, color: '#1677ff', tint: 'rgba(22, 119, 255, 0.12)' },
    { label: t('TotalSales'), value: money(total), icon: DollarOutlined, color: '#10b981', tint: 'rgba(16, 185, 129, 0.12)' },
    { label: tf('AvgPerSeller', 'Avg per seller'), value: money(rows.length ? total / rows.length : 0), icon: BarChartOutlined, color: '#6d28d9', tint: 'rgba(109, 40, 217, 0.12)' },
    { label: tf('TopSeller', 'Top seller'), value: top?.username || '—', icon: StarOutlined, color: '#f59e0b', tint: 'rgba(245, 158, 11, 0.14)' },
  ];
});

/* ------------------------------------------------------------- charts */
const topSellers = computed(() =>
  [...crud.rows.value]
    .map(r => ({ name: r.username, sales: parse(r.total_sales) }))
    .sort((a, b) => b.sales - a.sales)
    .slice(0, 10)
);
const methodTotals = computed(() =>
  paymentMethods.value
    .map(m => ({ name: m, amount: pageSum(m) }))
    .filter(x => x.amount !== 0)
);

/* ------------------------------------------------------------- exports */
const exporting = ref(null);

async function allRows() {
  // Exports cover the whole filtered set, not just the page.
  const { default: http } = await import('../../lib/http');
  const data = await http.get('report/seller_report', { ...filterParams(), page: 1, limit: -1, search: crud.search.value });
  return data.report || [];
}

async function exportList(kind) {
  exporting.value = kind;
  try {
    const rows = await allRows();
    if (kind === 'xlsx') await exportExcel('Seller_report', columns.value, rows);
    else await exportPdf(t('Seller_report'), columns.value, rows);
  } finally {
    exporting.value = null;
  }
}

async function doPrint() {
  // Open the window synchronously so the popup isn't blocked.
  const win = window.open('', '_blank');
  const rows = await allRows();
  printRows(t('Seller_report'), columns.value, rows, { win });
}

onMounted(crud.fetchRows);
</script>

<style scoped>
.filter-label {
  margin-bottom: 4px;
  color: rgba(0, 0, 0, 0.55);
  font-size: 13px;
}

/* KPI tiles — shared design with the other report pages. */
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
  font-size: 12px;
  color: rgba(0, 0, 0, 0.45);
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

/* Top-5 sellers listing under the chart, like legacy. */
.top-list {
  margin-top: 12px;
  border-top: 1px solid #f0f0f2;
  padding-top: 10px;
  font-size: 13px;
}
.top-list-head {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 6px;
}
.top-list-head span {
  text-transform: uppercase;
  font-size: 11px;
  letter-spacing: 0.05em;
  color: rgba(0, 0, 0, 0.45);
}
.top-list-row {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  padding: 3px 0;
  color: rgba(0, 0, 0, 0.65);
}

@media (max-width: 575px) {
  .kpi-value { font-size: 16px; }
}
</style>
