<template>
  <ReportPage
    :title="$t('Serial_Movement_Log')"
    :breadcrumb="[$t('Reports'), $t('Serial_Movement_Log')]"
    :crud="crud"
    :columns="columns"
    row-key="id"
    export-endpoint="report/serials/movements"
    :export-params="filterParams"
    export-rows-key="report"
  >
    <template #filters>
      <DateRangePicker v-model:value="range" allow-clear @change="crud.reload()" />
      <a-select
        v-model:value="action" allow-clear style="width: 200px"
        :placeholder="$t('Action')" :options="actionOptions" @change="crud.reload()"
      />
    </template>

    <template #bodyCell="{ column, record }">
      <template v-if="column.key === 'created_at'">{{ dateTime(record.created_at) }}</template>
      <template v-else-if="column.key === 'serial_number'">
        <code>{{ record.serial_number }}</code>
      </template>
      <template v-else-if="column.key === 'action'">
        <a-tag :color="actionColor(record.action)">{{ record.action }}</a-tag>
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
const { dateTime } = useFormat();

const range = ref(null);
const action = ref(undefined);

const filterParams = () => ({
  ...(range.value?.[0] ? { from_date: range.value[0].format('YYYY-MM-DD') } : {}),
  ...(range.value?.[1] ? { to_date: range.value[1].format('YYYY-MM-DD') } : {}),
  ...(action.value ? { action: action.value } : {}),
});

const actionOptions = computed(() => [
  { value: 'purchased', label: t('Purchased') },
  { value: 'sold', label: t('Status_sold') },
  { value: 'sale_returned', label: t('Status_returned_customer') },
  { value: 'purchase_returned', label: t('Status_returned_supplier') },
  { value: 'status_changed', label: t('Status_changed') },
]);

// Payload: { report, totalRows }
const crud = useCrudTable('report/serials/movements', {
  rowsKey: 'report',
  sortField: 'created_at',
  sortType: 'desc',
  params: filterParams,
});

const columns = computed(() => [
  { title: t('date'), key: 'created_at', dataIndex: 'created_at', sorter: true, exportValue: r => dateTime(r.created_at) },
  { title: t('Serial_Number'), key: 'serial_number', dataIndex: 'serial_number', sorter: true, exportValue: r => r.serial_number },
  { title: t('Action'), key: 'action', dataIndex: 'action', exportValue: r => r.action },
  { title: t('Serial_Status'), dataIndex: 'transition', key: 'transition' },
  { title: t('Reference'), dataIndex: 'reference', key: 'reference' },
]);

function actionColor(action) {
  const a = String(action || '').toLowerCase();
  if (a.includes('sold') || a.includes('out')) return 'error';
  if (a.includes('return')) return 'warning';
  if (a.includes('in') || a.includes('add')) return 'success';
  return 'default';
}

onMounted(crud.fetchRows);
</script>
