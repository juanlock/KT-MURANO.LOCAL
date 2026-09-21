<template>
  <ReportPage
    :title="$t('Sold_Serial_Numbers')"
    :breadcrumb="[$t('Reports'), $t('Sold_Serial_Numbers')]"
    :crud="crud"
    :columns="columns"
    row-key="id"
    export-endpoint="report/serials/sold"
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
      <template v-if="column.key === 'serial_number'">
        <code>{{ record.serial_number }}</code>
      </template>
      <template v-else-if="column.key === 'sale_date'">
        {{ record.sale_date ? date(record.sale_date) : '—' }}
      </template>
    </template>
  </ReportPage>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import ReportPage from '../../components/ReportPage.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';

const { t } = useI18n();
const { date } = useFormat();

const warehouseId = ref(undefined);
const filterParams = () => ({ warehouse_id: warehouseId.value || '' });

// Payload: { report, totalRows, warehouses } — rowsKey required.
const crud = useCrudTable('report/serials/sold', {
  rowsKey: 'report',
  sortField: 'sale_date',
  sortType: 'desc',
  params: filterParams,
});

const warehouseOptions = computed(() =>
  (crud.payload.value?.warehouses || []).map(w => ({ value: w.id, label: w.name }))
);

const columns = computed(() => [
  { title: t('Serial_Number'), key: 'serial_number', dataIndex: 'serial_number', sorter: true, exportValue: r => r.serial_number },
  { title: t('Name_product'), dataIndex: 'product_name', key: 'product_name', sorter: true },
  { title: t('warehouse'), dataIndex: 'warehouse_name', key: 'warehouse_name' },
  { title: t('Customer'), dataIndex: 'client_name', key: 'client_name' },
  { title: t('Sale'), dataIndex: 'sale_ref', key: 'sale_ref' },
  { title: t('date'), key: 'sale_date', dataIndex: 'sale_date', sorter: true, exportValue: r => (r.sale_date ? date(r.sale_date) : '') },
]);

onMounted(crud.fetchRows);
</script>
