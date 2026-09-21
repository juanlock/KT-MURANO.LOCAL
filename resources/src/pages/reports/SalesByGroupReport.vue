<template>
  <ReportPage
    :title="$t(kind.titleKey)"
    :breadcrumb="[$t('Reports'), $t(kind.titleKey)]"
    :crud="crud"
    :columns="columns"
    :row-key="kind.nameKey"
    :export-endpoint="kind.endpoint"
    :export-params="filterParams"
    export-rows-key="reports"
  >
    <template #actions>
      <!-- Multi-Currency: view amounts converted (display-only) -->
      <ViewCurrencySelect />
    </template>
    <!-- Summary + charts over the WHOLE filtered set (backend summary/top_groups/timeseries). -->
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
                <div class="kpi-value" :title="typeof k.value === 'string' ? k.value : undefined">
                  <a-spin v-if="crud.loading.value" size="small" />
                  <template v-else>{{ k.value }}</template>
                </div>
                <a-typography-text v-if="k.sub && !crud.loading.value" type="secondary" class="kpi-sub">
                  {{ k.sub }}
                </a-typography-text>
              </div>
            </div>
          </a-card>
        </a-col>
      </a-row>

      <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
        <a-col :xs="24" :xl="9">
          <a-card size="small" class="chart-card" :title="tf(kind.shareTitleKey, kind.shareTitleFallback)">
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
            :data="crud.payload.value?.timeseries"
            :fields="[{ key: 'amount', label: t('TotalSales') }]"
            :title="tf('Sales_over_time', 'Sales over time')"
            type="area"
            x-key="d"
            :format="money"
            :height="300"
          />
        </a-col>
      </a-row>
    </template>

    <template #filters>
      <DateRangePicker v-model:value="range" allow-clear @change="crud.reload()" />
    </template>

    <template #bodyCell="{ column, record }">
      <template v-if="column.key === kind.nameKey">
        <span style="font-weight: 500">{{ record[kind.nameKey] }}</span>
      </template>
      <template v-else-if="column.key === 'total_sales'">
        <strong>{{ money(record.total_sales) }}</strong>
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
  </ReportPage>
</template>

<script setup>
/**
 * One component for sales-by-category and sales-by-brand — identical contracts
 * with a different grouping column. `meta.kind` picks it; router-view is keyed
 * by path so switching remounts (endpoint is captured at setup).
 *
 * Payload: { reports, totalRows, summary, top_groups, timeseries, currency } —
 * summary/top_groups/timeseries cover the whole filtered set, not the page.
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import {
  DollarOutlined, ShoppingOutlined, TagsOutlined, TrophyOutlined,
} from '@ant-design/icons-vue';
import ReportPage from '../../components/ReportPage.vue';
import ReportChart from '../../components/ReportChart.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { useUiStore } from '../../stores/ui';
import { t as tf } from '../../i18n';
import DateRangePicker from '../../components/DateRangePicker.vue';
import ViewCurrencySelect from '../../components/ViewCurrencySelect.vue';

const { t } = useI18n();
const { money } = useFormat();
const route = useRoute();
const ui = useUiStore();

const KINDS = {
  category: {
    endpoint: 'report/sales_by_category_report',
    titleKey: 'Sales_by_Category',
    nameKey: 'category_name',
    nameLabel: 'Categorie',
    activeFallback: 'Categories with sales',
    topFallback: 'Top category',
    shareTitleKey: 'Share_by_Category',
    shareTitleFallback: 'Share by category',
  },
  brand: {
    endpoint: 'report/sales_by_brand_report',
    titleKey: 'Sales_by_Brand',
    nameKey: 'brand_name',
    nameLabel: 'Brand',
    activeFallback: 'Brands with sales',
    topFallback: 'Top brand',
    shareTitleKey: 'Share_by_Brand',
    shareTitleFallback: 'Share by brand',
  },
};

const kind = KINDS[route.meta.kind] || KINDS.category;

const range = ref(null);

const filterParams = () => ({
  ...(range.value?.[0] ? { from: range.value[0].format('YYYY-MM-DD') } : {}),
  ...(range.value?.[1] ? { to: range.value[1].format('YYYY-MM-DD') } : {}),
});

const crud = useCrudTable(kind.endpoint, {
  rowsKey: 'reports',
  sortField: 'total_sales',
  sortType: 'desc',
  params: filterParams,
});

const summary = computed(() => crud.payload.value?.summary || {});

/* ----------------------------------------------------------------- KPIs */
const kpiTiles = computed(() => {
  const s = summary.value;
  const n = k => Number(s[k]) || 0;
  return [
    { key: 'total', label: t('TotalSales'), value: money(n('total_sales')), icon: DollarOutlined, color: '#6d28d9', tint: 'rgba(109, 40, 217, 0.12)' },
    { key: 'items', label: tf('Items_Sold', 'Items sold'), value: n('items_sold'), icon: ShoppingOutlined, color: '#1677ff', tint: 'rgba(22, 119, 255, 0.12)' },
    {
      key: 'active',
      label: tf(kind.shareTitleKey === 'Share_by_Brand' ? 'Active_Brands' : 'Active_Categories', kind.activeFallback),
      value: `${n('groups_with_sales')} / ${n('groups_total')}`,
      icon: TagsOutlined, color: '#13c2c2', tint: 'rgba(19, 194, 194, 0.12)',
    },
    {
      key: 'top',
      label: tf(kind.shareTitleKey === 'Share_by_Brand' ? 'Top_Brand' : 'Top_Category', kind.topFallback),
      value: s.top_name || '—',
      sub: s.top_name ? money(n('top_total')) : '',
      icon: TrophyOutlined, color: '#22c55e', tint: 'rgba(34, 197, 94, 0.12)',
    },
  ];
});

