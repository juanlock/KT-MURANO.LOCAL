<template>
  <ReportPage
    :title="$t('SalesReport')"
    :breadcrumb="[$t('Reports'), $t('SalesReport')]"
    :crud="crud"
    :columns="columns"
    row-key="id"
    export-endpoint="report/sales"
    :export-params="filterParams"
    export-rows-key="sales"
  >
    <template #actions>
      <!-- Multi-Currency: view amounts converted (display-only) -->
      <ViewCurrencySelect />
    </template>
    <!-- Summary over the whole filtered set (backend `summary`), not just the page. -->
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
          <a-card size="small" class="chart-card" :title="$t('PaymentStatus')">
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
            :fields="[
              { key: 'amount', label: t('Total') },
              { key: 'paid', label: t('Paid') },
            ]"
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
      <a-input
        v-model:value="refFilter" allow-clear :placeholder="$t('Reference')" style="width: 180px"
        @press-enter="crud.reload()" @change="e => { if (!e.target.value) crud.reload(); }" />
      <a-select
        v-model:value="warehouseId" style="width: 180px" allow-clear show-search option-filter-prop="label"
        :placeholder="$t('warehouse')" :options="opts('warehouses')" @change="crud.reload()" />
      <a-select
        v-model:value="clientId" style="width: 180px" allow-clear show-search option-filter-prop="label"
        :placeholder="$t('Customer')" :options="opts('customers')" @change="crud.reload()" />
      <a-select
        v-model:value="sellerId" style="width: 160px" allow-clear show-search option-filter-prop="label"
        :placeholder="$t('Seller')" :options="opts('sellers', s => s.username || s.name)" @change="crud.reload()" />
      <a-select
        v-model:value="statut" style="width: 150px" allow-clear :placeholder="$t('Status')"
        :options="statusOptions" @change="crud.reload()" />
      <a-select
        v-model:value="paymentStatut" style="width: 150px" allow-clear :placeholder="$t('PaymentStatus')"
        :options="paymentOptions" @change="crud.reload()" />
    </template>

    <template #bodyCell="{ column, record }">
      <template v-if="column.key === 'date'">{{ date(record.date) }}</template>
      <template v-else-if="MONEY_KEYS.includes(column.key)">{{ money(record[column.key]) }}</template>
      <template v-else-if="column.key === 'statut'">
        <a-tag :color="docStatusColor(record.statut)">{{ record.statut }}</a-tag>
      </template>
      <template v-else-if="column.key === 'payment_status'">
        <a-tag :color="payStatusColor(record.payment_status)">{{ record.payment_status }}</a-tag>
      </template>
    </template>
  </ReportPage>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  ShoppingCartOutlined, DollarOutlined, CheckCircleOutlined, ClockCircleOutlined,
} from '@ant-design/icons-vue';
import ReportPage from '../../components/ReportPage.vue';
import ReportChart from '../../components/ReportChart.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { useUiStore } from '../../stores/ui';
import { t as tf } from '../../i18n';
import { docStatusColor, payStatusColor } from '../../lib/statusColors';
import DateRangePicker from '../../components/DateRangePicker.vue';
import ViewCurrencySelect from '../../components/ViewCurrencySelect.vue';

const { t } = useI18n();
const { money, date } = useFormat();
const ui = useUiStore();

const MONEY_KEYS = ['GrandTotal', 'paid_amount', 'due'];

const warehouseId = ref(undefined);
const clientId = ref(undefined);
const sellerId = ref(undefined);
const statut = ref(undefined);
const paymentStatut = ref(undefined);

// Legacy sends these as empty strings when unset.
const refFilter = ref('');
// No default range = all time; the backend only date-filters when bounds are sent.
const range = ref(null);
const filterParams = () => ({
  Ref: refFilter.value || '',
  warehouse_id: warehouseId.value || '',
  client_id: clientId.value || '',
  user_id: sellerId.value || '',
  statut: statut.value || '',
  payment_statut: paymentStatut.value || '',
  ...(range.value?.[0] ? { from: range.value[0].format('YYYY-MM-DD') } : {}),
  ...(range.value?.[1] ? { to: range.value[1].format('YYYY-MM-DD') } : {}),
});

// Payload: { sales, totalRows, summary, timeseries, payment_breakdown,
//            customers, warehouses, sellers }
const crud = useCrudTable('report/sales', {
  rowsKey: 'sales',
  sortField: 'id',
  sortType: 'desc',
  params: filterParams,
});

