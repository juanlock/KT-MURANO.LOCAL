<template>
  <ReportPage
    :title="$t(titleKey)"
    :breadcrumb="[$t('Reports'), $t('Payments'), $t(titleKey)]"
    :crud="crud"
    :columns="columns"
    row-key="id"
    :export-endpoint="endpoint"
    :export-params="filterParams"
    export-rows-key="payments"
  >
    <template #actions>
      <!-- Multi-Currency: view amounts converted (display-only) -->
      <ViewCurrencySelect />
    </template>
    <template #filters>
      <DateRangePicker v-model:value="range" :allow-clear="false" @change="crud.reload()" />
      <a-input
        v-model:value="refFilter" allow-clear :placeholder="$t('Reference')" style="width: 200px"
        @press-enter="crud.reload()" @change="e => { if (!e.target.value) crud.reload(); }"
      />
      <a-select
        v-model:value="partyId" allow-clear show-search option-filter-prop="label" style="width: 200px"
        :placeholder="$t(kind.partyLabel)" :options="partyOptions" @change="crud.reload()"
      />
      <a-select
        v-model:value="docId" allow-clear show-search option-filter-prop="label" style="width: 200px"
        :placeholder="$t(kind.docLabel)" :options="docOptions" @change="crud.reload()"
      />
      <a-select
        v-model:value="methodId" allow-clear show-search option-filter-prop="label" style="width: 180px"
        :placeholder="$t('ModePaiement')" :options="methodOptions" @change="crud.reload()"
      />
    </template>

    <!-- Summary cards — totals over the whole filtered set (all pages). -->
    <template #chart>
      <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
        <a-col v-for="k in kpiTiles" :key="k.label" :xs="12" :sm="12" :md="6">
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
          <a-card size="small" class="chart-card" :title="$t('ModePaiement')">
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
            :fields="[{ key: 'amount', label: t('Amount') }]"
            :title="tf('Payments_over_time', 'Payments over time')"
            type="area"
            x-key="d"
            :format="money"
            :height="300"
          />
        </a-col>
      </a-row>
    </template>

    <template #bodyCell="{ column, record }">
      <template v-if="column.key === 'date'">{{ date(record.date) }}</template>
      <template v-else-if="column.key === 'montant'">
        <strong>{{ money(record.montant) }}</strong>
      </template>
      <template v-else-if="column.key === 'payment_method'">
        <a-tag>{{ record.payment_method }}</a-tag>
      </template>
    </template>
  </ReportPage>
</template>

<script setup>
/**
 * One component for the four payment reports — they differ only by endpoint and
 * their document/party columns. The route supplies `kind` via meta:
 *
 *   sale             -> payment_sale            (Sale / Customer)
 *   purchase         -> payment_purchase        (Purchase / Supplier)
 *   returns_sale     -> payment/returns_sale    (Return / Customer)
 *   returns_purchase -> payment/returns_purchase(Return / Supplier)
 *
 * NOTE: these endpoints are NOT under `report/` like the other reports.
 */
