<template>
  <ReportPage
    :title="$t('Zero_Sales_Products_Report')"
    :breadcrumb="[$t('Reports'), $t('Zero_Sales_Products_Report')]"
    :crud="crud"
    :columns="columns"
    row-key="id"
    export-endpoint="report/zero_sales_products"
    :export-params="filterParams"
    export-rows-key="report"
  >
    <template #actions>
      <!-- Multi-Currency: view amounts converted (display-only) -->
      <ViewCurrencySelect />
    </template>
    <!-- Dormant-catalogue overview across the WHOLE filtered set (backend
         summary / idle_buckets / by_category), not the visible page. -->
    <template #chart>
      <a-card size="small" class="band-card" style="margin-bottom: 16px">
        <div class="band">
          <div v-for="(cell, i) in bandCells" :key="cell.key" class="band-cell" :class="{ first: i === 0 }">
            <a-typography-text type="secondary" class="band-label">{{ cell.label }}</a-typography-text>
            <div class="band-value" :style="cell.color ? { color: cell.color } : null">
              <a-spin v-if="crud.loading.value" size="small" />
              <template v-else>{{ cell.value }}</template>
            </div>
          </div>
        </div>
      </a-card>

      <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
        <a-col :xs="24" :xl="15">
          <a-card size="small" class="chart-card" :title="tf('Time_Since_Last_Sale', 'Time since last sale')">
            <apexchart
              v-if="bucketTotal"
              type="bar"
              height="300"
              :options="bucketOptions"
              :series="bucketSeries"
            />
            <a-empty v-else :description="$t('NodataAvailable')" style="padding: 48px 0" />
          </a-card>
        </a-col>
        <a-col :xs="24" :xl="9">
          <a-card size="small" class="chart-card" :title="tf('Top_Dormant_Categories', 'Top dormant categories')">
            <template v-if="byCategory.length">
              <div v-for="c in byCategory" :key="c.name" class="cat-row">
                <a-typography-text class="cat-name">{{ c.name }}</a-typography-text>
                <div class="cat-meter">
                  <span class="cat-count">{{ fmt(c.count) }}</span>
                  <span class="cat-track">
                    <span class="cat-fill" :style="{ width: shareOf(c) + '%' }"></span>
                  </span>
                </div>
              </div>
            </template>
            <a-empty v-else :description="$t('NodataAvailable')" style="padding: 32px 0" />
          </a-card>
        </a-col>
      </a-row>
    </template>

    <template #filters>
      <a-select v-model:value="period" style="width: 180px" :options="periodOptions" @change="crud.reload()" />
      <a-select
        v-model:value="warehouseId"
        style="width: 200px"
        allow-clear
        show-search
        option-filter-prop="label"
        :placeholder="$t('warehouse')"
        :options="warehouseOptions"
        @change="crud.reload()"
      />
      <a-select
        v-model:value="categoryId" allow-clear show-search option-filter-prop="label" style="width: 200px"
        :placeholder="$t('Categorie')" :options="categoryOptions" @change="crud.reload()"
      />
      <a-select
        v-model:value="brandId" allow-clear show-search option-filter-prop="label" style="width: 200px"
        :placeholder="$t('Brand')" :options="brandOptions" @change="crud.reload()"
      />
    </template>

    <template #bodyCell="{ column, record }">
      <template v-if="column.key === 'price'">{{ money(record.price) }}</template>
      <template v-else-if="column.key === 'last_sale_at'">
        {{ record.last_sale_at ? date(record.last_sale_at) : '—' }}
      </template>
      <template v-else-if="column.key === 'status'">
        <a-tag :color="record.status === 'NeverSold' ? 'purple' : 'warning'" style="margin: 0">
          {{ record.status === 'NeverSold' ? tf('Never_Sold', 'Never sold') : tf('Inactive_In_Period', 'No recent sales') }}
        </a-tag>
      </template>
    </template>
  </ReportPage>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import ReportPage from '../../components/ReportPage.vue';
import ViewCurrencySelect from '../../components/ViewCurrencySelect.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { useUiStore } from '../../stores/ui';
import { t as tf } from '../../i18n';
import http from '../../lib/http';

const { t } = useI18n();
const { money, date } = useFormat();
const ui = useUiStore();

const period = ref(30);
const warehouseId = ref(undefined);
const brandId = ref(undefined);
const categoryId = ref(undefined);
const warehouses = ref([]);

const filterParams = () => ({
  period: period.value,
  ...(warehouseId.value ? { warehouse_id: warehouseId.value } : {}),
  ...(categoryId.value ? { category_id: categoryId.value } : {}),
  ...(brandId.value ? { brand_id: brandId.value } : {}),
});

// Payload: { report, totalRows, summary, idle_buckets, by_category, brands, categories }
const crud = useCrudTable('report/zero_sales_products', {
  rowsKey: 'report',
  sortField: 'last_sale_at',
  sortType: 'desc',
  params: filterParams,
});

const periodOptions = computed(() =>
  [30, 60, 90, 180, 365].map(d => ({ value: d, label: `${d} ${t('Days')}` }))
);
const warehouseOptions = computed(() => warehouses.value.map(w => ({ value: w.id, label: w.name })));
const brandOptions = computed(() => (crud.payload.value?.brands || []).map(b => ({ value: b.id, label: b.name })));
const categoryOptions = computed(() => (crud.payload.value?.categories || []).map(c => ({ value: c.id, label: c.name })));

const fmt = v => (Number(v) || 0).toLocaleString();

/* ------------------------------------------------------------ stat band */
const summary = computed(() => crud.payload.value?.summary || {});

