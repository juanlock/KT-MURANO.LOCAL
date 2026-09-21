<template>
  <ReportPage
    :title="$t('Stock_Transfer_Report')"
    :breadcrumb="[$t('Reports'), $t('Stock_Transfer_Report')]"
    :crud="crud"
    :columns="columns"
    row-key="transfer_id"
    export-endpoint="report/stock_transfer"
    :export-params="filterParams"
    :export-select="p => p?.data?.rows || []"
  >
    <template #actions>
      <!-- Multi-Currency: view amounts converted (display-only) -->
      <ViewCurrencySelect />
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

    <template #chart>
      <ReportChart :data="timeseries" :fields="chartFields" :title="$t('Qty')" type="line" />
    </template>

    <template #bodyCell="{ column, record }">
      <template v-if="column.key === 'date_time'">{{ dateTime(record.date_time) }}</template>
      <template v-else-if="column.key === 'value'">{{ money(record.value) }}</template>
      <template v-else-if="column.key === 'statut'">
        <a-tag :color="docStatusColor(record.statut)">{{ record.statut }}</a-tag>
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
import { docStatusColor } from '../../lib/statusColors';
import DateRangePicker from '../../components/DateRangePicker.vue';
import ViewCurrencySelect from '../../components/ViewCurrencySelect.vue';

const { t } = useI18n();
const { money, dateTime } = useFormat();

const range = ref([dayjs().subtract(29, 'day'), dayjs()]);
const warehouseId = ref(undefined);

const filterParams = () => ({
  from: range.value?.[0]?.format?.('YYYY-MM-DD') || '',
  to: range.value?.[1]?.format?.('YYYY-MM-DD') || '',
  warehouse_id: warehouseId.value || '',
});

// Nested payload: { data: { kpis, timeseries, routes, rows, totalRows }, warehouses }
const crud = useCrudTable('report/stock_transfer', {
  sortField: 'date_time',
  sortType: 'desc',
  params: filterParams,
  select: p => ({ rows: p?.data?.rows || [], total: p?.data?.totalRows || 0 }),
});

const warehouseOptions = computed(() =>
  (crud.payload.value?.warehouses || []).map(w => ({ value: w.id, label: w.name }))
);

const timeseries = computed(() => crud.payload.value?.data?.timeseries || []);
const chartFields = computed(() => [{ key: 'qty', label: t('Qty') }]);

const columns = computed(() => [
  { title: t('ID'), dataIndex: 'transfer_id', key: 'transfer_id', sorter: true },
  { title: t('date'), key: 'date_time', dataIndex: 'date_time', sorter: true, exportValue: r => dateTime(r.date_time) },
  { title: t('From'), dataIndex: 'from', key: 'from' },
  { title: t('to'), dataIndex: 'to', key: 'to' },
  { title: t('Qty'), dataIndex: 'qty', key: 'qty', sorter: true, align: 'right', sum: true },
  { title: t('Value'), key: 'value', dataIndex: 'value', sorter: true, align: 'right', sum: 'money', exportValue: r => money(r.value) },
  { title: t('Status'), key: 'statut', dataIndex: 'statut', exportValue: r => r.statut },
]);

onMounted(crud.fetchRows);
</script>