import { ref, computed, onMounted } from 'vue';
import dayjs from 'dayjs';
import { useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import {
  WalletOutlined, DollarOutlined, BarChartOutlined, CreditCardOutlined,
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
const { money, date } = useFormat();
const route = useRoute();
const ui = useUiStore();

const KINDS = {
  sale: {
    endpoint: 'payment_sale',
    titleKey: 'Sales',
    docKey: 'Ref_Sale',
    docLabel: 'Sale',
    partyKey: 'client_name',
    partyLabel: 'Customer',
    // Filter wiring (matches each report controller's params + returned lists).
    partyParam: 'client_id', partyListKey: 'clients',
    docParam: 'sale_id', docListKey: 'sales',
  },
  purchase: {
    endpoint: 'payment_purchase',
    titleKey: 'Purchases',
    docKey: 'Ref_Purchase',
    docLabel: 'Purchase',
    partyKey: 'provider_name',
    partyLabel: 'Supplier',
    partyParam: 'provider_id', partyListKey: 'suppliers',
    docParam: 'purchase_id', docListKey: 'purchases',
  },
  returns_sale: {
    endpoint: 'payment/returns_sale',
    titleKey: 'SalesReturn',
    docKey: 'Ref_return',
    docLabel: 'Return',
    partyKey: 'client_name',
    partyLabel: 'Customer',
    partyParam: 'client_id', partyListKey: 'clients',
    docParam: 'sale_return_id', docListKey: 'sale_returns',
  },
  returns_purchase: {
    endpoint: 'payment/returns_purchase',
    titleKey: 'PurchasesReturn',
    docKey: 'Ref_return',
    docLabel: 'Return',
    partyKey: 'provider_name',
    partyLabel: 'Supplier',
    partyParam: 'provider_id', partyListKey: 'suppliers',
    docParam: 'purchase_return_id', docListKey: 'purchase_returns',
  },
};

// Resolved once per mount. useCrudTable captures its endpoint at setup, so the
// route gives this component a :key — switching payment reports remounts it
// rather than leaving the composable pointed at the previous endpoint.
const kind = KINDS[route.meta.kind] || KINDS.sale;
const endpoint = kind.endpoint;
const titleKey = kind.titleKey;

const range = ref([dayjs().subtract(29, 'day'), dayjs()]);
const refFilter = ref('');
const partyId = ref(undefined);
const docId = ref(undefined);
const methodId = ref(undefined);

const filterParams = () => ({
  from: range.value?.[0]?.format?.('YYYY-MM-DD') || '',
  to: range.value?.[1]?.format?.('YYYY-MM-DD') || '',
  Ref: refFilter.value || '',
  [kind.partyParam]: partyId.value || '',
  [kind.docParam]: docId.value || '',
  payment_method_id: methodId.value || '',
});

// Payload: { payments, totalRows, stats, timeseries, method_breakdown,
//            <partyListKey>, <docListKey>, payment_methods }
const crud = useCrudTable(endpoint, {
  rowsKey: 'payments',
  sortField: 'id',
  sortType: 'desc',
  params: filterParams,
});

// Summary cards — `stats` ships with the list payload (whole filtered set).
const kpiTiles = computed(() => {
  const s = crud.payload.value?.stats || {};
  const n = k => Number(s[k]) || 0;
  return [
    { label: t('Payments'), value: n('count'), icon: WalletOutlined, color: '#6d28d9', tint: 'rgba(109, 40, 217, 0.12)' },
    { label: t('Amount'), value: money(n('total')), icon: DollarOutlined, color: '#10b981', tint: 'rgba(16, 185, 129, 0.12)' },
    { label: tf('Avg_Payment', 'Avg payment'), value: money(n('average')), icon: BarChartOutlined, color: '#1677ff', tint: 'rgba(22, 119, 255, 0.12)' },
    { label: t('ModePaiement'), value: s.top_method || '—', icon: CreditCardOutlined, color: '#f59e0b', tint: 'rgba(245, 158, 11, 0.14)' },
  ];
});

/* Payment-method mix donut — categorical palette in fixed order (top method
   first, as the backend sorts by amount), amounts + counts in the tooltip. */
const DONUT_COLORS = ['#6366f1', '#22d3ee', '#f59e0b', '#ec4899', '#10b981', '#8b5cf6', '#0ea5e9', '#94a3b8'];

const donutRows = computed(() =>
  (crud.payload.value?.method_breakdown || []).filter(r => Number(r.amount) > 0)
);
const donutSeries = computed(() => donutRows.value.map(r => Number(r.amount) || 0));
const donutOptions = computed(() => ({
  chart: { type: 'donut', background: 'transparent', fontFamily: 'inherit' },
  labels: donutRows.value.map(r => r.name),
  colors: DONUT_COLORS,
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

// Filter dropdowns come from the report payload itself (same as legacy).
const partyOptions = computed(() =>
  (crud.payload.value?.[kind.partyListKey] || []).map(x => ({ value: x.id, label: x.name }))
);
const docOptions = computed(() =>
  (crud.payload.value?.[kind.docListKey] || []).map(x => ({ value: x.id, label: x.Ref }))
);
const methodOptions = computed(() =>
  (crud.payload.value?.payment_methods || []).map(x => ({ value: x.id, label: x.name }))
);

const columns = computed(() => [
  { title: t('date'), key: 'date', dataIndex: 'date', sorter: true, exportValue: r => date(r.date) },
  { title: t('Reference'), dataIndex: 'Ref', key: 'Ref', sorter: true },
  { title: t(kind.docLabel), dataIndex: kind.docKey, key: 'doc' },
  { title: t(kind.partyLabel), dataIndex: kind.partyKey, key: 'party' },
  { title: t('ModePaiement'), key: 'payment_method', dataIndex: 'payment_method', exportValue: r => r.payment_method },
  { title: t('Account'), dataIndex: 'account_name', key: 'account_name' },
  { title: t('Amount'), key: 'montant', dataIndex: 'montant', sorter: true, align: 'right', sum: 'money', exportValue: r => money(r.montant) },
  { title: t('AddedBy'), dataIndex: 'user_name', key: 'user_name' },
]);

onMounted(crud.fetchRows);
</script>

<style scoped>
/* KPI tiles — shared design with the other report pages. */
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
