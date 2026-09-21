<template>
  <div class="page">
    <PageHeader :title="$t('product_report')" :breadcrumb="[$t('Reports'), $t('product_report')]">
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
              <div class="kpi-value">
                <a-spin v-if="crud.loading.value" size="small" />
                <template v-else>{{ k.value }}</template>
              </div>
            </div>
          </div>
        </a-card>
      </a-col>
    </a-row>

    <!-- Chart: top 10 by revenue in the range -->
    <a-card size="small" class="chart-card" style="margin-bottom: 16px" :title="$t('Top_10_Products_By_Revenue')">
      <ReportChart
        :data="chartData"
        :fields="[{ key: 'amount', label: t('TotalAmount') }]"
        :title="$t('product_report')"
        type="bar"
        x-key="name"
        :format="money"
        :height="320"
      />
    </a-card>

    <!-- Table -->
    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'name'">
          <span style="font-weight: 500">{{ record.name }}</span>
        </template>
        <template v-else-if="column.key === 'sold_amount'">
          <strong style="color: #10b981">{{ money(record.sold_amount) }}</strong>
        </template>
        <template v-else-if="column.key === 'share'">
          <div class="share-cell">
            <span>{{ shareOf(record) }}%</span>
            <span class="share-track">
              <span class="share-fill" :style="{ width: Math.min(shareOf(record), 100) + '%' }"></span>
            </span>
          </div>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-tooltip :title="$t('Reports')">
            <a-button type="text" size="small" @click="$router.push(`/reports/product/${record.id}`)">
              <template #icon><EyeOutlined /></template>
            </a-button>
          </a-tooltip>
        </template>
      </template>
    </DataTable>
  </div>
</template>

<script setup>
/**
 * Product report — GET report/product_report {products (all products with
 * sold qty+amount over the range), totalRows, stats, chart, warehouses}.
 * Stats/chart are whole-range SQL aggregates; sold_qty strings carry the
 * product's unit (legacy format, e.g. "12 Pcs").
 */
import { ref, computed, onMounted } from 'vue';
import dayjs from 'dayjs';
import { useI18n } from 'vue-i18n';
import {
  FilePdfOutlined, FileExcelOutlined, PrinterOutlined, EyeOutlined,
  AppstoreOutlined, TagOutlined, NumberOutlined, DollarOutlined,
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
const warehouseId = ref(undefined);

const filterParams = () => ({
  from: range.value?.[0]?.format?.('YYYY-MM-DD') || '',
  to: range.value?.[1]?.format?.('YYYY-MM-DD') || '',
  ...(warehouseId.value ? { warehouse_id: warehouseId.value } : {}),
});

// Payload: { products, totalRows, stats, chart, warehouses }
const crud = useCrudTable('report/product_report', {
  rowsKey: 'products',
  params: filterParams,
});

const warehouseOptions = computed(() =>
  (crud.payload.value?.warehouses || []).map(w => ({ value: w.id, label: w.name }))
);
const stats = computed(() => crud.payload.value?.stats || {});
const chartData = computed(() => crud.payload.value?.chart || []);

const shareOf = r => {
  const total = Number(stats.value.revenue) || 0;
  return total > 0 ? +(Number(r.sold_amount) / total * 100).toFixed(1) : 0;
};

/* ---------------------------------------------------------------- KPIs */
const kpiTiles = computed(() => {
  const n = k => Number(stats.value[k]) || 0;
  return [
    { label: t('Products'), value: n('products'), icon: AppstoreOutlined, color: '#6d28d9', tint: 'rgba(109, 40, 217, 0.12)' },
    { label: t('Products_Sold'), value: n('sold_products'), icon: TagOutlined, color: '#1677ff', tint: 'rgba(22, 119, 255, 0.12)' },
    { label: t('Units_Sold'), value: n('qty').toLocaleString(), icon: NumberOutlined, color: '#f59e0b', tint: 'rgba(245, 158, 11, 0.14)' },
    { label: t('Revenue'), value: money(n('revenue')), icon: DollarOutlined, color: '#10b981', tint: 'rgba(16, 185, 129, 0.12)' },
  ];
});

/* --------------------------------------------------------------- table */
const columns = computed(() => [
  { title: t('ProductCode'), dataIndex: 'code', key: 'code' },
  { title: t('ProductName'), dataIndex: 'name', key: 'name' },
  { title: t('TotalSales'), dataIndex: 'sold_qty', key: 'sold_qty', align: 'right' },
  { title: t('TotalAmount'), key: 'sold_amount', dataIndex: 'sold_amount', align: 'right', sum: 'money', exportValue: r => money(r.sold_amount) },
  { title: t('Share'), key: 'share', align: 'right', width: 170, exportValue: r => `${shareOf(r)}%` },
  { title: t('Action'), key: 'actions', align: 'center', width: 80, exportable: false },
]);

/* ------------------------------------------------------------- exports */
const exporting = ref(null);

async function allRows() {
  const data = await http.get('report/product_report', { ...filterParams(), page: 1, limit: -1, search: crud.search.value });
  return data.products || [];
}

async function exportList(kind) {
  exporting.value = kind;
  try {
    const rows = await allRows();
    if (kind === 'xlsx') await exportExcel('Product_report', columns.value, rows);
    else await exportPdf(t('product_report'), columns.value, rows);
  } finally {
    exporting.value = null;
  }
}

async function doPrint() {
  // Open the window synchronously so the popup isn't blocked.
  const win = window.open('', '_blank');
  const rows = await allRows();
  printRows(t('product_report'), columns.value, rows, { win });
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

/* Share cell: percent + a mini bar (share of range revenue). */
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
