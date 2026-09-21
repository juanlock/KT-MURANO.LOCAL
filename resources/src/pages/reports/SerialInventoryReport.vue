<template>
  <ReportPage
    :title="$t('Product_Serial_Inventory')"
    :breadcrumb="[$t('Reports'), $t('Product_Serial_Inventory')]"
    :crud="crud"
    :columns="columns"
    row-key="product_id"
    export-endpoint="report/serials/inventory"
    :export-params="filterParams"
    export-rows-key="report"
  >
    <template #filters>
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
      <template v-if="column.key === 'available'">
        <a-tag color="success">{{ record.available ?? 0 }}</a-tag>
      </template>
      <template v-else-if="column.key === 'sold'">
        <a-tag color="processing">{{ record.sold ?? 0 }}</a-tag>
      </template>
      <template v-else-if="column.key === 'damaged'">
        <a-tag :color="Number(record.damaged) > 0 ? 'error' : 'default'">{{ record.damaged ?? 0 }}</a-tag>
      </template>
    </template>
  </ReportPage>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import ReportPage from '../../components/ReportPage.vue';
import { useCrudTable } from '../../composables/useCrudTable';

const { t } = useI18n();

const warehouseId = ref(undefined);
const filterParams = () => ({ warehouse_id: warehouseId.value || '' });

// Payload: { report, totalRows, warehouses }
// NOTE: the legacy page sends no SortField/SortType here — the endpoint is a
// per-product aggregate. useCrudTable still sends them; harmless, and sorting
// is left off the columns to match legacy behaviour.
const crud = useCrudTable('report/serials/inventory', {
  rowsKey: 'report',
  params: filterParams,
});

const warehouseOptions = computed(() =>
  (crud.payload.value?.warehouses || []).map(w => ({ value: w.id, label: w.name }))
);

const columns = computed(() => [
  { title: t('Name_product'), dataIndex: 'product_name', key: 'product_name' },
  { title: t('ProductCode'), dataIndex: 'product_code', key: 'product_code' },
  { title: t('Status_available'), key: 'available', dataIndex: 'available', align: 'right', sum: true, exportValue: r => r.available ?? 0 },
  { title: t('Status_sold'), key: 'sold', dataIndex: 'sold', align: 'right', sum: true, exportValue: r => r.sold ?? 0 },
  { title: t('Status_returned_supplier'), dataIndex: 'returned_supplier', key: 'returned_supplier', align: 'right', sum: true },
  { title: t('Status_damaged'), key: 'damaged', dataIndex: 'damaged', align: 'right', sum: true, exportValue: r => r.damaged ?? 0 },
  { title: t('Status_reserved'), dataIndex: 'reserved', key: 'reserved', align: 'right', sum: true },
  { title: t('Total'), dataIndex: 'total', key: 'total', align: 'right', sum: true },
]);

onMounted(crud.fetchRows);
</script>
