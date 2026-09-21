<template>
  <ReportPage
    :title="$t('Draft_Invoices_Report')"
    :breadcrumb="[$t('Reports'), $t('Draft_Invoices_Report')]"
    :crud="crud"
    :columns="columns"
    row-key="id"
    export-endpoint="report/draft_invoices"
    :export-params="filterParams"
    export-rows-key="report"
  >
    <template #actions>
      <!-- Multi-Currency: view amounts converted (display-only) -->
      <ViewCurrencySelect />
    </template>
    <!-- Pending-pipeline view over the WHOLE filtered set (backend summary /
         age_buckets / by_user), not the visible page. -->
    <template #chart>
      <a-card size="small" class="pending-banner" :class="{ clear: !hasDrafts }" style="margin-bottom: 16px">
        <div class="banner-inner">
          <span class="banner-icon" :class="{ clear: !hasDrafts }">
            <CheckCircleOutlined v-if="!hasDrafts && !crud.loading.value" />
            <FieldTimeOutlined v-else />
          </span>
          <div class="banner-text">
            <div class="banner-headline">
              <a-spin v-if="crud.loading.value" size="small" />
              <template v-else-if="hasDrafts">
                {{ money(summary.value) }}
                <span class="banner-dim">
                  {{ tf('Waiting_In_Drafts', 'waiting in') }} {{ fmt(summary.drafts) }} {{ tf('Draft_Invoices', 'draft invoices') }}
                </span>
              </template>
              <template v-else>{{ tf('No_Draft_Invoices', 'No draft invoices in this range.') }}</template>
            </div>
            <a-typography-text v-if="hasDrafts && !crud.loading.value" type="secondary">
              {{ tf('Avg_Age', 'avg age') }} {{ fmt(summary.avg_age) }} {{ $t('Days') }}
              · {{ tf('Oldest', 'oldest') }} {{ fmt(summary.oldest) }} {{ $t('Days') }}
              · <strong>{{ fmt(summary.stale) }}</strong> {{ tf('Stale_Over_30d', 'stale (30 days or more)') }}
            </a-typography-text>
            <a-typography-text v-else-if="!crud.loading.value" type="secondary">
              {{ tf('No_Draft_Invoices_Hint', 'Every sale in this range was finalized.') }}
            </a-typography-text>
          </div>
        </div>
      </a-card>

      <a-row v-if="hasDrafts" :gutter="[16, 16]" style="margin-bottom: 16px">
        <a-col :xs="24" :xl="15">
          <a-card size="small" class="chart-card" :title="tf('Draft_Value_By_Age', 'Draft value by age')">
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
          <a-card size="small" class="chart-card" :title="tf('By_User', 'By user')">
            <template v-if="byUser.length">
              <div v-for="u in byUser" :key="u.name" class="user-row">
                <div class="user-text">
                  <a-typography-text strong class="user-name">{{ u.name }}</a-typography-text>
                  <a-typography-text type="secondary" class="user-sub">
                    {{ fmt(u.count) }} {{ tf('Draft_Invoices', 'draft invoices') }}
                  </a-typography-text>
                </div>
                <div class="user-meter">
                  <span class="user-value">{{ money(u.value) }}</span>
                  <span class="user-track">
                    <span class="user-fill" :style="{ width: shareOf(u) + '%' }"></span>
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
      <DateRangePicker v-model:value="range" :allow-clear="false" @change="crud.reload()" />
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
    </template>

    <template #bodyCell="{ column, record }">
      <template v-if="column.key === 'date'">{{ date(record.date) }}</template>
      <template v-else-if="MONEY_KEYS.includes(column.key)">{{ money(record[column.key]) }}</template>
      <template v-else-if="column.key === 'age_days'">
        <a-tag :color="Number(record.age_days) >= 30 ? 'error' : 'default'">{{ record.age_days }}</a-tag>
      </template>
    </template>
  </ReportPage>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import dayjs from 'dayjs';
import { useI18n } from 'vue-i18n';
import { FieldTimeOutlined, CheckCircleOutlined } from '@ant-design/icons-vue';
import ReportPage from '../../components/ReportPage.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { useUiStore } from '../../stores/ui';
import { t as tf } from '../../i18n';
import http from '../../lib/http';
import DateRangePicker from '../../components/DateRangePicker.vue';
import ViewCurrencySelect from '../../components/ViewCurrencySelect.vue';

const { t } = useI18n();
const { money, date } = useFormat();
const ui = useUiStore();

const MONEY_KEYS = ['GrandTotal', 'TaxNet', 'discount', 'shipping'];

const range = ref([dayjs().subtract(29, 'day'), dayjs()]);
const warehouseId = ref(undefined);
const warehouses = ref([]);

const filterParams = () => ({
  from: range.value?.[0]?.format?.('YYYY-MM-DD') || '',
  to: range.value?.[1]?.format?.('YYYY-MM-DD') || '',
  warehouse_id: warehouseId.value || '',
});

// Payload: { report, totalRows, summary, age_buckets, by_user, warehouses }
const crud = useCrudTable('report/draft_invoices', {
  rowsKey: 'report',
  sortField: 'age_days',
  sortType: 'desc',
  params: filterParams,
});

const warehouseOptions = computed(() => warehouses.value.map(w => ({ value: w.id, label: w.name })));

