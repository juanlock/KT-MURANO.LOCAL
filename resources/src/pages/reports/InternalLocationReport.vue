<template>
  <ReportPage
    :title="$t('Internal_Location_Report')"
    :breadcrumb="[$t('Reports'), $t('Internal_Location_Report')]"
    :crud="crud"
    :columns="columns"
    row-key="id"
    export-endpoint="report/internal_location_report"
    :export-params="filterParams"
    export-rows-key="rows"
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
      <template v-if="column.key === 'location'">
        <a-tag v-if="record.location" color="blue">{{ record.location }}</a-tag>
        <span v-else style="opacity: 0.45">—</span>
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

// Payload: { rows, totalRows, warehouses }
const crud = useCrudTable('report/internal_location_report', {
  rowsKey: 'rows',
  params: filterParams,
});

const warehouseOptions = computed(() =>
  (crud.payload.value?.warehouses || []).map(w => ({ value: w.id, label: w.name }))
);

const columns = computed(() => [
  { title: t('warehouse'), dataIndex: 'warehouse', key: 'warehouse' },
  { title: t('CodeProduct'), dataIndex: 'code', key: 'code', sorter: true },
  { title: t('ProductName'), dataIndex: 'name', key: 'name', sorter: true },
  { title: t('Internal_Location_Rack_Shelf'), key: 'location', dataIndex: 'location', exportValue: r => r.location || '' },
]);

onMounted(crud.fetchRows);
</script>
