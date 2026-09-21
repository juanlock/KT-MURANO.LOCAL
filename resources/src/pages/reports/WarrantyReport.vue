<template>
  <ReportPage
    :title="$t('Warranty_Guarantee_Report')"
    :breadcrumb="[$t('Reports'), $t('Warranty_Guarantee_Report')]"
    :crud="crud"
    :columns="columns"
    row-key="id"
    export-endpoint="report/warranty_guarantee"
    :export-params="filterParams"
    export-rows-key="rows"
  >
    <!-- Summary + charts over the whole filtered set (backend summary/expiry_timeline). -->
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
          <a-card size="small" class="chart-card" :title="$t('Status')">
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
            :data="crud.payload.value?.expiry_timeline"
            :fields="[{ key: 'count', label: tf('Coverage_Ending', 'Coverage ending') }]"
            :title="tf('Coverage_Ending_By_Month', 'Coverage ending by month')"
            type="bar"
            x-key="m"
            :height="300"
          />
        </a-col>
      </a-row>
    </template>

    <template #filters>
      <DateRangePicker v-model:value="range" :allow-clear="false" @change="crud.reload()" />
      <a-select
        v-model:value="status"
        style="width: 180px"
        allow-clear
        :placeholder="$t('Status')"
        :options="statusOptions"
        @change="crud.reload()"
      />
      <a-select
        v-model:value="clientId" allow-clear show-search option-filter-prop="label" style="width: 200px"
        :placeholder="$t('Customer')" :options="opts('customers')" @change="crud.reload()"
      />
      <a-select
        v-model:value="warehouseId" allow-clear show-search option-filter-prop="label" style="width: 200px"
        :placeholder="$t('warehouse')" :options="opts('warehouses')" @change="crud.reload()"
      />
      <a-select
        v-model:value="productId" allow-clear show-search option-filter-prop="label" style="width: 200px"
        :placeholder="$t('Product')" :options="opts('products')" @change="crud.reload()"
      />
    </template>

    <template #bodyCell="{ column, record }">
      <template v-if="column.key === 'sale_date'">{{ date(record.sale_date) }}</template>
      <template v-else-if="column.key === 'warranty_date'">
        {{ record.warranty_date ? date(record.warranty_date) : '—' }}
      </template>
      <template v-else-if="column.key === 'guarantee_date'">
        {{ record.guarantee_date ? date(record.guarantee_date) : '—' }}
      </template>
      <template v-else-if="column.key === 'days_remaining'">
        <a-tag :color="daysColor(record.days_remaining)">{{ record.days_remaining ?? '—' }}</a-tag>
      </template>
      <template v-else-if="column.key === 'status'">
        <a-tag :color="statusColor(record.status)">{{ record.status }}</a-tag>
      </template>
    </template>
  </ReportPage>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import dayjs from 'dayjs';
import { useI18n } from 'vue-i18n';
import {
  SafetyCertificateOutlined, CheckCircleOutlined, ClockCircleOutlined, CloseCircleOutlined,
} from '@ant-design/icons-vue';
import ReportPage from '../../components/ReportPage.vue';
import ReportChart from '../../components/ReportChart.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { useUiStore } from '../../stores/ui';
import { t as tf } from '../../i18n';
import DateRangePicker from '../../components/DateRangePicker.vue';

const { t } = useI18n();
const { date } = useFormat();
const ui = useUiStore();

const range = ref([dayjs().subtract(29, 'day'), dayjs()]);
const status = ref(undefined);
const clientId = ref(undefined);
const warehouseId = ref(undefined);
const productId = ref(undefined);

const filterParams = () => ({
  from: range.value?.[0]?.format?.('YYYY-MM-DD') || '',
  to: range.value?.[1]?.format?.('YYYY-MM-DD') || '',
  ...(status.value ? { status: status.value } : {}),
  ...(clientId.value ? { client_id: clientId.value } : {}),
  ...(warehouseId.value ? { warehouse_id: warehouseId.value } : {}),
  ...(productId.value ? { product_id: productId.value } : {}),
});

// Payload: { rows, totalRows, summary, expiry_timeline, customers,
//            warehouses, products } — rowsKey required.
const crud = useCrudTable('report/warranty_guarantee', {
  rowsKey: 'rows',
  sortField: 'sale_date',
  sortType: 'desc',
  params: filterParams,
});

const opts = key => (crud.payload.value?.[key] || []).map(x => ({ value: x.id, label: x.name }));