const bandCells = computed(() => {
  const n = k => Number(summary.value[k]) || 0;
  return [
    { key: 'total', label: tf('Zero_Sales_Products', 'Zero-sales products'), value: fmt(n('total')) },
    { key: 'never', label: tf('Never_Sold', 'Never sold'), value: fmt(n('never_sold')), color: '#8b5cf6' },
    { key: 'inactive', label: tf('Inactive_In_Period', 'No recent sales'), value: fmt(n('inactive')), color: '#f59e0b' },
    { key: 'idle', label: tf('Avg_Days_Idle', 'Avg days idle'), value: n('avg_idle_days') ? fmt(n('avg_idle_days')) : '—' },
  ];
});

/* ------------------------------------------------------ staleness chart */
const BUCKET_LABELS = computed(() => ({
  le60: `≤ 60 ${t('Days')}`,
  d61_90: `61–90 ${t('Days')}`,
  d91_180: `91–180 ${t('Days')}`,
  d181_365: `181–365 ${t('Days')}`,
  gt365: `> 365 ${t('Days')}`,
  never: tf('Never_Sold', 'Never sold'),
}));

const buckets = computed(() => crud.payload.value?.idle_buckets || []);
const bucketTotal = computed(() => buckets.value.reduce((s, b) => s + (Number(b.count) || 0), 0));
const bucketSeries = computed(() => [{
  name: tf('Products', 'Products'),
  data: buckets.value.map(b => Number(b.count) || 0),
}]);
const bucketOptions = computed(() => {
  const axisColor = ui.dark ? '#d9d9d9' : '#595959';
  return {
    chart: { type: 'bar', background: 'transparent', fontFamily: 'inherit', toolbar: { show: false } },
    plotOptions: { bar: { columnWidth: '55%', borderRadius: 4, distributed: true } },
    // Staleness is a sequence: one hue stepping darker, "never sold" distinct.
    colors: ['#ddd6fe', '#c4b5fd', '#a78bfa', '#8b5cf6', '#6d28d9', '#f59e0b'],
    legend: { show: false },
    dataLabels: { enabled: false },
    xaxis: {
      categories: buckets.value.map(b => BUCKET_LABELS.value[b.key] || b.key),
      labels: { style: { colors: axisColor } },
      axisBorder: { show: false },
      axisTicks: { show: false },
    },
    yaxis: { labels: { style: { colors: axisColor } } },
    grid: { borderColor: 'rgba(128, 128, 128, 0.2)' },
    tooltip: { theme: ui.dark ? 'dark' : 'light' },
  };
});

/* -------------------------------------------------- dormant categories */
const byCategory = computed(() => crud.payload.value?.by_category || []);
const maxCategoryCount = computed(() =>
  Math.max(1, ...byCategory.value.map(c => Number(c.count) || 0))
);
function shareOf(c) {
  return Math.round(((Number(c.count) || 0) / maxCategoryCount.value) * 100);
}

/* ----------------------------------------------------------------- table */
const columns = computed(() => [
  { title: t('Code'), dataIndex: 'code', key: 'code', sorter: true },
  { title: t('Product'), dataIndex: 'name', key: 'name', sorter: true },
  { title: t('Price'), key: 'price', dataIndex: 'price', sorter: true, align: 'right', exportValue: r => money(r.price) },
  {
    title: t('LastSale'),
    key: 'last_sale_at',
    dataIndex: 'last_sale_at',
    sorter: true,
    exportValue: r => (r.last_sale_at ? date(r.last_sale_at) : ''),
  },
  { title: t('DaysSinceLastSale'), dataIndex: 'days_since_last_sale', key: 'days_since_last_sale', sorter: true, align: 'right' },
  {
    title: t('Status'),
    key: 'status',
    dataIndex: 'status',
    exportValue: r => (r.status === 'NeverSold' ? tf('Never_Sold', 'Never sold') : tf('Inactive_In_Period', 'No recent sales')),
  },
]);

async function loadWarehouses() {
  try {
    const data = await http.get('warehouses', { page: 1, SortField: 'id', SortType: 'desc', search: '', limit: -1 });
    warehouses.value = data.warehouses || [];
  } catch (e) { /* filter stays empty; report still works */ }
}

onMounted(() => {
  crud.fetchRows();
  loadWarehouses();
});
</script>

<style scoped>
.band-card,
.chart-card { border-radius: 10px; }

/* Divided stat band — one card, cells split by hairlines (wraps on mobile). */
.band {
  display: flex;
  flex-wrap: wrap;
  gap: 12px 0;
}
.band-cell {
  flex: 1 1 140px;
  min-width: 140px;
  padding: 2px 20px;
  border-inline-start: 1px solid rgba(128, 128, 128, 0.2);
}
.band-cell.first {
  border-inline-start: none;
  padding-inline-start: 4px;
}
.band-label {
  display: block;
  font-size: 12px;
  margin-bottom: 2px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.band-value {
  font-size: 22px;
  font-weight: 700;
  line-height: 1.25;
  white-space: nowrap;
}

/* Dormant category meters. */
.cat-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 8px 4px;
}
.cat-row + .cat-row { border-top: 1px solid rgba(128, 128, 128, 0.14); }
.cat-name {
  min-width: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.cat-meter {
  display: flex;
  align-items: center;
  gap: 8px;
  flex: none;
}
.cat-count {
  font-weight: 600;
  min-width: 34px;
  text-align: end;
}
.cat-track {
  display: inline-block;
  width: 90px;
  height: 6px;
  border-radius: 999px;
  background: rgba(128, 128, 128, 0.2);
  overflow: hidden;
}
.cat-fill {
  display: block;
  height: 100%;
  border-radius: 999px;
  background: #8b5cf6;
}
</style>
