<template>
  <ReportPage
    :title="$t('Inventory_Valuation')"
    :breadcrumb="[$t('Reports'), $t('Inventory_Valuation')]"
    :crud="crud"
    :columns="columns"
    row-key="id"
    export-endpoint="report/inventory_valuation_summary"
    :export-params="filterParams"
    export-rows-key="reports"
  >
    <template #actions>
      <!-- Multi-Currency: view amounts converted (display-only) -->
      <ViewCurrencySelect />
    </template>
    <!-- Summary + charts over the WHOLE filtered set (backend summary /
         top_products / warehouse_values), not the visible page. -->
    <template #chart>
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
                  <a-spin v-if="crud.loading.value" size="small" />
                  <template v-else>{{ k.value }}</template>
                </div>
              </div>
            </div>
          </a-card>
        </a-col>
      </a-row>

      <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
        <a-col :xs="24" :xl="9">
          <a-card size="small" class="chart-card" :title="tf('Top_Products_By_Value', 'Top products by value')">
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
          <ReportChart
            :data="crud.payload.value?.warehouse_values"
            :fields="[{ key: 'value', label: t('ASSET_VALUE') }]"
            :title="tf('Value_By_Warehouse', 'Asset value by warehouse')"
            type="bar"
            x-key="name"
            :format="money"
            :height="300"
          />
        </a-col>
      </a-row>
    </template>

    <template #filters>
      <a-select
        v-model:value="warehouseId"
        style="width: 220px"
        allow-clear
        show-search
        option-filter-prop="label"
        :placeholder="$t('warehouse')"
        :options="warehouseOptions"
        @change="crud.reload()"
      />
    </template>

    <template #bodyCell="{ column, record }">
      <template v-if="column.key === 'cost'">{{ money(record.cost) }}</template>
      <template v-else-if="column.key === 'inventory_value'">
        <strong>{{ money(record.inventory_value) }}</strong>
      </template>
    </template>
  </ReportPage>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  DollarOutlined, DatabaseOutlined, AppstoreOutlined, CheckCircleOutlined,
} from '@ant-design/icons-vue';
import ReportPage from '../../components/ReportPage.vue';
import ReportChart from '../../components/ReportChart.vue';
import ViewCurrencySelect from '../../components/ViewCurrencySelect.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { useUiStore } from '../../stores/ui';
import { t as tf } from '../../i18n';

const { t } = useI18n();
const { money } = useFormat();
const ui = useUiStore();

const warehouseId = ref(undefined);
const filterParams = () => ({ warehouse_id: warehouseId.value || '' });

// Payload: { reports, totalRows, summary, top_products, warehouse_values, warehouses }
const crud = useCrudTable('report/inventory_valuation_summary', {
  rowsKey: 'reports',
  sortField: 'inventory_value',
  sortType: 'desc',
  params: filterParams,
});

const warehouseOptions = computed(() =>
  (crud.payload.value?.warehouses || []).map(w => ({ value: w.id, label: w.name }))
);

/* ------------------------------------------------------------- summary */
const summary = computed(() => crud.payload.value?.summary || {});

const kpiTiles = computed(() => {
  const n = k => Number(summary.value[k]) || 0;
  return [
    { key: 'value', label: t('ASSET_VALUE'), value: money(n('asset_value')), icon: DollarOutlined, color: '#6d28d9', tint: 'rgba(109, 40, 217, 0.12)' },
    { key: 'units', label: tf('Units_On_Hand', 'Units on hand'), value: n('units'), icon: DatabaseOutlined, color: '#1677ff', tint: 'rgba(22, 119, 255, 0.12)' },
    { key: 'products', label: t('Products'), value: n('products'), icon: AppstoreOutlined, color: '#13c2c2', tint: 'rgba(19, 194, 194, 0.12)' },
    { key: 'in_stock', label: tf('In_Stock', 'In stock'), value: `${n('in_stock')} / ${n('products')}`, icon: CheckCircleOutlined, color: '#22c55e', tint: 'rgba(34, 197, 94, 0.12)' },
  ];
});

/* --------------------------------------------------------------- donut */
const DONUT_COLORS = ['#6366f1', '#22d3ee', '#f59e0b', '#ec4899', '#10b981', '#8b5cf6', '#0ea5e9', '#94a3b8'];

const donutRows = computed(() => crud.payload.value?.top_products || []);
const donutSeries = computed(() => donutRows.value.map(r => Number(r.value) || 0));
const donutOptions = computed(() => ({
  chart: { type: 'donut', background: 'transparent', fontFamily: 'inherit' },
  labels: donutRows.value.map(r => r.name),
  colors: DONUT_COLORS,
  legend: { position: 'bottom', labels: { colors: ui.dark ? '#d9d9d9' : '#595959' } },
  dataLabels: { enabled: false },
  stroke: { colors: [ui.dark ? '#1f1f1f' : '#ffffff'], width: 3 },
  plotOptions: { pie: { donut: { size: '70%' } } },
  tooltip: { theme: ui.dark ? 'dark' : 'light', y: { formatter: v => money(v) } },
}));

/* --------------------------------------------------------------- table */
const columns = computed(() => [
  { title: t('ITEM_NAME'), dataIndex: 'name', key: 'name', sorter: true },
  { title: t('SKU'), dataIndex: 'code', key: 'code', sorter: true },
  { title: t('Variant_NAME'), dataIndex: 'variant_name', key: 'variant_name' },
  { title: t('STOCK_ON_HAND'), dataIndex: 'stock_hand', key: 'stock_hand', sorter: true, align: 'right', sum: true },
  { title: t('Cost'), key: 'cost', dataIndex: 'cost', align: 'right', exportValue: r => money(r.cost) },
  { title: t('ASSET_VALUE'), key: 'inventory_value', dataIndex: 'inventory_value', sorter: true, align: 'right', sum: 'money', exportValue: r => money(r.inventory_value) },
]);

onMounted(crud.fetchRows);
</script>

<style scoped>
/* Same tile anatomy as the other report KPIs; labels use antd's secondary
   text token so they stay readable in dark mode. */
.kpi-card,
.chart-card { border-radius: 10px; }
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
@media (max-width: 575px) {
  .kpi-value { font-size: 16px; }
}
</style>
