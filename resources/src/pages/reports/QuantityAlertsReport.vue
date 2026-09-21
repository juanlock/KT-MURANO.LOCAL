<template>
  <div class="page">
    <PageHeader :title="$t('ProductQuantityAlerts')" :breadcrumb="[$t('Reports'), $t('ProductQuantityAlerts')]">
      <template #actions>
        <a-space wrap>
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
          <div class="filter-label">{{ $t('warehouse') }}</div>
          <a-select
            v-model:value="warehouseId" style="width: 100%" allow-clear show-search
            option-filter-prop="label" :placeholder="$t('warehouse')" :options="warehouseOptions"
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
              <div class="kpi-value" :style="k.style">
                <a-spin v-if="crud.loading.value" size="small" />
                <template v-else>{{ k.value }}</template>
              </div>
            </div>
          </div>
        </a-card>
      </a-col>
    </a-row>

    <!-- Chart: the most depleted items -->
    <a-card size="small" class="chart-card" style="margin-bottom: 16px" :title="tf('Most_Depleted_Items', 'Most depleted items')">
      <ReportChart
        :data="depletedChart"
        :fields="[
          { key: 'quantity', label: t('Quantity') },
          { key: 'stock_alert', label: t('AlertQuantity') },
        ]"
        :title="$t('ProductQuantityAlerts')"
        type="bar"
        x-key="name"
        :height="300"
      />
    </a-card>

    <!-- Table -->
    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'name'">
          <span style="font-weight: 500">{{ record.name }}</span>
        </template>
        <template v-else-if="column.key === 'status'">
          <a-tag :color="statusOf(record).color">{{ statusOf(record).label }}</a-tag>
        </template>
        <template v-else-if="column.key === 'quantity'">
          <strong :style="{ color: severityColor(record) }">{{ record.quantity }}</strong>
        </template>
        <template v-else-if="column.key === 'shortfall'">
          <strong style="color: #f43f5e">{{ shortfallOf(record) }}</strong>
        </template>
        <template v-else-if="column.key === 'level'">
          <div class="level-cell">
            <span class="level-track">
              <span
                class="level-fill"
                :style="{ width: ratioOf(record) + '%', background: severityColor(record) }"
              ></span>
            </span>
            <span :style="{ color: severityColor(record), fontWeight: 600 }">{{ ratioOf(record) }}%</span>
          </div>
        </template>
      </template>
    </DataTable>
  </div>
</template>

<script setup>
/**
 * Quantity alerts — analytics view over the deviant contract
 * `get_products_stock_alerts` (Laravel paginator: {products:{data,total},
 * warehouses, stats}; the warehouse filter param is `warehouse`).
 * Severity model matches the dashboard: out of stock ≤0, critical ≤34% of the
 * alert threshold, low otherwise.
 */
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  FilePdfOutlined, FileExcelOutlined, PrinterOutlined,
  BellOutlined, StopOutlined, WarningOutlined, ShopOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import ReportChart from '../../components/ReportChart.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { exportExcel, exportPdf, printRows } from '../../lib/exporters';
import http from '../../lib/http';

const { t } = useI18n();

const warehouseId = ref(undefined);
const filterParams = () => ({ warehouse: warehouseId.value || '' });

const crud = useCrudTable('get_products_stock_alerts', {
  params: filterParams,
  select: p => ({ rows: p?.products?.data || [], total: Number(p?.products?.total) || 0 }),
});

const warehouseOptions = computed(() =>
  (crud.payload.value?.warehouses || []).map(w => ({ value: w.id, label: w.name }))
);
const stats = computed(() => crud.payload.value?.stats || {});

/* ------------------------------------------------------------ severity */
const num = v => Number(v) || 0;
const ratioOf = r => {
  const threshold = num(r.stock_alert);
  if (threshold <= 0) return 0;
  return Math.max(0, Math.min(100, Math.round((num(r.quantity) / threshold) * 100)));
};
const shortfallOf = r => Math.max(0, +(num(r.stock_alert) - num(r.quantity)).toFixed(2));
const statusOf = r => {
  if (num(r.quantity) <= 0) return { label: t('OutOfStock'), color: 'error' };
  if (ratioOf(r) <= 34) return { label: tf('Critical', 'Critical'), color: 'volcano' };
  return { label: tf('Low', 'Low'), color: 'warning' };
};
const severityColor = r => (num(r.quantity) <= 0 ? '#f5222d' : ratioOf(r) <= 34 ? '#fa541c' : '#faad14');

