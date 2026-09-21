<template>
  <ReportPage
    :title="$t('Expiry_Report')"
    :breadcrumb="[$t('Reports'), $t('Expiry_Report')]"
    :crud="crud"
    :columns="columns"
    row-key="id"
    export-endpoint="report/expiry"
    :export-params="filterParams"
    export-rows-key="batches"
  >
    <template #actions>
      <!-- Multi-Currency: view amounts converted (display-only) -->
      <ViewCurrencySelect />
    </template>
    <template #filters>
      <a-select v-model:value="expiryWindow" style="width: 200px" :options="windowOptions" @change="crud.reload()" />
      <a-select
        v-model:value="warehouseId"
        style="width: 200px"
        allow-clear
        show-search
        option-filter-prop="label"
        :placeholder="$t('Warehouse')"
        :options="warehouseOptions"
        @change="crud.reload()"
      />
    </template>

    <!-- KPIs: batch counts by expiry state, plus their stock value. -->
    <template #chart>
      <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
        <a-col v-for="tile in tiles" :key="tile.key" :xs="24" :sm="12" :xl="6">
          <a-card :bordered="false" class="kpi-card">
            <div class="kpi-inner">
              <div class="kpi-icon" :style="{ background: tile.tint, color: tile.color }">
                <component :is="tile.icon" />
              </div>
              <div class="kpi-text">
                <div class="kpi-label">{{ tile.label }}</div>
                <div class="kpi-value" :style="{ color: tile.color }">
                  <a-spin v-if="loading" size="small" />
                  <template v-else>{{ tile.value }}</template>
                </div>
                <div v-if="!loading && tile.sub" class="kpi-sub">{{ $t('Value') }}: {{ money(tile.sub) }}</div>
              </div>
            </div>
          </a-card>
        </a-col>

        <a-col :xs="24" :sm="12" :xl="6">
          <a-card :bordered="false" class="kpi-card donut-card">
            <div v-if="!chartReady" class="donut-loading"><a-spin /></div>
            <apexchart v-else type="donut" height="120" :options="donutOptions" :series="donutSeries" />
          </a-card>
        </a-col>
      </a-row>
    </template>

    <template #bodyCell="{ column, record }">
      <template v-if="column.key === 'expiry_date'">
        {{ record.expiry_date ? date(record.expiry_date) : '—' }}
      </template>
      <template v-else-if="column.key === 'value'">{{ money(record.value) }}</template>
      <template v-else-if="column.key === 'status'">
        <a-tag :color="statusColor(record.status)">{{ record.status }}</a-tag>
      </template>
    </template>
  </ReportPage>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { ExclamationCircleOutlined, ClockCircleOutlined, CheckCircleOutlined } from '@ant-design/icons-vue';
import ReportPage from '../../components/ReportPage.vue';
import ViewCurrencySelect from '../../components/ViewCurrencySelect.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';

const { t } = useI18n();
const { money, date } = useFormat();

const expiryWindow = ref(30);
const warehouseId = ref(undefined);

const filterParams = () => ({
  expiry_window: expiryWindow.value,
  ...(warehouseId.value ? { warehouse_id: warehouseId.value } : {}),
});

// Payload: { batches, totalRows, warehouses, kpis } — rowsKey is required.
const crud = useCrudTable('report/expiry', {
  rowsKey: 'batches',
  sortField: 'expiry_date',
  sortType: 'asc',
  params: filterParams,
});

const loading = computed(() => crud.loading.value);

// Mount the donut once, after the first load — and never unmount it. Toggling
// an apexchart with v-if on every reload triggers its "Element not found" race.
const chartReady = ref(false);
watch(loading, v => { if (!v) chartReady.value = true; });

// The warehouse list ships with the report itself; no extra request needed.
const warehouseOptions = computed(() =>
  (crud.payload.value?.warehouses || []).map(w => ({ value: w.id, label: w.name }))
);

const windowOptions = computed(() =>
  [7, 30, 60, 90, 180].map(d => ({ value: d, label: `${d} ${t('Days')}` }))
);

/* ---------------------------------------------------------------- KPIs */
// Payload: kpis { expired, near, valid, expired_value, near_value }
const kpis = computed(
  () => crud.payload.value?.kpis || { expired: 0, near: 0, valid: 0, expired_value: 0, near_value: 0 }
);

const tiles = computed(() => [
  { key: 'expired', label: t('Expired'), value: kpis.value.expired || 0, sub: kpis.value.expired_value, icon: ExclamationCircleOutlined, color: '#ff4d4f', tint: 'rgba(255, 77, 79, 0.12)' },
  { key: 'near', label: t('Expiring_Soon'), value: kpis.value.near || 0, sub: kpis.value.near_value, icon: ClockCircleOutlined, color: '#faad14', tint: 'rgba(250, 173, 20, 0.14)' },
  { key: 'valid', label: t('Valid'), value: kpis.value.valid || 0, sub: null, icon: CheckCircleOutlined, color: '#52c41a', tint: 'rgba(82, 196, 26, 0.12)' },
]);
const donutSeries = computed(() => [
  Number(kpis.value.expired || 0),
  Number(kpis.value.near || 0),
  Number(kpis.value.valid || 0),
]);

const donutOptions = computed(() => ({
  chart: { type: 'donut', background: 'transparent', fontFamily: 'inherit' },
  labels: [t('Expired'), t('Expiring_Soon'), t('Valid')],
  colors: ['#ff4d4f', '#faad14', '#52c41a'],
  legend: { show: false },
  dataLabels: { enabled: false },
  stroke: { width: 0 },
  plotOptions: { pie: { donut: { size: '70%' } } },
  tooltip: { theme: 'light' },
}));

const columns = computed(() => [
  { title: t('Product'), dataIndex: 'product', key: 'product', sorter: true },
  { title: t('Batch_No'), dataIndex: 'batch_no', key: 'batch_no' },
  { title: t('Warehouse'), dataIndex: 'warehouse_name', key: 'warehouse_name' },
  {
    title: t('Expiry_Date'),
    key: 'expiry_date',
    dataIndex: 'expiry_date',
    sorter: true,
    exportValue: r => (r.expiry_date ? date(r.expiry_date) : ''),
  },
  { title: t('Quantity'), dataIndex: 'qty', key: 'qty', align: 'right', sum: true },
  { title: t('Value'), key: 'value', dataIndex: 'value', align: 'right', sum: 'money', exportValue: r => money(r.value) },
  { title: t('Status'), key: 'status', dataIndex: 'status', exportValue: r => r.status },
]);

function statusColor(status) {
  const s = String(status || '').toLowerCase();
  if (s.includes('expired')) return 'error';
  if (s.includes('soon') || s.includes('near')) return 'warning';
  return 'success';
}

onMounted(crud.fetchRows);
</script>

<style scoped>
/* KPI icon tiles — shared design with the customer ledger / supplier details. */
.kpi-card {
  border: 1px solid #f0f0f0;
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.04);
  height: 100%;
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
  line-height: 1.3;
  margin-bottom: 2px;
}
.kpi-value {
  font-size: 22px;
  font-weight: 700;
  line-height: 1.2;
}
.kpi-sub {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.4);
  margin-top: 2px;
}
.donut-card :deep(.ant-card-body) {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 8px;
}
.donut-loading {
  height: 120px;
  display: flex;
  align-items: center;
  justify-content: center;
}
</style>
