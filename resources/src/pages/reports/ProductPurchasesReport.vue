<template>
  <ReportPage
    :title="$t('Product_purchases_report')"
    :breadcrumb="[$t('Reports'), $t('Product_purchases_report')]"
    :crud="crud"
    :columns="columns"
    row-key="id"
    export-endpoint="report/product_purchases_report"
    :export-params="filterParams"
    export-rows-key="purchases"
  >
    <template #actions>
      <!-- Multi-Currency: view amounts converted (display-only) -->
      <ViewCurrencySelect />
    </template>
    <template #filters>
      <DateRangePicker v-model:value="range" allow-clear @change="crud.reload()" />
      <a-select
        v-model:value="warehouseId" style="width: 180px" allow-clear show-search option-filter-prop="label"
        :placeholder="$t('warehouse')" :options="opts('warehouses')" @change="crud.reload()" />
      <a-select
        v-model:value="providerId" style="width: 180px" allow-clear show-search option-filter-prop="label"
        :placeholder="$t('Supplier')" :options="opts('suppliers')" @change="crud.reload()" />
    </template>

    <template #bodyCell="{ column, record }">
      <template v-if="column.key === 'date'">{{ date(record.date) }}</template>
      <template v-else-if="column.key === 'total'">{{ money(record.total) }}</template>
    </template>
  </ReportPage>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import ReportPage from '../../components/ReportPage.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import DateRangePicker from '../../components/DateRangePicker.vue';
import ViewCurrencySelect from '../../components/ViewCurrencySelect.vue';

const { t } = useI18n();
const { money, date } = useFormat();

const range = ref(null);
const warehouseId = ref(undefined);
const providerId = ref(undefined);

const filterParams = () => ({
  ...(range.value?.[0] ? { from: range.value[0].format('YYYY-MM-DD') } : {}),
  ...(range.value?.[1] ? { to: range.value[1].format('YYYY-MM-DD') } : {}),
  ...(warehouseId.value ? { warehouse_id: warehouseId.value } : {}),
  ...(providerId.value ? { provider_id: providerId.value } : {}),
});

// Payload: { purchases, totalRows, suppliers, warehouses }
const crud = useCrudTable('report/product_purchases_report', {
  rowsKey: 'purchases',
  sortField: 'date',
  sortType: 'desc',
  params: filterParams,
});

const opts = key =>
  (crud.payload.value?.[key] || []).map(x => ({ value: x.id, label: x.name }));

const columns = computed(() => [
  { title: t('date'), key: 'date', dataIndex: 'date', sorter: true, exportValue: r => date(r.date) },
  { title: t('Reference'), dataIndex: 'Ref', key: 'Ref', sorter: true },
  { title: t('Supplier'), dataIndex: 'provider_name', key: 'provider_name' },
  { title: t('warehouse'), dataIndex: 'warehouse_name', key: 'warehouse_name' },
  { title: t('ProductName'), dataIndex: 'product_name', key: 'product_name' },
  { title: t('Quantity'), dataIndex: 'quantity', key: 'quantity', align: 'right', sum: true },
  { title: t('Total'), key: 'total', dataIndex: 'total', sorter: true, align: 'right', sum: 'money', exportValue: r => money(r.total) },
]);

onMounted(crud.fetchRows);
</script>
