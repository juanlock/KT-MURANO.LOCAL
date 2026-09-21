<template>
  <ReportPage
    :title="$t('Checklist_Completion_Report')"
    :breadcrumb="[$t('Reports'), $t('Checklist_Completion_Report')]"
    :crud="crud"
    :columns="columns"
    row-key="category_name"
    export-endpoint="report/service_checklist_completion"
    :export-params="filterParams"
    export-rows-key="rows"
  >
    <template #filters>
      <DateRangePicker v-model:value="range" allow-clear @change="crud.reload()" />
      <a-select
        v-model:value="technicianId" allow-clear show-search option-filter-prop="label" style="width: 200px"
        :placeholder="$t('Technician')" :options="technicianOptions" @change="crud.reload()"
      />
    </template>

    <template #bodyCell="{ column, record }">
      <template v-if="column.key === 'completed_items'">
        <a-space>
          {{ record.completed_items }}
          <a-progress
            :percent="percent(record)"
            size="small"
            style="width: 120px"
            :status="percent(record) >= 100 ? 'success' : 'active'"
          />
        </a-space>
      </template>
    </template>
  </ReportPage>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import ReportPage from '../../components/ReportPage.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import DateRangePicker from '../../components/DateRangePicker.vue';

const { t } = useI18n();

const range = ref(null);
const technicianId = ref(undefined);

const filterParams = () => ({
  ...(range.value?.[0] ? { from: range.value[0].format('YYYY-MM-DD') } : {}),
  ...(range.value?.[1] ? { to: range.value[1].format('YYYY-MM-DD') } : {}),
  ...(technicianId.value ? { technician_id: technicianId.value } : {}),
});

// Payload: { rows, technicians, clients, categories, job_types }.
const crud = useCrudTable('report/service_checklist_completion', {
  rowsKey: 'rows',
  params: filterParams,
});

const technicianOptions = computed(() =>
  (crud.payload.value?.technicians || []).map(x => ({ value: x.id, label: x.name }))
);

const columns = computed(() => [
  { title: t('Category'), dataIndex: 'category_name', key: 'category_name' },
  { title: t('Total_Items'), dataIndex: 'total_items', key: 'total_items', align: 'right', sum: true },
  { title: t('Completed_Items'), key: 'completed_items', dataIndex: 'completed_items', align: 'right', sum: true, exportValue: r => r.completed_items },
]);

function percent(record) {
  const total = Number(record.total_items) || 0;
  if (!total) return 0;
  return Math.round((Number(record.completed_items) || 0) / total * 100);
}

onMounted(crud.fetchRows);
</script>