// All four statuses the backend computes — not just active/expired.
const statusOptions = computed(() => [
  { value: 'active', label: t('Active') },
  { value: 'expiring_soon', label: tf('Expiring_Soon', 'Expiring soon') },
  { value: 'expired', label: t('Expired') },
  { value: 'no_coverage', label: tf('No_Coverage', 'No coverage') },
]);

/* -------------------------------------------------- summary KPIs + donut */
const summary = computed(() => crud.payload.value?.summary || {});

const kpiTiles = computed(() => {
  const n = k => Number(summary.value[k]) || 0;
  return [
    { key: 'total', label: tf('Covered_Items', 'Items'), value: n('total'), icon: SafetyCertificateOutlined, color: '#6d28d9', tint: 'rgba(109, 40, 217, 0.12)' },
    { key: 'active', label: t('Active'), value: n('active'), icon: CheckCircleOutlined, color: '#10b981', tint: 'rgba(16, 185, 129, 0.12)' },
    { key: 'expiring', label: tf('Expiring_Soon', 'Expiring soon'), value: n('expiring_soon'), icon: ClockCircleOutlined, color: '#f59e0b', tint: 'rgba(245, 158, 11, 0.14)' },
    { key: 'expired', label: t('Expired'), value: n('expired'), icon: CloseCircleOutlined, color: '#f43f5e', tint: 'rgba(244, 63, 94, 0.12)' },
  ];
});

/* Status mix donut — color follows the STATUS; counts, not amounts. */
const STATUS_META = computed(() => ([
  { key: 'active', label: t('Active'), color: '#10b981' },
  { key: 'expiring_soon', label: tf('Expiring_Soon', 'Expiring soon'), color: '#f59e0b' },
  { key: 'expired', label: t('Expired'), color: '#f43f5e' },
  { key: 'no_coverage', label: tf('No_Coverage', 'No coverage'), color: '#94a3b8' },
]));

const donutRows = computed(() =>
  STATUS_META.value
    .map(m => ({ ...m, count: Number(summary.value[m.key]) || 0 }))
    .filter(r => r.count > 0)
);
const donutSeries = computed(() => donutRows.value.map(r => r.count));
const donutOptions = computed(() => ({
  chart: { type: 'donut', background: 'transparent', fontFamily: 'inherit' },
  labels: donutRows.value.map(r => r.label),
  colors: donutRows.value.map(r => r.color),
  legend: { position: 'bottom', labels: { colors: ui.dark ? '#d9d9d9' : '#595959' } },
  dataLabels: { enabled: false },
  stroke: { colors: [ui.dark ? '#1f1f1f' : '#ffffff'], width: 3 },
  plotOptions: { pie: { donut: { size: '70%' } } },
  tooltip: { theme: ui.dark ? 'dark' : 'light' },
}));

const columns = computed(() => [
  { title: t('Invoice'), dataIndex: 'Ref', key: 'Ref', sorter: true },
  { title: t('date'), key: 'sale_date', dataIndex: 'sale_date', sorter: true, exportValue: r => date(r.sale_date) },
  { title: t('Product'), dataIndex: 'product_name', key: 'product_name' },
  { title: t('qty'), dataIndex: 'quantity', key: 'quantity', align: 'right', sum: true },
  { title: t('Customer'), dataIndex: 'client_name', key: 'client_name' },
  { title: t('warehouse'), dataIndex: 'warehouse_name', key: 'warehouse_name' },
  {
    title: t('Warranty_Date'),
    key: 'warranty_date',
    dataIndex: 'warranty_date',
    sorter: true,
    exportValue: r => (r.warranty_date ? date(r.warranty_date) : ''),
  },
  {
    title: t('Guarantee_Date'),
    key: 'guarantee_date',
    dataIndex: 'guarantee_date',
    sorter: true,
    exportValue: r => (r.guarantee_date ? date(r.guarantee_date) : ''),
  },
  { title: t('Days_Remaining'), key: 'days_remaining', dataIndex: 'days_remaining', sorter: true, align: 'right', exportValue: r => r.days_remaining ?? '' },
  { title: t('Status'), key: 'status', dataIndex: 'status', exportValue: r => r.status },
]);

function daysColor(days) {
  const n = Number(days);
  if (!Number.isFinite(n)) return 'default';
  if (n <= 0) return 'error';
  if (n <= 30) return 'warning';
  return 'success';
}

function statusColor(s) {
  return String(s || '').toLowerCase().includes('expired') ? 'error' : 'success';
}

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
