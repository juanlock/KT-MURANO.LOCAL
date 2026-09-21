<template>
  <ReportPage
    :title="$t('stock_report')"
    :breadcrumb="[$t('Reports'), $t('stock_report')]"
    :crud="crud"
    :columns="columns"
    row-key="id"
    export-endpoint="report/stock"
    :export-params="filterParams"
    export-rows-key="report"
  >
    <!-- Stock health over the WHOLE filtered set (backend summary /
         category_units / low_stock), not the visible page. -->
    <template #chart>
      <a-card size="small" class="health-card" style="margin-bottom: 16px">
        <div class="health-head">
          <div class="health-headline">
            <a-spin v-if="crud.loading.value" size="small" />
            <template v-else>
              {{ fmt(summary.units) }}
              <span class="health-headline-unit">{{ tf('Units_On_Hand', 'units on hand') }}</span>
            </template>
          </div>
          <a-typography-text type="secondary">
            {{ fmt(summary.products) }} {{ $t('Products') }}
          </a-typography-text>
        </div>

        <!-- Distribution of products across health states. -->
        <div class="health-bar" role="img" :aria-label="tf('Stock_Health', 'Stock health')">
          <span
            v-for="s in healthStates"
            v-show="s.count > 0"
            :key="s.key"
            class="health-seg"
            :style="{ background: s.color, flexGrow: s.count }"
          />
          <span v-if="!healthTotal" class="health-seg health-seg-empty" />
        </div>

        <div class="health-legend">
          <span v-for="s in healthStates" :key="s.key" class="health-chip">
            <span class="health-dot" :style="{ background: s.color }" />
            {{ s.label }}
            <strong>{{ fmt(s.count) }}</strong>
          </span>
        </div>
      </a-card>

      <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
        <a-col :xs="24" :xl="9">
          <a-card size="small" class="chart-card" :title="tf('Low_Stock_Watchlist', 'Low stock watchlist')">
            <template v-if="lowStock.length">
              <div
                v-for="p in lowStock"
                :key="p.id"
                class="watch-row"
                role="link"
                tabindex="0"
                @click="$router.push(`/reports/stock/${p.id}`)"
                @keydown.enter="$router.push(`/reports/stock/${p.id}`)"
              >
                <div class="watch-text">
                  <a-typography-text strong class="watch-name">{{ p.name }}</a-typography-text>
                  <a-typography-text type="secondary" class="watch-code">
                    {{ p.code }}<template v-if="Number(p.stock_alert) > 0"> · {{ tf('Alert_At', 'alert at') }} {{ fmt(p.stock_alert) }}</template>
                  </a-typography-text>
                </div>
                <a-tag :color="Number(p.qty) <= 0 ? 'error' : 'warning'" style="margin: 0">
                  {{ fmt(p.qty) }}
                </a-tag>
              </div>
            </template>
            <a-empty v-else :description="tf('All_Stock_Healthy', 'All products are above their alert levels.')" style="padding: 32px 0" />
          </a-card>
        </a-col>
        <a-col :xs="24" :xl="15">
          <a-card size="small" class="chart-card" :title="tf('Units_By_Category', 'Units by category')">
            <apexchart
              v-if="treemapSeries[0].data.length"
              type="treemap"
              height="320"
              :options="treemapOptions"
              :series="treemapSeries"
            />
            <a-empty v-else :description="$t('NodataAvailable')" style="padding: 48px 0" />
          </a-card>
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
      <template v-if="column.key === 'quantity'">
        <a-tag :color="Number(record.quantity) <= 0 ? 'error' : 'default'">{{ record.quantity }}</a-tag>
      </template>
      <template v-else-if="column.key === 'actions'">
        <a-tooltip :title="$t('Reports')">
          <a-button type="text" size="small" @click="$router.push(`/reports/stock/${record.id}`)">
            <template #icon><EyeOutlined /></template>
          </a-button>
        </a-tooltip>
      </template>
    </template>
  </ReportPage>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { EyeOutlined } from '@ant-design/icons-vue';
import ReportPage from '../../components/ReportPage.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useUiStore } from '../../stores/ui';
import { t as tf } from '../../i18n';

const { t } = useI18n();
const ui = useUiStore();

const warehouseId = ref(undefined);
const filterParams = () => ({ warehouse_id: warehouseId.value || '' });

// Payload: { report, totalRows, summary, category_units, low_stock, warehouses }
const crud = useCrudTable('report/stock', {
  rowsKey: 'report',
  sortField: 'quantity',
  sortType: 'asc',
  params: filterParams,
});

