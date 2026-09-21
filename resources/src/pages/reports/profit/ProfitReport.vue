<template>
  <div class="page">
    <PageHeader :title="cfg.title" :breadcrumb="[$t('Reports'), $t('Profit'), cfg.title]" />

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
              <div class="kpi-value" :style="k.style">
                <a-spin v-if="crud.loading.value" size="small" />
                <template v-else>{{ k.value }}</template>
              </div>
            </div>
          </div>
        </a-card>
      </a-col>
    </a-row>

    <!-- Chart -->
    <a-card size="small" class="chart-card" style="margin-bottom: 16px" :title="cfg.chartTitle">
      <ReportChart
        :data="chartData"
        :fields="chartFields"
        :title="cfg.title"
        :type="cfg.chart"
        x-key="label"
        :format="money"
        :height="300"
      />
    </a-card>

    <!-- Table -->
    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'label'">
          <span style="font-weight: 500">{{ dimension === 'date' ? date(record.label) : record.label }}</span>
        </template>
        <template v-else-if="column.key === 'revenue'">{{ money(record.revenue) }}</template>
        <template v-else-if="column.key === 'cost'">{{ money(record.cost) }}</template>
        <template v-else-if="column.key === 'profit'">
          <strong :style="{ color: Number(record.profit) >= 0 ? '#10b981' : '#f43f5e' }">
            {{ money(record.profit) }}
          </strong>
        </template>
        <template v-else-if="column.key === 'margin'">
          <div class="margin-cell">
            <span :style="{ color: Number(record.margin) >= 0 ? '#10b981' : '#f43f5e' }">{{ record.margin }}%</span>
            <span class="margin-track">
              <span
                class="margin-fill"
                :style="{
                  width: Math.min(Math.abs(Number(record.margin) || 0), 100) + '%',
                  background: Number(record.margin) >= 0 ? '#10b981' : '#f43f5e',
                }"
              ></span>
            </span>
          </div>
        </template>
      </template>
    </DataTable>
  </div>
</template>

<script setup>
/**
 * Profit analysis — ONE page for all six dimensions. The route's
 * meta.dimension picks the grouping; GET report/profit/{dimension} answers
 * {rows, totalRows, kpis, chart, warehouses}. KPIs and the chart cover the
 * whole filtered set; the table is server-paginated/sorted/searched.
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import dayjs from 'dayjs';
import { useI18n } from 'vue-i18n';
import {
  DollarOutlined, WalletOutlined, RiseOutlined, PercentageOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../../components/PageHeader.vue';
import DataTable from '../../../components/DataTable.vue';
import ReportChart from '../../../components/ReportChart.vue';
import { useCrudTable } from '../../../composables/useCrudTable';
import { useFormat } from '../../../composables/useFormat';
import DateRangePicker from '../../../components/DateRangePicker.vue';

const { t } = useI18n();
const { money, date } = useFormat();
const route = useRoute();

const dimension = route.meta.dimension || 'product';

const CONFIG = {
  product: { title: t('Profit by Product'), labelHeader: t('ProductName'), chart: 'bar', chartTitle: t('Top_10_Products_By_Profit') },
  category: { title: t('Profit by Category'), labelHeader: t('Categorie'), chart: 'bar', chartTitle: t('Top_10_Categories_By_Profit') },
  unit: { title: t('Profit by Unit'), labelHeader: t('Unit'), chart: 'bar', chartTitle: t('Top_Units_By_Profit') },
  customer: { title: t('Profit by Customer'), labelHeader: t('Customer'), chart: 'bar', chartTitle: t('Top_10_Customers_By_Profit') },
  date: { title: t('Profit by Date'), labelHeader: t('date'), chart: 'area', chartTitle: t('Profit_Over_Time') },
  warehouse: { title: t('Profit by Warehouse'), labelHeader: t('warehouse'), chart: 'bar', chartTitle: t('Warehouses_By_Profit') },
};
const cfg = CONFIG[dimension] || CONFIG.product;

const range = ref([dayjs().subtract(29, 'day'), dayjs()]);
const warehouseId = ref(undefined);

const filterParams = () => ({
  from: range.value?.[0]?.format?.('YYYY-MM-DD') || '',
  to: range.value?.[1]?.format?.('YYYY-MM-DD') || '',
  warehouse_id: warehouseId.value || '',
});

// Payload: { rows, totalRows, kpis, chart, warehouses }
const crud = useCrudTable(`report/profit/${dimension}`, {
  rowsKey: 'rows',
  sortField: 'profit',
  sortType: 'desc',
  params: filterParams,
});

const warehouseOptions = computed(() =>
  (crud.payload.value?.warehouses || []).map(w => ({ value: w.id, label: w.name }))
);

/* -------------------------------------------------------------- KPIs */
const kpiTiles = computed(() => {
  const k = crud.payload.value?.kpis || {};
  const n = key => Number(k[key]) || 0;
  return [
    { label: t('Revenue'), value: money(n('revenue')), icon: DollarOutlined, color: '#1677ff', tint: 'rgba(22, 119, 255, 0.12)' },
    { label: t('Cost'), value: money(n('cost')), icon: WalletOutlined, color: '#f59e0b', tint: 'rgba(245, 158, 11, 0.14)' },
    {
      label: t('Gross_Profit'), value: money(n('profit')), icon: RiseOutlined,
      color: '#10b981', tint: 'rgba(16, 185, 129, 0.12)',
      style: { color: n('profit') >= 0 ? '#10b981' : '#f43f5e' },
    },
    { label: t('Margin'), value: `${n('margin')}%`, icon: PercentageOutlined, color: '#6d28d9', tint: 'rgba(109, 40, 217, 0.12)' },
  ];
});

