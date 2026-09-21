<template>
  <ReportPage
    :title="$t('Cash_Flow_Report')"
    :breadcrumb="[$t('Reports'), $t('Cash_Flow_Report')]"
    :crud="crud"
    :columns="columns"
    row-key="group"
    export-endpoint="report/cash_flow_report"
    :export-params="filterParams"
    export-rows-key="rows"
  >
    <template #actions>
      <!-- Multi-Currency: view amounts converted (display-only) -->
      <ViewCurrencySelect />
    </template>
    <template #filters>
      <DateRangePicker v-model:value="range" :allow-clear="false" @change="crud.reload()" />
      <a-select v-model:value="groupBy" style="width: 170px" :options="groupOptions" @change="onGroupChange" />
      <a-select
        v-model:value="warehouseId" style="width: 180px" allow-clear show-search option-filter-prop="label"
        :placeholder="$t('warehouse')" :options="opts('warehouses')" @change="crud.reload()" />
      <a-select
        v-if="groupBy === 'account'"
        v-model:value="accountId" style="width: 180px" allow-clear show-search option-filter-prop="label"
        :placeholder="$t('Account')" :options="opts('accounts')" @change="crud.reload()" />
      <a-select
        v-else
        v-model:value="paymentMethodId" style="width: 180px" allow-clear show-search option-filter-prop="label"
        :placeholder="$t('PaymentMethod')" :options="opts('payment_methods')" @change="crud.reload()" />
    </template>

    <template #chart>
      <!-- Summary tiles + daily inflow/outflow trend -->
      <a-row :gutter="16" style="margin-bottom: 16px">
        <a-col :xs="24" :md="8">
          <a-card size="small">
            <div class="cf-title">{{ $t('Inflow') }}</div>
            <div class="cf-value" style="color: #52c41a">
              <a-spin v-if="loading" size="small" />
              <template v-else>{{ money(totals.inflow) }}</template>
            </div>
          </a-card>
        </a-col>
        <a-col :xs="24" :md="8">
          <a-card size="small">
            <div class="cf-title">{{ $t('Outflow') }}</div>
            <div class="cf-value" style="color: #ff4d4f">
              <a-spin v-if="loading" size="small" />
              <template v-else>{{ money(totals.outflow) }}</template>
            </div>
          </a-card>
        </a-col>
        <a-col :xs="24" :md="8">
          <a-card size="small">
            <div class="cf-title">{{ $t('Net') }}</div>
            <div class="cf-value" :style="{ color: totals.net >= 0 ? '#52c41a' : '#ff4d4f' }">
              <a-spin v-if="loading" size="small" />
              <template v-else>{{ money(totals.net) }}</template>
            </div>
          </a-card>
        </a-col>
      </a-row>
      <ReportChart :data="timeseries" :fields="chartFields" :title="$t('Cash_Flow_Report')" type="area" :format="money" />
    </template>

    <template #bodyCell="{ column, record }">
      <template v-if="column.key === 'inflow'">{{ money(record.inflow) }}</template>
      <template v-else-if="column.key === 'outflow'">{{ money(record.outflow) }}</template>
      <template v-else-if="column.key === 'net'">
        <strong :style="{ color: Number(record.net) >= 0 ? '#52c41a' : '#ff4d4f' }">{{ money(record.net) }}</strong>
      </template>
    </template>
  </ReportPage>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import dayjs from 'dayjs';
import { useI18n } from 'vue-i18n';
import ReportPage from '../../components/ReportPage.vue';
import ReportChart from '../../components/ReportChart.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import DateRangePicker from '../../components/DateRangePicker.vue';
import ViewCurrencySelect from '../../components/ViewCurrencySelect.vue';

const { t } = useI18n();
const { money } = useFormat();

const range = ref([dayjs().subtract(29, 'day'), dayjs()]);
const groupBy = ref('account');
const warehouseId = ref(undefined);
const accountId = ref(undefined);
const paymentMethodId = ref(undefined);

// Legacy sends the account/method filter only for the active grouping.
const filterParams = () => ({
  from: range.value?.[0]?.format?.('YYYY-MM-DD') || '',
  to: range.value?.[1]?.format?.('YYYY-MM-DD') || '',
  group_by: groupBy.value,
  warehouse_id: warehouseId.value || '',
  account_id: groupBy.value === 'account' ? (accountId.value || '') : '',
  payment_method_id: groupBy.value === 'method' ? (paymentMethodId.value || '') : '',
});

// Payload: { rows, total_inflow, total_outflow, net_cash_flow,
//            timeseries: [{d, inflow, outflow, net}], warehouses,
//            payment_methods, accounts }
const crud = useCrudTable('report/cash_flow_report', {
  rowsKey: 'rows',
  sortField: 'net',
  sortType: 'desc',
  params: filterParams,
});

function onGroupChange() {
  accountId.value = undefined;
  paymentMethodId.value = undefined;
  crud.reload();
}

const loading = computed(() => crud.loading.value);

const opts = key =>
  (crud.payload.value?.[key] || []).map(x => ({ value: x.id, label: x.name }));

const totals = computed(() => ({
  inflow: Number(crud.payload.value?.total_inflow || 0),
  outflow: Number(crud.payload.value?.total_outflow || 0),
  net: Number(crud.payload.value?.net_cash_flow || 0),
}));

const timeseries = computed(() => crud.payload.value?.timeseries || []);
const chartFields = computed(() => [
  { key: 'inflow', label: t('Inflow') },
  { key: 'outflow', label: t('Outflow') },
]);

const groupOptions = computed(() => [
  { value: 'account', label: t('Account') },
  { value: 'method', label: t('PaymentMethod') },
]);

const columns = computed(() => [
  { title: t('Group'), dataIndex: 'group', key: 'group', sorter: true },
  { title: t('Inflow'), key: 'inflow', dataIndex: 'inflow', sorter: true, align: 'right', sum: 'money', exportValue: r => money(r.inflow) },
  { title: t('Outflow'), key: 'outflow', dataIndex: 'outflow', sorter: true, align: 'right', sum: 'money', exportValue: r => money(r.outflow) },
  { title: t('Net'), key: 'net', dataIndex: 'net', sorter: true, align: 'right', sum: 'money', exportValue: r => money(r.net) },
]);

onMounted(crud.fetchRows);
</script>

<style scoped>
/* Mirrors the a-statistic look so the inline spinner can replace the value. */
.cf-title {
  color: rgba(0, 0, 0, 0.45);
  font-size: 14px;
  margin-bottom: 4px;
}
.cf-value {
  font-size: 24px;
  line-height: 32px;
}
</style>
