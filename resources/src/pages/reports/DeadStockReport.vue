<template>
  <ReportPage
    :title="$t('Dead_Stock_Report')"
    :breadcrumb="[$t('Reports'), $t('Dead_Stock_Report')]"
    :crud="crud"
    :columns="columns"
    row-key="product_id"
    export-endpoint="report/dead_stock"
    :export-params="filterParams"
    export-rows-key="report"
  >
    <template #actions>
      <!-- Multi-Currency: view amounts converted (display-only) -->
      <ViewCurrencySelect />
    </template>
    <!-- Frozen-capital view over the WHOLE filtered set (backend summary /
         top_frozen / by_category), not the visible page. -->
    <template #chart>
      <a-card size="small" class="freeze-banner" :class="{ clear: !hasDeadStock }" style="margin-bottom: 16px">
        <div class="banner-inner">
          <span class="banner-icon" :class="{ clear: !hasDeadStock }">
            <CheckCircleOutlined v-if="!hasDeadStock && !crud.loading.value" />
            <PauseCircleOutlined v-else />
          </span>
          <div class="banner-text">
            <div class="banner-headline">
              <a-spin v-if="crud.loading.value" size="small" />
              <template v-else-if="hasDeadStock">
                {{ money(summary.frozen_value) }}
                <span class="banner-dim">{{ tf('Frozen_In_Dead_Stock', 'frozen in dead stock') }}</span>
              </template>
              <template v-else>{{ tf('No_Dead_Stock', 'No dead stock in this period.') }}</template>
            </div>
            <a-typography-text v-if="hasDeadStock && !crud.loading.value" type="secondary">
              {{ fmt(summary.products) }} {{ $t('Products') }}
              · {{ fmt(summary.units) }} {{ tf('Units_On_Hand', 'units on hand') }}
              · {{ fmt(summary.never_moved) }} {{ tf('Never_Moved', 'never moved') }}
            </a-typography-text>
            <a-typography-text v-else-if="!crud.loading.value" type="secondary">
              {{ tf('No_Dead_Stock_Hint', 'Every product had at least one movement in the selected period.') }}
            </a-typography-text>
          </div>
        </div>
      </a-card>

      <a-row v-if="hasDeadStock" :gutter="[16, 16]" style="margin-bottom: 16px">
        <a-col :xs="24" :xl="15">
          <a-card size="small" class="chart-card" :title="tf('Where_Money_Is_Stuck', 'Where the money is stuck')">
            <apexchart
              v-if="frozenSeries[0].data.length"
              type="bar"
              height="320"
              :options="frozenOptions"
              :series="frozenSeries"
            />
            <a-empty v-else :description="$t('NodataAvailable')" style="padding: 48px 0" />
          </a-card>
        </a-col>
        <a-col :xs="24" :xl="9">
          <a-card size="small" class="chart-card" :title="tf('By_Category', 'By category')">
            <template v-if="byCategory.length">
              <div v-for="c in byCategory" :key="c.name" class="cat-row">
                <div class="cat-text">
                  <a-typography-text strong class="cat-name">{{ c.name }}</a-typography-text>
                  <a-typography-text type="secondary" class="cat-sub">
                    {{ fmt(c.count) }} {{ $t('Products') }}
                  </a-typography-text>
                </div>
                <div class="cat-meter">
                  <span class="cat-value">{{ money(c.value) }}</span>
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
      <a-select
        v-model:value="period"
        style="width: 180px"
        :options="periodOptions"
        @change="crud.reload()"
      />
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
      <template v-if="column.key === 'last_movement_at'">
        {{ record.last_movement_at ? date(record.last_movement_at) : '—' }}
      </template>
      <template v-else-if="column.key === 'days_since_last_movement'">
        <a-tag :color="daysColor(record.days_since_last_movement)">
          {{ record.days_since_last_movement ?? '—' }}
        </a-tag>
      </template>
      <template v-else-if="column.key === 'status'">
        <a-tag :color="record.status === 'NeverMoved' ? 'purple' : 'warning'" style="margin: 0">
          {{ record.status === 'NeverMoved' ? tf('Never_Moved', 'Never moved') : tf('No_Movement_In_Period', 'No recent movement') }}
        </a-tag>
      </template>
    </template>
  </ReportPage>
</template>

<script setup>
/**
 * REFERENCE REPORT PAGE — copy this for the other reports.
 *
 * Reports reuse the same list contract as CRUD pages (page/SortField/SortType/
 * search/limit) with extra filter params, and answer { report, totalRows }, so
 * useCrudTable drives them via `rowsKey` + `params`.
 */
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { PauseCircleOutlined, CheckCircleOutlined } from '@ant-design/icons-vue';
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

const period = ref(90);
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

// Payload: { report, totalRows, summary, top_frozen, by_category, brands, categories }
const crud = useCrudTable('report/dead_stock', {
  rowsKey: 'report',
  sortField: 'days_since_last_movement',
  sortType: 'desc',
  params: filterParams,
});

const periodOptions = computed(() => [
  { value: 30, label: `30 ${t('Days')}` },
  { value: 60, label: `60 ${t('Days')}` },
  { value: 90, label: `90 ${t('Days')}` },
  { value: 180, label: `180 ${t('Days')}` },
  { value: 365, label: `365 ${t('Days')}` },
]);

const warehouseOptions = computed(() =>
  warehouses.value.map(w => ({ value: w.id, label: w.name }))
);
// brands / categories come from the report payload.
const brandOptions = computed(() => (crud.payload.value?.brands || []).map(b => ({ value: b.id, label: b.name })));
const categoryOptions = computed(() => (crud.payload.value?.categories || []).map(c => ({ value: c.id, label: c.name })));

