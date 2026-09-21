<template>
  <div class="page">
    <PageHeader :title="$t('Deposits_Report')" :breadcrumb="[$t('Reports'), $t('Deposits_Report')]">
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

    <!-- Filters -->
    <a-card size="small" style="margin-bottom: 16px">
      <a-row :gutter="[16, 8]">
        <a-col :xs="24" :md="8" :xl="6">
          <div class="filter-label">{{ $t('date') }}</div>
          <DateRangePicker v-model:value="range" style="width: 100%" :allow-clear="false" @change="crud.reload()" />
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
      <a-col :xs="24" :xl="9">
        <a-card size="small" class="chart-card" :title="$t('Share_by_Category')">
          <apexchart
            v-if="donutSeries.length"
            type="donut"
            height="300"
            :options="donutOptions"
            :series="donutSeries"
          />
          <a-empty v-else :description="$t('NodataAvailable')" style="padding: 48px 0" />
        </a-card>
      </a-col>
      <a-col :xs="24" :xl="15">
        <a-card size="small" class="chart-card" :title="$t('Deposits_over_time')">
          <ReportChart
            :data="timeseries"
            :fields="[{ key: 'amount', label: t('Total_Deposits') }]"
            :title="$t('Deposits_Report')"
            type="area"
            x-key="d"
            :format="money"
            :height="300"
          />
        </a-card>
      </a-col>
    </a-row>

    <!-- Table -->
    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'category_name'">
          <span style="font-weight: 500">{{ record.category_name }}</span>
        </template>
        <template v-else-if="column.key === 'total_deposits'">
          <strong style="color: #10b981">{{ money(record.total_deposits) }}</strong>
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
      <template #summary>
        <a-table-summary-row>
          <a-table-summary-cell :index="0"><b>{{ $t('Total') }}</b></a-table-summary-cell>
          <a-table-summary-cell :index="1" align="right">
            <b>{{ kpis.count || 0 }}</b>
          </a-table-summary-cell>
          <a-table-summary-cell :index="2" align="right">
            <b style="color: #10b981">{{ money(kpis.total || 0) }}</b>
          </a-table-summary-cell>
          <a-table-summary-cell :index="3" />
        </a-table-summary-row>
      </template>
    </DataTable>
  </div>
</template>

<script setup>
/**
 * Deposits report — analytics view over GET report/deposits_report:
 * {reports (grouped by category, paginated), totalRows, kpis, timeseries}.
 * KPIs and the daily trend cover the whole filtered set; the donut shows the
 * loaded categories' shares (top 7 + Other).
 */
import { ref, computed, onMounted } from 'vue';
import dayjs from 'dayjs';
import { useI18n } from 'vue-i18n';
import {
  FilePdfOutlined, FileExcelOutlined, PrinterOutlined,
  BankOutlined, FileTextOutlined, TagsOutlined, CalendarOutlined,
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

const filterParams = () => ({
  from: range.value?.[0]?.format?.('YYYY-MM-DD') || '',
  to: range.value?.[1]?.format?.('YYYY-MM-DD') || '',
});

// Payload: { reports, totalRows, kpis, timeseries }
const crud = useCrudTable('report/deposits_report', {
  rowsKey: 'reports',
  sortField: 'total_deposits',
  sortType: 'desc',
  params: filterParams,
});

const kpis = computed(() => crud.payload.value?.kpis || {});
const timeseries = computed(() => crud.payload.value?.timeseries || []);

const shareOf = r => {
  const total = Number(kpis.value.total) || 0;
  return total > 0 ? +(Number(r.total_deposits) / total * 100).toFixed(1) : 0;
};

/* ---------------------------------------------------------------- KPIs */
const kpiTiles = computed(() => {
  const n = k => Number(kpis.value[k]) || 0;
  return [
    { label: t('Total_Deposits'), value: money(n('total')), icon: BankOutlined, color: '#10b981', tint: 'rgba(16, 185, 129, 0.12)' },
    { label: t('Entries'), value: n('count'), icon: FileTextOutlined, color: '#1677ff', tint: 'rgba(22, 119, 255, 0.12)' },
    { label: t('Categories_Count'), value: n('categories'), icon: TagsOutlined, color: '#6d28d9', tint: 'rgba(109, 40, 217, 0.12)' },
    { label: t('Avg_Per_Day'), value: money(n('avg_per_day')), icon: CalendarOutlined, color: '#13c2c2', tint: 'rgba(19, 194, 194, 0.12)' },
  ];
});

/* --------------------------------------------------------------- donut */
const donutRows = computed(() => {
  const rows = [...crud.rows.value]
    .map(r => ({ name: r.category_name, value: Number(r.total_deposits) || 0 }))
    .sort((a, b) => b.value - a.value);
  if (rows.length <= 8) return rows;
  const top = rows.slice(0, 7);
  const other = rows.slice(7).reduce((s, r) => s + r.value, 0);
  return [...top, { name: t('Other'), value: other }];
});
const donutSeries = computed(() => donutRows.value.map(r => r.value));
const donutOptions = computed(() => ({
  chart: { type: 'donut', background: 'transparent', fontFamily: 'inherit' },
  labels: donutRows.value.map(r => r.name),
  colors: ['#10b981', '#6366f1', '#22d3ee', '#f59e0b', '#ec4899', '#8b5cf6', '#0ea5e9', '#94a3b8'],
  legend: { position: 'bottom', labels: { colors: '#595959' } },
  dataLabels: { enabled: false },
  stroke: { colors: ['#ffffff'], width: 3 },
  plotOptions: { pie: { donut: { size: '70%' } } },
  tooltip: { theme: 'light', y: { formatter: v => money(v) } },
}));

/* --------------------------------------------------------------- table */
const columns = computed(() => [
  { title: t('Deposit_Category'), dataIndex: 'category_name', key: 'category_name' },
  { title: t('Entries'), dataIndex: 'deposits_count', key: 'deposits_count', align: 'right' },
  { title: t('Total_Deposits'), dataIndex: 'total_deposits', key: 'total_deposits', align: 'right', exportValue: r => money(r.total_deposits) },
  { title: t('Share'), key: 'share', align: 'right', width: 170, exportValue: r => `${shareOf(r)}%` },
]);

/* ------------------------------------------------------------- exports */
const exporting = ref(null);

async function allRows() {
  // Exports cover the whole filtered set, not just the page.
  const data = await http.get('report/deposits_report', { ...filterParams(), page: 1, limit: -1, search: crud.search.value });
  return data.reports || [];
}

async function exportList(kind) {
  exporting.value = kind;
  try {
    const rows = await allRows();
    if (kind === 'xlsx') await exportExcel('Deposits_report', columns.value, rows);
    else await exportPdf(t('Deposits_Report'), columns.value, rows);
  } finally {
    exporting.value = null;
  }
}

async function doPrint() {
  // Open the window synchronously so the popup isn't blocked.
  const win = window.open('', '_blank');
  const rows = await allRows();
  printRows(t('Deposits_Report'), columns.value, rows, { win });
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

/* Share cell: percent + a mini bar so category weight reads at a glance. */
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