/* ---------------------------------------------------------------- donut */
const donutRows = computed(() => crud.payload.value?.top_groups || []);
const donutSeries = computed(() => donutRows.value.map(r => Number(r.value) || 0));
const donutOptions = computed(() => ({
  chart: { type: 'donut', background: 'transparent', fontFamily: 'inherit' },
  labels: donutRows.value.map(r => r.name),
  colors: ['#6366f1', '#22d3ee', '#f59e0b', '#ec4899', '#10b981', '#8b5cf6', '#0ea5e9', '#94a3b8'],
  legend: { position: 'bottom', labels: { colors: ui.dark ? '#d9d9d9' : '#595959' } },
  dataLabels: { enabled: false },
  stroke: { colors: [ui.dark ? '#1f1f1f' : '#ffffff'], width: 3 },
  plotOptions: { pie: { donut: { size: '70%' } } },
  tooltip: { theme: ui.dark ? 'dark' : 'light', y: { formatter: v => money(v) } },
}));

/* ---------------------------------------------------------------- table */
function shareOf(record) {
  const total = Number(summary.value.total_sales) || 0;
  if (!total) return 0;
  return Math.round(((Number(record.total_sales) || 0) / total) * 1000) / 10;
}

const columns = computed(() => [
  { title: t(kind.nameLabel), dataIndex: kind.nameKey, key: kind.nameKey, sorter: true },
  { title: tf('Orders', 'Orders'), dataIndex: 'orders', key: 'orders', sorter: true, align: 'right', width: 110 },
  { title: tf('Products_Sold', 'Products sold'), dataIndex: 'products_sold', key: 'products_sold', align: 'right', width: 130 },
  { title: tf('Items_Sold', 'Items sold'), dataIndex: 'items_sold', key: 'items_sold', sorter: true, align: 'right', sum: true, width: 120 },
  { title: t('TotalSales'), key: 'total_sales', dataIndex: 'total_sales', sorter: true, align: 'right', sum: 'money', exportValue: r => money(r.total_sales) },
  { title: tf('Share', 'Share'), key: 'share', align: 'right', width: 170, exportValue: r => `${shareOf(r)}%` },
]);

onMounted(crud.fetchRows);
</script>

<style scoped>
/* Same tile anatomy as the Expenses/Sales report KPIs; labels use antd's
   secondary text token so they stay readable in dark mode. */
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
.kpi-sub {
  display: block;
  font-size: 12px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.chart-card { border-radius: 10px; }

/* Share cell: percent + a mini bar so group weight reads at a glance. */
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
  display: inline-block;
  width: 80px;
  height: 6px;
  border-radius: 999px;
  background: rgba(128, 128, 128, 0.2);
  overflow: hidden;
}
.share-fill {
  display: block;
  height: 100%;
  border-radius: 999px;
  background: #6d28d9;
}
@media (max-width: 575px) {
  .kpi-value { font-size: 16px; }
}
</style>