/* -------------------------------------------------------------- chart */
const chartData = computed(() => {
  const c = crud.payload.value?.chart || [];
  return dimension === 'date' ? c.map(r => ({ ...r, label: date(r.label) })) : c;
});
const chartFields = computed(() => [
  { key: 'profit', label: t('Profit') },
  { key: 'revenue', label: t('Revenue') },
]);

/* -------------------------------------------------------------- table */
const columns = computed(() => [
  { title: cfg.labelHeader, dataIndex: 'label', key: 'label', sorter: true },
  { title: t('Quantity'), dataIndex: 'qty', key: 'qty', sorter: true, align: 'right', sum: true },
  { title: t('Revenue'), key: 'revenue', dataIndex: 'revenue', sorter: true, align: 'right', sum: 'money', exportValue: r => money(r.revenue) },
  { title: t('Cost'), key: 'cost', dataIndex: 'cost', sorter: true, align: 'right', sum: 'money', exportValue: r => money(r.cost) },
  { title: t('Profit'), key: 'profit', dataIndex: 'profit', sorter: true, align: 'right', sum: 'money', exportValue: r => money(r.profit) },
  { title: t('Margin'), key: 'margin', dataIndex: 'margin', align: 'right', width: 160, exportValue: r => `${r.margin}%` },
]);

onMounted(crud.fetchRows);
</script>

<style scoped>
.filter-label {
  margin-bottom: 4px;
  color: rgba(0, 0, 0, 0.55);
  font-size: 13px;
}

/* KPI tiles — shared design with the ledger pages. */
.kpi-card {
  border-radius: 10px;
}
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
.kpi-text {
  min-width: 0;
  flex: 1 1 auto;
}
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

/* Margin cell: percent + a mini bar so margins compare at a glance. */
.margin-cell {
  display: flex;
  align-items: center;
  gap: 8px;
  justify-content: flex-end;
}
.margin-cell > span:first-child {
  font-weight: 600;
  min-width: 48px;
  text-align: right;
}
.margin-track {
  width: 56px;
  height: 5px;
  border-radius: 3px;
  background: rgba(128, 128, 128, 0.15);
  overflow: hidden;
  flex: none;
}
.margin-fill {
  display: block;
  height: 100%;
  border-radius: 3px;
}

@media (max-width: 575px) {
  .kpi-value {
    font-size: 16px;
  }
}
</style>
