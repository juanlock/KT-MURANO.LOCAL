<template>
  <ReportPage
    :title="$t('Stock_Adjustment_Report')"
    :breadcrumb="[$t('Reports'), $t('Stock_Adjustment_Report')]"
    :crud="crud"
    :columns="columns"
    row-key="adj_id"
    export-endpoint="report/stock_adjustment"
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

    <template #bodyCell="{ column, record }">
      <template v-if="column.key === 'date'">{{ date(record.date) }}</template>
      <template v-else-if="column.key === 'net_qty'">
        <a-tag :color="Number(record.net_qty) < 0 ? 'error' : 'success'">{{ record.net_qty }}</a-tag>
      </template>
      <template v-else-if="column.key === 'purchase_cost'">{{ money(record.purchase_cost) }}</template>
      <template v-else-if="column.key === 'sale_price'">{{ money(record.sale_price) }}</template>
    </template>
  </ReportPage>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import dayjs from 'dayjs';
import { useI18n } from 'vue-i18n';
import ReportPage from '../../components/ReportPage.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import DateRangePicker from '../../components/DateRangePicker.vue';
import ViewCurrencySelect from '../../components/ViewCurrencySelect.vue';

const { t } = useI18n();
const { money, date } = useFormat();

const range = ref([dayjs().subtract(29, 'day'), dayjs()]);
const warehouseId = ref(undefined);

const filterParams = () => ({
  from: range.value?.[0]?.format?.('YYYY-MM-DD') || '',
  to: range.value?.[1]?.format?.('YYYY-MM-DD') || '',
  warehouse_id: warehouseId.value || '',
});

// Nested payload like top_suppliers: { data: { rows, totalRows }, warehouses }.
const crud = useCrudTable('report/stock_adjustment', {
  sortField: 'dt',
  sortType: 'desc',
  params: filterParams,
  select: p => ({ rows: p?.data?.rows || [], total: p?.data?.totalRows || 0 }),
});

const warehouseOptions = computed(() =>
  (crud.payload.value?.warehouses || []).map(w => ({ value: w.id, label: w.name }))
);

const columns = computed(() => [
  { title: t('ID'), dataIndex: 'adj_id', key: 'adj_id', sorter: true },
  { title: t('Ref'), dataIndex: 'ref', key: 'ref', sorter: true },
  { title: t('date'), key: 'date', dataIndex: 'date', sorter: true, exportValue: r => date(r.date) },
  { title: t('warehouse'), dataIndex: 'warehouse', key: 'warehouse' },
  { title: t('Qty'), dataIndex: 'qty', key: 'qty', align: 'right', sum: true },
  { title: t('NetQty'), key: 'net_qty', dataIndex: 'net_qty', align: 'right', sum: true, exportValue: r => r.net_qty },
  { title: t('Purchase_Cost'), key: 'purchase_cost', dataIndex: 'purchase_cost', align: 'right', sum: 'money', exportValue: r => money(r.purchase_cost) },
  { title: t('Sale_Price'), key: 'sale_price', dataIndex: 'sale_price', align: 'right', sum: 'money', exportValue: r => money(r.sale_price) },
]);

onMounted(crud.fetchRows);
</script>
