<template>
  <ReportPage
    :title="$t('Service_Jobs_Report')"
    :breadcrumb="[$t('Reports'), $t('Service_Jobs_Report')]"
    :crud="crud"
    :columns="columns"
    row-key="id"
    export-endpoint="report/service_jobs"
    :export-params="filterParams"
    export-rows-key="rows"
  >
    <template #filters>
      <DateRangePicker v-model:value="range" allow-clear @change="crud.reload()" />
      <a-select
        v-model:value="clientId"
        style="width: 200px"
        allow-clear
        show-search
        option-filter-prop="label"
        :placeholder="$t('Customer')"
        :options="clientOptions"
        @change="crud.reload()"
      />
      <a-select
        v-model:value="technicianId"
        style="width: 200px"
        allow-clear
        show-search
        option-filter-prop="label"
        :placeholder="$t('Technician')"
        :options="technicianOptions"
        @change="crud.reload()"
      />
    </template>

    <template #bodyCell="{ column, record }">
      <template v-if="column.key === 'scheduled_date'">
        {{ record.scheduled_date ? date(record.scheduled_date) : '—' }}
      </template>
      <template v-else-if="column.key === 'status'">
        <a-tag :color="statusColor(record.status)">{{ record.status }}</a-tag>
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
import DateRangePicker from '../../components/DateRangePicker.vue';

const { t } = useI18n();
const { date } = useFormat();

const range = ref(null); // legacy defaults to no date filter here
const clientId = ref(undefined);
const technicianId = ref(undefined);

const filterParams = () => ({
  ...(range.value?.[0] ? { from: range.value[0].format('YYYY-MM-DD') } : {}),
  ...(range.value?.[1] ? { to: range.value[1].format('YYYY-MM-DD') } : {}),
  ...(clientId.value ? { client_id: clientId.value } : {}),
  ...(technicianId.value ? { technician_id: technicianId.value } : {}),
});

// Payload: { rows, totalRows, clients, technicians } — rowsKey required.
const crud = useCrudTable('report/service_jobs', {
  rowsKey: 'rows',
  sortField: 'scheduled_date',
  sortType: 'desc',
  params: filterParams,
});

const clientOptions = computed(() =>
  (crud.payload.value?.clients || []).map(c => ({ value: c.id, label: c.name }))
);
const technicianOptions = computed(() =>
  (crud.payload.value?.technicians || []).map(x => ({ value: x.id, label: x.name || x.username }))
);

const columns = computed(() => [
  { title: t('date'), key: 'scheduled_date', dataIndex: 'scheduled_date', sorter: true, exportValue: r => (r.scheduled_date ? date(r.scheduled_date) : '') },
  { title: t('Customer'), dataIndex: 'client_name', key: 'client_name' },
  { title: t('Technician'), dataIndex: 'technician_name', key: 'technician_name' },
  { title: t('Service_Item'), dataIndex: 'service_item', key: 'service_item' },
  { title: t('Job_Type'), dataIndex: 'job_type', key: 'job_type' },
  { title: t('Status'), key: 'status', dataIndex: 'status', exportValue: r => r.status },
]);

function statusColor(status) {
  const s = String(status || '').toLowerCase();
  if (s.includes('done') || s.includes('completed') || s.includes('ready')) return 'success';
  if (s.includes('progress') || s.includes('quoted')) return 'processing';
  if (s.includes('cancel')) return 'error';
  if (s.includes('pending') || s.includes('await')) return 'warning';
  return 'default';
}

onMounted(crud.fetchRows);
</script>
