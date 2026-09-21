<template>
  <ReportPage
    :title="$t('Top_Suppliers_Report')"
    :breadcrumb="[$t('Reports'), $t('Top_Suppliers_Report')]"
    :crud="crud"
    :columns="columns"
    row-key="supplier_id"
    export-endpoint="report/top_suppliers"
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
      <ReportChart
        :data="topByValue"
        :fields="[{ key: 'value_sum', label: $t('TotalSpend') }]"
        :title="$t('Top_Suppliers_Report')"
        type="bar"
        x-key="supplier"
        :format="money"
      />
    </template>

    <template #bodyCell="{ column, record }">
      <template v-if="column.key === 'value_sum'">{{ money(record.value_sum) }}</template>
      <template v-else-if="column.key === 'avg_value'">{{ money(record.avg_value) }}</template>
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
const warehouseId = ref(undefined);

const filterParams = () => ({
  from: range.value?.[0]?.format?.('YYYY-MM-DD') || '',
  to: range.value?.[1]?.format?.('YYYY-MM-DD') || '',
  warehouse_id: warehouseId.value || '',
});

// This endpoint nests its payload: { data: { rows, totalRows, kpis, topByValue,
// topByQty }, warehouses } — hence `select` rather than `rowsKey`.
const crud = useCrudTable('report/top_suppliers', {
  sortField: 'value_sum',
  sortType: 'desc',
  params: filterParams,
  select: p => ({ rows: p?.data?.rows || [], total: p?.data?.totalRows || 0 }),
});

const warehouseOptions = computed(() =>
  (crud.payload.value?.warehouses || []).map(w => ({ value: w.id, label: w.name }))
);

// Top suppliers by spend — already ranked by the API.
const topByValue = computed(() => crud.payload.value?.data?.topByValue || []);

const columns = computed(() => [
  { title: t('Supplier'), dataIndex: 'supplier', key: 'supplier', sorter: true },
  { title: t('Purchases'), dataIndex: 'orders_count', key: 'orders_count', sorter: true, align: 'right', sum: true },
  { title: t('QtyPurchased'), dataIndex: 'qty_sum', key: 'qty_sum', sorter: true, align: 'right', sum: true },
  { title: t('TotalSpend'), key: 'value_sum', dataIndex: 'value_sum', sorter: true, align: 'right', sum: 'money', exportValue: r => money(r.value_sum) },
  { title: t('AvgPerPurchase'), key: 'avg_value', dataIndex: 'avg_value', sorter: true, align: 'right', exportValue: r => money(r.avg_value) },
]);

onMounted(crud.fetchRows);
</script>
