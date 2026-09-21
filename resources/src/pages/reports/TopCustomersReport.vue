<template>
  <div class="page">
    <PageHeader :title="$t('Top_customers')" :breadcrumb="[$t('Reports'), $t('Top_customers')]">
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

    <!-- Filters + "Top by" metric -->
    <a-card size="small" style="margin-bottom: 16px">
      <a-row :gutter="[16, 8]" align="bottom">
        <a-col :xs="24" :md="8" :xl="6">
          <div class="filter-label">{{ $t('date') }}</div>
          <DateRangePicker v-model:value="range" style="width: 100%" :allow-clear="false" @change="crud.reload()" />
        </a-col>
        <a-col :xs="24" :md="8" :xl="6">
          <div class="filter-label">{{ $t('Top_By') }}</div>
          <a-segmented v-model:value="metric" :options="metricOptions" @change="crud.reload()" />
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

    <!-- Chart: top 10 by the chosen metric -->
    <a-card size="small" class="chart-card" style="margin-bottom: 16px" :title="chartTitle">
      <ReportChart
        :data="chartData"
        :fields="chartFields"
        :title="$t('Top_customers')"
        type="bar"
        x-key="name"
        :format="metric === 'amount' ? money : undefined"
        :height="320"
      />
    </a-card>

    <!-- Table -->
    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'rank'">
          <span class="rank" :class="'rank-' + rankOf(record)">{{ rankOf(record) }}</span>
        </template>
        <template v-else-if="column.key === 'name'">
          <span style="font-weight: 500">{{ record.name }}</span>
        </template>
        <template v-else-if="column.key === 'total'">
          <strong style="color: #10b981">{{ money(record.total) }}</strong>
        </template>
        <template v-else-if="column.key === 'share'">
          <div class="share-cell">
            <span>{{ shareOf(record) }}%</span>
            <span class="share-track">
              <span class="share-fill" :style="{ width: Math.min(shareOf(record), 100) + '%' }"></span>
            </span>
          </div>
        </template>
      </template>
    </DataTable>
  </div>
</template>

<script setup>
/**
 * Top customers — GET report/top_customers {customers, totalRows, stats,
 * chart}. The "Top by" control switches the ranking metric server-side
 * (order_by = amount | orders); stats and the top-10 chart cover the whole
 * filtered range.
 */