const fmt = v => (Number(v) || 0).toLocaleString();

/* ---------------------------------------------------------------- banner */
const summary = computed(() => crud.payload.value?.summary || {});
const hasDrafts = computed(() => (Number(summary.value.drafts) || 0) > 0);

/* ------------------------------------------------------------ age chart */
const BUCKET_LABELS = computed(() => ({
  d0_7: `0–7 ${t('Days')}`,
  d8_14: `8–14 ${t('Days')}`,
  d15_30: `15–30 ${t('Days')}`,
  d31_90: `31–90 ${t('Days')}`,
  gt90: `> 90 ${t('Days')}`,
}));

const buckets = computed(() => crud.payload.value?.age_buckets || []);
const bucketTotal = computed(() => buckets.value.reduce((s, b) => s + (Number(b.value) || 0), 0));
const bucketSeries = computed(() => [{
  name: t('Amount'),
  data: buckets.value.map(b => Number(b.value) || 0),
}]);
const bucketOptions = computed(() => {
  const axisColor = ui.dark ? '#d9d9d9' : '#595959';
  return {
    chart: { type: 'bar', background: 'transparent', fontFamily: 'inherit', toolbar: { show: false } },
    plotOptions: { bar: { columnWidth: '55%', borderRadius: 4, distributed: true } },
    // Urgency ramp: fresh drafts cool, old drafts hot.
    colors: ['#10b981', '#22d3ee', '#f59e0b', '#fb923c', '#f43f5e'],
    legend: { show: false },
    dataLabels: { enabled: false },
    xaxis: {
      categories: buckets.value.map(b => BUCKET_LABELS.value[b.key] || b.key),
      labels: { style: { colors: axisColor } },
      axisBorder: { show: false },
      axisTicks: { show: false },
    },
    yaxis: { labels: { style: { colors: axisColor }, formatter: v => money(v) } },
    grid: { borderColor: 'rgba(128, 128, 128, 0.2)' },
    tooltip: {
      theme: ui.dark ? 'dark' : 'light',
      y: {
        formatter: (v, { dataPointIndex }) => {
          const b = buckets.value[dataPointIndex];
          return `${money(v)} (${b ? fmt(b.count) : 0})`;
        },
      },
    },
  };
});

/* ------------------------------------------------------------- by user */
const byUser = computed(() => crud.payload.value?.by_user || []);
const maxUserValue = computed(() =>
  Math.max(1, ...byUser.value.map(u => Number(u.value) || 0))
);
function shareOf(u) {
  return Math.round(((Number(u.value) || 0) / maxUserValue.value) * 100);
}

/* ---------------------------------------------------------------- table */
const columns = computed(() => [
  { title: t('date'), key: 'date', dataIndex: 'date', sorter: true, exportValue: r => date(r.date) },
  { title: t('Number'), dataIndex: 'Ref', key: 'Ref', sorter: true },
  { title: t('Customer'), dataIndex: 'client', key: 'client', sorter: true },
  { title: t('warehouse'), dataIndex: 'warehouse', key: 'warehouse', sorter: true },
  { title: t('User'), dataIndex: 'user', key: 'user', sorter: true },
  { title: t('Amount'), key: 'GrandTotal', dataIndex: 'GrandTotal', sorter: true, align: 'right', sum: 'money', exportValue: r => money(r.GrandTotal) },
  { title: t('Tax'), key: 'TaxNet', dataIndex: 'TaxNet', sorter: true, align: 'right', sum: 'money', exportValue: r => money(r.TaxNet) },
  { title: t('Discount'), key: 'discount', dataIndex: 'discount', sorter: true, align: 'right', sum: 'money', exportValue: r => money(r.discount) },
  { title: t('Shipping'), key: 'shipping', dataIndex: 'shipping', sorter: true, align: 'right', sum: 'money', exportValue: r => money(r.shipping) },
  { title: t('AgeDays'), key: 'age_days', dataIndex: 'age_days', sorter: true, align: 'right', exportValue: r => r.age_days },
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
.chart-card { border-radius: 10px; }

/* Pending banner: amber accent while drafts exist, green when all clear. */
.pending-banner {
  border-radius: 10px;
  border-inline-start: 4px solid #f59e0b;
  background: rgba(245, 158, 11, 0.06);
}
.pending-banner.clear {
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
  color: #f59e0b;
  background: rgba(245, 158, 11, 0.14);
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

/* Per-user rows: name + draft count, pending value + share meter. */
.user-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 8px 4px;
}
.user-row + .user-row { border-top: 1px solid rgba(128, 128, 128, 0.14); }
.user-text {
  min-width: 0;
  display: flex;
  flex-direction: column;
}
.user-name,
.user-sub {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.user-sub { font-size: 12px; }
.user-meter {
  display: flex;
  align-items: center;
  gap: 8px;
  flex: none;
}
.user-value {
  font-weight: 600;
  color: #f59e0b;
}
.user-track {
  display: inline-block;
  width: 80px;
  height: 6px;
  border-radius: 999px;
  background: rgba(128, 128, 128, 0.2);
  overflow: hidden;
}
.user-fill {
  display: block;
  height: 100%;
  border-radius: 999px;
  background: #f59e0b;
}
</style>