const fmt = v => (Number(v) || 0).toLocaleString();

/* ---------------------------------------------------------------- banner */
const summary = computed(() => crud.payload.value?.summary || {});
const hasDeadStock = computed(() => (Number(summary.value.products) || 0) > 0);

/* -------------------------------------------------- frozen value (bars) */
const frozenRows = computed(() => crud.payload.value?.top_frozen || []);
const frozenSeries = computed(() => [{
  name: tf('Frozen_Value', 'Frozen value'),
  data: frozenRows.value.map(r => Number(r.value) || 0),
}]);
const frozenOptions = computed(() => {
  const axisColor = ui.dark ? '#d9d9d9' : '#595959';
  return {
    chart: { type: 'bar', background: 'transparent', fontFamily: 'inherit', toolbar: { show: false } },
    plotOptions: { bar: { horizontal: true, borderRadius: 4, barHeight: '60%' } },
    colors: ['#0ea5e9'],
    dataLabels: { enabled: false },
    xaxis: {
      categories: frozenRows.value.map(r => r.name),
      labels: { style: { colors: axisColor }, formatter: v => money(v) },
      axisBorder: { show: false },
      axisTicks: { show: false },
    },
    yaxis: { labels: { style: { colors: axisColor }, maxWidth: 220 } },
    grid: { borderColor: 'rgba(128, 128, 128, 0.2)' },
    tooltip: { theme: ui.dark ? 'dark' : 'light', y: { formatter: v => money(v) } },
  };
});

/* ---------------------------------------------------- category meters */
const byCategory = computed(() => crud.payload.value?.by_category || []);
const maxCategoryValue = computed(() =>
  Math.max(1, ...byCategory.value.map(c => Number(c.value) || 0))
);
function shareOf(c) {
  return Math.round(((Number(c.value) || 0) / maxCategoryValue.value) * 100);
}

/* ----------------------------------------------------------------- table */
const columns = computed(() => [
  { title: t('Code'), dataIndex: 'code', key: 'code', sorter: true },
  { title: t('Product'), dataIndex: 'product_name', key: 'product_name', sorter: true },
  { title: t('OnHand'), dataIndex: 'on_hand', key: 'on_hand', sorter: true, align: 'right', sum: true },
  {
    title: t('LastMovement'),
    key: 'last_movement_at',
    dataIndex: 'last_movement_at',
    sorter: true,
    exportValue: r => (r.last_movement_at ? date(r.last_movement_at) : ''),
  },
  {
    title: t('DaysSinceLastMovement'),
    key: 'days_since_last_movement',
    dataIndex: 'days_since_last_movement',
    sorter: true,
    align: 'right',
    exportValue: r => r.days_since_last_movement ?? '',
  },
  {
    title: t('Status'),
    key: 'status',
    dataIndex: 'status',
    exportValue: r => (r.status === 'NeverMoved' ? tf('Never_Moved', 'Never moved') : tf('No_Movement_In_Period', 'No recent movement')),
  },
]);

function daysColor(days) {
  const n = Number(days);
  if (!Number.isFinite(n)) return 'default';
  if (n >= 180) return 'error';
  if (n >= 90) return 'warning';
  return 'default';
}

async function loadWarehouses() {
  try {
    const data = await http.get('warehouses', { page: 1, SortField: 'id', SortType: 'desc', search: '', limit: -1 });
    warehouses.value = data.warehouses || [];
  } catch (e) {
    // Filter stays empty; the report still works.
  }
}

onMounted(() => {
  crud.fetchRows();
  loadWarehouses();
});
</script>

<style scoped>
.chart-card { border-radius: 10px; }

/* Frozen-capital banner: cyan accent while dead stock exists, green when clear. */
.freeze-banner {
  border-radius: 10px;
  border-inline-start: 4px solid #0ea5e9;
  background: rgba(14, 165, 233, 0.05);
}
.freeze-banner.clear {
  border-inline-start-color: #10b981;
  background: rgba(16, 185, 129, 0.05);
}
.banner-inner {
  display: flex;
  align-items: center;
  gap: 14px;
}
.banner-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 44px;
  height: 44px;
  border-radius: 12px;
  font-size: 22px;
  flex: none;
  color: #0ea5e9;
  background: rgba(14, 165, 233, 0.12);
}
.banner-icon.clear {
  color: #10b981;
  background: rgba(16, 185, 129, 0.12);
}
.banner-text { min-width: 0; }
.banner-headline {
  font-size: 18px;
  font-weight: 700;
  line-height: 1.3;
}
.banner-dim {
  font-weight: 400;
  font-size: 14px;
  opacity: 0.65;
}

/* Category rows: name + product count, frozen value + share meter. */
.cat-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 8px 4px;
}
.cat-row + .cat-row { border-top: 1px solid rgba(128, 128, 128, 0.14); }
.cat-text {
  min-width: 0;
  display: flex;
  flex-direction: column;
}
.cat-name,
.cat-sub {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.cat-sub { font-size: 12px; }
.cat-meter {
  display: flex;
  align-items: center;
  gap: 8px;
  flex: none;
}
.cat-value {
  font-weight: 600;
  color: #0ea5e9;
}
.cat-track {
  display: inline-block;
  width: 80px;
  height: 6px;
  border-radius: 999px;
  background: rgba(128, 128, 128, 0.2);
  overflow: hidden;
}
.cat-fill {
  display: block;
  height: 100%;
  border-radius: 999px;
  background: #0ea5e9;
}
</style>