import { ref, computed, onMounted } from 'vue';
import dayjs from 'dayjs';
import { useI18n } from 'vue-i18n';
import {
  FilePdfOutlined, FileExcelOutlined, PrinterOutlined,
  TeamOutlined, ShoppingCartOutlined, DollarOutlined, BarChartOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import ReportChart from '../../components/ReportChart.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { exportExcel, exportPdf, printRows } from '../../lib/exporters';
import http from '../../lib/http';
import DateRangePicker from '../../components/DateRangePicker.vue';
import ViewCurrencySelect from '../../components/ViewCurrencySelect.vue';

const { t } = useI18n();
const { money } = useFormat();

const range = ref([dayjs().subtract(29, 'day'), dayjs()]);
const metric = ref('amount');

const metricOptions = [
  { value: 'amount', label: t('Amount') },
  { value: 'orders', label: t('Orders') },
];

const filterParams = () => ({
  from: range.value?.[0]?.format?.('YYYY-MM-DD') || '',
  to: range.value?.[1]?.format?.('YYYY-MM-DD') || '',
  order_by: metric.value,
});

// Payload: { customers, totalRows, stats, chart }
const crud = useCrudTable('report/top_customers', {
  rowsKey: 'customers',
  params: filterParams,
});

const stats = computed(() => crud.payload.value?.stats || {});

const shareOf = r => {
  const total = Number(stats.value.revenue) || 0;
  return total > 0 ? +(Number(r.total) / total * 100).toFixed(1) : 0;
};
// Rank within the ranking order: page offset + row position.
const rankOf = r => (crud.page.value - 1) * crud.limit.value + crud.rows.value.indexOf(r) + 1;

/* ---------------------------------------------------------------- KPIs */
const kpiTiles = computed(() => {
  const n = k => Number(stats.value[k]) || 0;
  return [
    { label: t('Customers'), value: n('customers'), icon: TeamOutlined, color: '#6d28d9', tint: 'rgba(109, 40, 217, 0.12)' },
    { label: t('Sales'), value: n('orders'), icon: ShoppingCartOutlined, color: '#1677ff', tint: 'rgba(22, 119, 255, 0.12)' },
    { label: t('Revenue'), value: money(n('revenue')), icon: DollarOutlined, color: '#10b981', tint: 'rgba(16, 185, 129, 0.12)' },
    { label: t('Avg_Per_Customer'), value: money(n('avg_per_customer')), icon: BarChartOutlined, color: '#f59e0b', tint: 'rgba(245, 158, 11, 0.14)' },
  ];
});

/* --------------------------------------------------------------- chart */
const METRIC_FIELD = { amount: 'total', orders: 'total_sales' };
const METRIC_LABEL = { amount: t('Amount'), orders: t('Orders') };

const chartTitle = computed(() => `${t('Top_10_By')} ${METRIC_LABEL[metric.value].toLowerCase()}`);
const chartData = computed(() =>
  (crud.payload.value?.chart || []).map(r => ({
    name: r.name,
    value: Number(r[METRIC_FIELD[metric.value]]) || 0,
  }))
);
const chartFields = computed(() => [{ key: 'value', label: METRIC_LABEL[metric.value] }]);

/* --------------------------------------------------------------- table */
const columns = computed(() => [
  { title: '#', key: 'rank', width: 60, align: 'center', exportValue: r => rankOf(r) },
  { title: t('Name'), dataIndex: 'name', key: 'name' },
  { title: t('Phone'), dataIndex: 'phone', key: 'phone' },
  { title: t('Email'), dataIndex: 'email', key: 'email' },
  { title: t('TotalSales'), dataIndex: 'total_sales', key: 'total_sales', align: 'right', sum: true },
  { title: t('TotalAmount'), key: 'total', dataIndex: 'total', align: 'right', sum: 'money', exportValue: r => money(r.total) },
  { title: t('Share'), key: 'share', align: 'right', width: 170, exportValue: r => `${shareOf(r)}%` },
]);

/* ------------------------------------------------------------- exports */
const exporting = ref(null);

async function allRows() {
  const data = await http.get('report/top_customers', { ...filterParams(), page: 1, limit: -1, search: crud.search.value });
  return data.customers || [];
}

async function exportList(kind) {
  exporting.value = kind;
  try {
    const rows = await allRows();
    if (kind === 'xlsx') await exportExcel('Top_customers', columns.value, rows);
    else await exportPdf(t('Top_customers'), columns.value, rows);
  } finally {
    exporting.value = null;
  }
}

async function doPrint() {
  // Open the window synchronously so the popup isn't blocked.
  const win = window.open('', '_blank');
  const rows = await allRows();
  printRows(t('Top_customers'), columns.value, rows, { win });
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

/* Rank badge — gold / silver / bronze for the podium, neutral after. */
.rank {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 26px;
  height: 26px;
  border-radius: 50%;
  font-size: 12px;
  font-weight: 700;
  background: rgba(128, 128, 128, 0.14);
  color: rgba(0, 0, 0, 0.55);
}
.rank-1 { background: #f6c454; color: #7a5a00; }
.rank-2 { background: #d5d9df; color: #4a4f57; }
.rank-3 { background: #e6b183; color: #7a4410; }

/* Share cell: percent + a mini bar (share of total revenue). */
.share-cell {
  display: flex;
  align-items: center;
  gap: 8px;
  justify-content: flex-end;
}
.share-cell > span:first-child {
  font-weight: 600;
  min-width: 48px;
  text-align: right;
}
.share-track {
  width: 64px;
  height: 5px;
  border-radius: 3px;
  background: rgba(128, 128, 128, 0.15);
  overflow: hidden;
  flex: none;
}
.share-fill {
  display: block;
  height: 100%;
  border-radius: 3px;
  background: #10b981;
}

@media (max-width: 575px) {
  .kpi-value { font-size: 16px; }
}
</style>
