<template>
  <ReportPage
    :title="$t('Customer_Maintenance_History')"
    :breadcrumb="[$t('Reports'), $t('Customer_Maintenance_History')]"
    :crud="crud"
    :columns="columns"
    row-key="id"
    export-endpoint="report/customer_maintenance_history"
    :export-params="filterParams"
    export-rows-key="jobs"
  >
    <template #filters>
      <a-select
        v-model:value="clientId"
        style="width: 220px"
        allow-clear
        show-search
        option-filter-prop="label"
        :placeholder="$t('Choose_Customer')"
        :options="clientOptions"
        @change="crud.reload()"
      />
      <DateRangePicker v-model:value="range" allow-clear @change="crud.reload()" />
    </template>

    <template #bodyCell="{ column, record }">
      <template v-if="column.key === 'scheduled_date'">
        {{ record.scheduled_date ? date(record.scheduled_date) : '—' }}
      </template>
      <template v-else-if="column.key === 'status'">
        <a-tag>{{ record.status }}</a-tag>
      </template>
    </template>
  </ReportPage>
</template>

<script setup>
/**
 * The legacy "report" is a thin wrapper around
 * pages/service/CustomerMaintenanceHistory.vue; this is that component's
 * contract directly. NOTE the rows key: `jobs`, not `rows`/`report`.
 */
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import ReportPage from '../../components/ReportPage.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import DateRangePicker from '../../components/DateRangePicker.vue';

const { t } = useI18n();
const { date } = useFormat();

const clientId = ref(undefined);
const range = ref(null);

const filterParams = () => ({
  ...(clientId.value ? { client_id: clientId.value } : {}),
  ...(range.value?.[0] ? { from: range.value[0].format('YYYY-MM-DD') } : {}),
  ...(range.value?.[1] ? { to: range.value[1].format('YYYY-MM-DD') } : {}),
});

// Payload: { jobs, totalRows, clients }
const crud = useCrudTable('report/customer_maintenance_history', {
  rowsKey: 'jobs',
  sortField: 'scheduled_date',
  sortType: 'desc',
  params: filterParams,
});

const clientOptions = computed(() =>
  (crud.payload.value?.clients || []).map(c => ({ value: c.id, label: c.name }))
);

const columns = computed(() => [
  { title: t('date'), key: 'scheduled_date', dataIndex: 'scheduled_date', sorter: true, exportValue: r => (r.scheduled_date ? date(r.scheduled_date) : '') },
  { title: t('Customer'), dataIndex: 'client_name', key: 'client_name' },
  { title: t('Technician'), dataIndex: 'technician_name', key: 'technician_name' },
  { title: t('Service_Item'), dataIndex: 'service_item', key: 'service_item' },
  { title: t('Job_Type'), dataIndex: 'job_type', key: 'job_type' },
  { title: t('Status'), key: 'status', dataIndex: 'status', exportValue: r => r.status },
]);

onMounted(crud.fetchRows);
</script>