/* ---------------------------------------------------------------- KPIs */
const kpiTiles = computed(() => {
  const n = k => Number(stats.value[k]) || 0;
  return [
    { label: tf('Alerts', 'Alerts'), value: n('total'), icon: BellOutlined, color: '#faad14', tint: 'rgba(250, 173, 20, 0.14)' },
    { label: t('OutOfStock'), value: n('out_of_stock'), icon: StopOutlined, color: '#f5222d', tint: 'rgba(245, 34, 45, 0.12)', style: n('out_of_stock') > 0 ? { color: '#f5222d' } : undefined },
    { label: tf('Critical', 'Critical'), value: n('critical'), icon: WarningOutlined, color: '#fa541c', tint: 'rgba(250, 84, 28, 0.12)' },
    { label: tf('Warehouses', 'Warehouses'), value: n('warehouses'), icon: ShopOutlined, color: '#1677ff', tint: 'rgba(22, 119, 255, 0.12)' },
  ];
});
const tf = (key, fallback) => {
  const out = t(key);
  return out === key ? fallback : out;
};

/* --------------------------------------------------------------- chart */
// Top 10 most depleted (lowest fill ratio) from the loaded rows.
const depletedChart = computed(() =>
  [...crud.rows.value]
    .sort((a, b) => ratioOf(a) - ratioOf(b))
    .slice(0, 10)
    .map(r => ({ name: r.name, quantity: num(r.quantity), stock_alert: num(r.stock_alert) }))
);

/* --------------------------------------------------------------- table */
const columns = computed(() => [
  { title: t('ProductCode'), dataIndex: 'code', key: 'code' },
  { title: t('ProductName'), dataIndex: 'name', key: 'name' },
  { title: t('warehouse'), dataIndex: 'warehouse', key: 'warehouse' },
  { title: t('Status'), key: 'status', width: 120, exportValue: r => statusOf(r).label },
  { title: t('Quantity'), key: 'quantity', dataIndex: 'quantity', align: 'right', sum: true, exportValue: r => r.quantity },
  { title: t('AlertQuantity'), dataIndex: 'stock_alert', key: 'stock_alert', align: 'right' },
  { title: tf('Shortfall', 'Shortfall'), key: 'shortfall', align: 'right', exportValue: r => shortfallOf(r) },
  { title: tf('Stock_Level', 'Stock level'), key: 'level', align: 'right', width: 170, exportValue: r => `${ratioOf(r)}%` },
]);

/* ------------------------------------------------------------- exports */
const exporting = ref(null);

async function allRows() {
  // limit=-1 would make the backend's collection slice(0, -1) drop the last
  // row, so exports ask for a big page instead.
  const data = await http.get('get_products_stock_alerts', { ...filterParams(), page: 1, limit: 100000, search: crud.search.value });
  return data?.products?.data || data?.products || [];
}

async function exportList(kind) {
  exporting.value = kind;
  try {
    const rows = await allRows();
    if (kind === 'xlsx') await exportExcel('Quantity_alerts', columns.value, rows);
    else await exportPdf(t('ProductQuantityAlerts'), columns.value, rows);
  } finally {
    exporting.value = null;
  }
}

async function doPrint() {
  // Open the window synchronously so the popup isn't blocked.
  const win = window.open('', '_blank');
  const rows = await allRows();
  printRows(t('ProductQuantityAlerts'), columns.value, rows, { win });
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

/* Stock-level cell: depletion bar + percent, dashboard's severity colours. */
.level-cell {
  display: flex;
  align-items: center;
  gap: 8px;
  justify-content: flex-end;
}
.level-track {
  width: 64px;
  height: 5px;
  border-radius: 3px;
  background: rgba(128, 128, 128, 0.15);
  overflow: hidden;
  flex: none;
}
.level-fill {
  display: block;
  height: 100%;
  border-radius: 3px;
}

@media (max-width: 575px) {
  .kpi-value { font-size: 16px; }
}
</style>