const opts = (key, labelOf = x => x.name) =>
  (crud.payload.value?.[key] || []).map(x => ({ value: x.id, label: labelOf(x) }));

const kpiTiles = computed(() => {
  const s = crud.payload.value?.summary || {};
  const n = k => Number(s[k]) || 0;
  return [
    { key: 'count', label: t('Sales'), value: n('count'), icon: ShoppingCartOutlined, color: '#1677ff', tint: 'rgba(22, 119, 255, 0.12)' },
    { key: 'total', label: t('Total'), value: money(n('total')), icon: DollarOutlined, color: '#6d28d9', tint: 'rgba(109, 40, 217, 0.12)' },
    { key: 'paid', label: t('Paid'), value: money(n('paid')), icon: CheckCircleOutlined, color: '#22c55e', tint: 'rgba(34, 197, 94, 0.12)' },
    { key: 'due', label: t('Due'), value: money(n('due')), icon: ClockCircleOutlined, color: '#f43f5e', tint: 'rgba(244, 63, 94, 0.12)' },
  ];
});

/* Payment-status mix donut — color follows the STATUS (green/amber/rose),
   labels reuse the same i18n keys as the filter select. */
const PAYMENT_META = computed(() => ({
  paid: { label: t('Paid'), color: '#10b981' },
  partial: { label: t('partial'), color: '#f59e0b' },
  unpaid: { label: t('Unpaid'), color: '#f43f5e' },
}));

const donutRows = computed(() => {
  const meta = PAYMENT_META.value;
  return (crud.payload.value?.payment_breakdown || [])
    .filter(r => Number(r.amount) > 0)
    .map(r => ({
      label: meta[r.status]?.label || r.status || '—',
      color: meta[r.status]?.color || '#94a3b8',
      amount: Number(r.amount) || 0,
      count: Number(r.count) || 0,
    }));
});

const donutSeries = computed(() => donutRows.value.map(r => r.amount));
const donutOptions = computed(() => ({
  chart: { type: 'donut', background: 'transparent', fontFamily: 'inherit' },
  labels: donutRows.value.map(r => r.label),
  colors: donutRows.value.map(r => r.color),
  legend: { position: 'bottom', labels: { colors: ui.dark ? '#d9d9d9' : '#595959' } },
  dataLabels: { enabled: false },
  stroke: { colors: [ui.dark ? '#1f1f1f' : '#ffffff'], width: 3 },
  plotOptions: { pie: { donut: { size: '70%' } } },
  tooltip: {
    theme: ui.dark ? 'dark' : 'light',
    y: {
      formatter: (v, { seriesIndex }) => {
        const row = donutRows.value[seriesIndex];
        return `${money(v)} (${row ? row.count : 0})`;
      },
    },
  },
}));

const statusOptions = computed(() => [
  { value: 'completed', label: t('complete') },
  { value: 'pending', label: t('Pending') },
  { value: 'ordered', label: t('Ordered') },
]);
const paymentOptions = computed(() => [
  { value: 'paid', label: t('Paid') },
  { value: 'partial', label: t('partial') },
  { value: 'unpaid', label: t('Unpaid') },
]);

const columns = computed(() => [
  { title: t('date'), key: 'date', dataIndex: 'date', sorter: true, exportValue: r => date(r.date) },
  { title: t('Reference'), dataIndex: 'Ref', key: 'Ref', sorter: true },
  { title: t('Customer'), dataIndex: 'client_name', key: 'client_name' },
  { title: t('warehouse'), dataIndex: 'warehouse_name', key: 'warehouse_name' },
  { title: t('Status'), key: 'statut', dataIndex: 'statut', exportValue: r => r.statut },
  { title: t('Total'), key: 'GrandTotal', dataIndex: 'GrandTotal', sorter: true, align: 'right', sum: 'money', exportValue: r => money(r.GrandTotal) },
  { title: t('Paid'), key: 'paid_amount', dataIndex: 'paid_amount', sorter: true, align: 'right', sum: 'money', exportValue: r => money(r.paid_amount) },
  { title: t('Due'), key: 'due', dataIndex: 'due', sorter: true, align: 'right', sum: 'money', exportValue: r => money(r.due) },
  { title: t('PaymentStatus'), key: 'payment_status', dataIndex: 'payment_status', exportValue: r => r.payment_status },
  { title: t('Seller'), dataIndex: 'seller_name', key: 'seller_name' },
]);

onMounted(crud.fetchRows);
</script>

<style scoped>
/* Same tile anatomy as the Expenses report KPIs; label uses antd's secondary
   text token so it stays readable in dark mode. */
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