const warehouseOptions = computed(() =>
  (crud.payload.value?.warehouses || []).map(w => ({ value: w.id, label: w.name }))
);

const fmt = v => (Number(v) || 0).toLocaleString();

/* ------------------------------------------------------- health strip */
const summary = computed(() => crud.payload.value?.summary || {});

// Color follows the STATE: healthy green, low amber, out rose, negative deep red.
const healthStates = computed(() => [
  { key: 'healthy', label: tf('Healthy', 'Healthy'), color: '#10b981', count: Number(summary.value.healthy) || 0 },
  { key: 'low', label: tf('Low_Stock', 'Low stock'), color: '#f59e0b', count: Number(summary.value.low) || 0 },
  { key: 'out', label: tf('OutOfStock', 'Out of stock'), color: '#f43f5e', count: Number(summary.value.out_of_stock) || 0 },
  { key: 'negative', label: tf('Negative_Stock', 'Negative'), color: '#b91c1c', count: Number(summary.value.negative) || 0 },
]);
const healthTotal = computed(() => healthStates.value.reduce((s, x) => s + x.count, 0));

/* ---------------------------------------------------------- watchlist */
const lowStock = computed(() => crud.payload.value?.low_stock || []);

/* ------------------------------------------------------------ treemap */
const TREEMAP_COLORS = ['#6366f1', '#22d3ee', '#f59e0b', '#ec4899', '#10b981', '#8b5cf6', '#0ea5e9', '#f43f5e', '#a3e635', '#94a3b8'];

const treemapSeries = computed(() => [{
  data: (crud.payload.value?.category_units || []).map(c => ({ x: c.name, y: Number(c.value) || 0 })),
}]);
const treemapOptions = computed(() => ({
  chart: { type: 'treemap', background: 'transparent', fontFamily: 'inherit', toolbar: { show: false } },
  colors: TREEMAP_COLORS,
  plotOptions: { treemap: { distributed: true, enableShades: false } },
  dataLabels: { enabled: true, style: { fontSize: '12px' } },
  stroke: { colors: [ui.dark ? '#1f1f1f' : '#ffffff'], width: 2 },
  legend: { show: false },
  tooltip: {
    theme: ui.dark ? 'dark' : 'light',
    y: { formatter: v => `${fmt(v)} ${tf('Units', 'units')}` },
  },
}));

/* -------------------------------------------------------------- table */
const columns = computed(() => [
  { title: t('ProductCode'), dataIndex: 'code', key: 'code', sorter: true },
  { title: t('ProductName'), dataIndex: 'name', key: 'name', sorter: true },
  { title: t('Categorie'), dataIndex: 'category', key: 'category' },
  { title: t('Quantity'), key: 'quantity', dataIndex: 'quantity', sorter: true, align: 'right', sum: true, exportValue: r => r.quantity },
  { title: t('Action'), key: 'actions', align: 'center', width: 80, exportable: false },
]);

onMounted(crud.fetchRows);
</script>

<style scoped>
.health-card,
.chart-card { border-radius: 10px; }

.health-head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 12px;
}
.health-headline {
  font-size: 26px;
  font-weight: 700;
  line-height: 1.2;
}
.health-headline-unit {
  font-size: 13px;
  font-weight: 400;
  opacity: 0.65;
  margin-inline-start: 4px;
}

/* Segmented distribution bar — one flex-grown segment per health state. */
.health-bar {
  display: flex;
  gap: 2px;
  height: 10px;
  border-radius: 999px;
  overflow: hidden;
  margin-bottom: 12px;
}
.health-seg {
  display: block;
  min-width: 6px;
  border-radius: 999px;
}
.health-seg-empty {
  flex: 1;
  background: rgba(128, 128, 128, 0.18);
}

.health-legend {
  display: flex;
  flex-wrap: wrap;
  gap: 8px 18px;
}
.health-chip {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  font-size: 13px;
}
.health-chip strong { font-size: 13px; }
.health-dot {
  width: 9px;
  height: 9px;
  border-radius: 999px;
  flex: none;
}

/* Watchlist rows — compact, clickable, keyboard reachable. */
.watch-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 8px 6px;
  border-radius: 8px;
  cursor: pointer;
}
.watch-row:hover,
.watch-row:focus-visible {
  background: rgba(128, 128, 128, 0.1);
  outline: none;
}
.watch-row + .watch-row {
  border-top: 1px solid rgba(128, 128, 128, 0.14);
  border-radius: 0 0 8px 8px;
}
.watch-text {
  min-width: 0;
  display: flex;
  flex-direction: column;
}
.watch-name,
.watch-code {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.watch-code { font-size: 12px; }
</style>
