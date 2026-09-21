<template>
  <ReportPage
    :title="$t('Stock_Inventory_Valuation')"
    :breadcrumb="[$t('Reports'), $t('Stock_Inventory_Valuation')]"
    :crud="crud"
    :columns="columns"
    row-key="id"
    export-endpoint="report/stock_inventory_valuation"
    :export-params="filterParams"
    export-rows-key="reports"
  >
    <template #actions>
      <!-- Multi-Currency: view amounts converted (display-only) -->
      <ViewCurrencySelect />
    </template>
    <template #filters>
      <DateRangePicker v-model:value="range" allow-clear @change="crud.reload()" />
      <a-select
        v-model:value="warehouseId"
        style="width: 220px"
        allow-clear
        show-search
        option-filter-prop="label"
        :placeholder="$t('warehouse')"
        :options="warehouseOptions"
        @change="crud.reload()"
      />
    </template>

    <template #bodyCell="{ column, record }">
      <template v-if="MONEY_KEYS.includes(column.key)">{{ money(record[column.key]) }}</template>
      <template v-else-if="column.key === 'potential_profit'">
        <strong :style="{ color: Number(record.potential_profit) >= 0 ? '#52c41a' : '#ff4d4f' }">
          {{ money(record.potential_profit) }}
        </strong>
      </template>
    </template>
  </ReportPage>
</template>

<script setup>
/** Date params here are date_from/date_to — the API's fifth date dialect. */
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import ReportPage from '../../components/ReportPage.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import DateRangePicker from '../../components/DateRangePicker.vue';
import ViewCurrencySelect from '../../components/ViewCurrencySelect.vue';

const { t } = useI18n();
const { money } = useFormat();

const MONEY_KEYS = ['selling_price', 'stock_value_cost', 'stock_value_selling'];

const range = ref(null);
const warehouseId = ref(undefined);

const filterParams = () => ({
  ...(range.value?.[0] ? { date_from: range.value[0].format('YYYY-MM-DD') } : {}),
  ...(range.value?.[1] ? { date_to: range.value[1].format('YYYY-MM-DD') } : {}),
  warehouse_id: warehouseId.value || '',
});

// Payload: { reports, totalRows, warehouses }
const crud = useCrudTable('report/stock_inventory_valuation', {
  rowsKey: 'reports',
  sortField: 'stock_value_cost',
  sortType: 'desc',
  params: filterParams,
});

const warehouseOptions = computed(() =>
  (crud.payload.value?.warehouses || []).map(w => ({ value: w.id, label: w.name }))
);

const columns = computed(() => [
  { title: t('SKU'), dataIndex: 'sku', key: 'sku', sorter: true },
  { title: t('product_name'), dataIndex: 'product_name', key: 'product_name', sorter: true },
  { title: t('Variant'), dataIndex: 'variant', key: 'variant' },
  { title: t('Category'), dataIndex: 'category', key: 'category' },
  { title: t('Warehouse'), dataIndex: 'warehouse', key: 'warehouse' },
  { title: t('Selling_Price_Unit'), key: 'selling_price', dataIndex: 'selling_price', align: 'right', exportValue: r => money(r.selling_price) },
  { title: t('Current_Quantity'), dataIndex: 'current_quantity', key: 'current_quantity', sorter: true, align: 'right', sum: true },
  { title: t('Stock_Value_Cost'), key: 'stock_value_cost', dataIndex: 'stock_value_cost', sorter: true, align: 'right', sum: 'money', exportValue: r => money(r.stock_value_cost) },
  { title: t('Stock_Value_Selling'), key: 'stock_value_selling', dataIndex: 'stock_value_selling', sorter: true, align: 'right', sum: 'money', exportValue: r => money(r.stock_value_selling) },
  { title: t('Potential_Profit'), key: 'potential_profit', dataIndex: 'potential_profit', sorter: true, align: 'right', sum: 'money', exportValue: r => money(r.potential_profit) },
  { title: t('Total_Units_Sold'), dataIndex: 'total_units_sold', key: 'total_units_sold', align: 'right', sum: true },
  { title: t('Total_Units_Transferred'), dataIndex: 'total_units_transferred', key: 'total_units_transferred', align: 'right', sum: true },
  { title: t('Total_Units_Adjusted'), dataIndex: 'total_units_adjusted', key: 'total_units_adjusted', align: 'right', sum: true },
]);

onMounted(crud.fetchRows);
</script>
