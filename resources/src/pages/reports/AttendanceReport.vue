<template>
  <ReportPage
    :title="$t('attendance_summary')"
    :breadcrumb="[$t('Reports'), $t('attendance_summary')]"
    :crud="crud"
    :columns="columns"
    row-key="employee_id"
    export-endpoint="report/attendance_summary"
    :export-params="filterParams"
    export-rows-key="report"
  >
    <template #filters>
      <a-select v-model:value="scope" style="width: 140px" :options="scopeOptions" @change="crud.reload()" />

      <!-- The legacy page swaps a day picker for a month picker with the scope. -->
      <a-date-picker v-if="scope === 'daily'" v-model:value="day" :allow-clear="false" @change="crud.reload()" />
      <a-date-picker v-else v-model:value="month" picker="month" :allow-clear="false" @change="crud.reload()" />

      <a-select
        v-model:value="companyId"
        style="width: 180px"
        allow-clear
        show-search
        option-filter-prop="label"
        :placeholder="$t('Company')"
        :options="companyOptions"
        @change="crud.reload()"
      />
      <a-select
        v-model:value="employeeId"
        style="width: 180px"
        allow-clear
        show-search
        option-filter-prop="label"
        :placeholder="$t('Employee')"
        :options="employeeOptions"
        @change="crud.reload()"
      />
    </template>
  </ReportPage>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import dayjs from 'dayjs';
import { useI18n } from 'vue-i18n';
import ReportPage from '../../components/ReportPage.vue';
import { useCrudTable } from '../../composables/useCrudTable';

const { t } = useI18n();

const scope = ref('daily');
const day = ref(dayjs());
const month = ref(dayjs());
const companyId = ref(undefined);
const employeeId = ref(undefined);

// daily -> date=YYYY-MM-DD ; monthly -> month=YYYY-MM (legacy contract)
const filterParams = () => ({
  scope: scope.value,
  ...(scope.value === 'daily'
    ? { date: day.value?.format?.('YYYY-MM-DD') || '' }
    : { month: month.value?.format?.('YYYY-MM') || '' }),
  ...(companyId.value ? { company_id: companyId.value } : {}),
  ...(employeeId.value ? { employee_id: employeeId.value } : {}),
});

// Payload: { report, totalRows, companies, employees } — rowsKey required.
const crud = useCrudTable('report/attendance_summary', {
  rowsKey: 'report',
  sortField: 'employee_username',
  sortType: 'asc',
  params: filterParams,
});

const scopeOptions = computed(() => [
  { value: 'daily', label: t('Daily') },
  { value: 'monthly', label: t('Monthly') },
]);

const companyOptions = computed(() =>
  (crud.payload.value?.companies || []).map(c => ({ value: c.id, label: c.name }))
);
const employeeOptions = computed(() =>
  (crud.payload.value?.employees || []).map(e => ({ value: e.id, label: e.username || e.name }))
);

const columns = computed(() => [
  { title: t('Employee'), dataIndex: 'employee_username', key: 'employee_username', sorter: true },
  { title: t('Company'), dataIndex: 'company_name', key: 'company_name' },
  { title: t('Work_Duration'), dataIndex: 'total_work', key: 'total_work' },
]);

onMounted(crud.fetchRows);
</script>
