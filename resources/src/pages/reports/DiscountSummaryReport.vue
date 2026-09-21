<template>
  <ReportPage
    :title="$t('Discount_Summary_Report')"
    :breadcrumb="[$t('Reports'), $t('Discount_Summary_Report')]"
    :crud="crud"
    :columns="columns"
    row-key="sale_id"
    export-endpoint="report/discount_summary"
    :export-params="filterParams"
    export-rows-key="report"
  >
    <template #actions>
      <!-- Multi-Currency: view amounts converted (display-only) -->
      <ViewCurrencySelect />
    </template>
    <template #filters>
      <DateRangePicker v-model:value="range" :allow-clear="false" @change="crud.reload()" />
      <a-typography-text v-if="overallTotal" strong>
        {{ $t('Total_Discount') }}: {{ money(overallTotal) }}
      </a-typography-text>
    </template>

    <template #chart>
      <ReportChart
        :data="timeseries"
        :fields="chartFields"
        :title="$t('Total_Discount')"
        type="area"
        :format="money"
      />
    </template>

    <template #bodyCell="{ column, record }">
      <template v-if="column.key === 'date_time'">{{ dateTime(record.date_time) }}</template>
      <template v-else-if="MONEY_KEYS.includes(column.key)">{{ money(record[column.key]) }}</template>
    </template>
  </ReportPage>
</template>

<script setup>
/**
 * First report with a date range — the pattern for the ~27 others that filter
 * by period. dayjs objects come from a-range-picker; the API wants YYYY-MM-DD.
 */
import { ref, computed, onMounted } from 'vue';
import dayjs from 'dayjs';
import { useI18n } from 'vue-i18n';
import ReportPage from '../../components/ReportPage.vue';
import ReportChart from '../../components/ReportChart.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import DateRangePicker from '../../components/DateRangePicker.vue';
import ViewCurrencySelect from '../../components/ViewCurrencySelect.vue';

const { t } = useI18n();
const { money, dateTime } = useFormat();

const MONEY_KEYS = ['line_discount', 'header_manual_discount', 'header_points_discount', 'total_discount'];

// Default to the last 30 days, like the legacy page.
const range = ref([dayjs().subtract(29, 'day'), dayjs()]);

const filterParams = () => ({
  from: range.value?.[0]?.format?.('YYYY-MM-DD') || '',
  to: range.value?.[1]?.format?.('YYYY-MM-DD') || '',
});

const crud = useCrudTable('report/discount_summary', {
  rowsKey: 'report',
  sortField: 'date_time',
  sortType: 'desc',
  params: filterParams,
});

const overallTotal = computed(() => Number(crud.payload.value?.overall_total || 0));

// Payload also carries a daily trend: [{ d, total_discount }]
const timeseries = computed(() => crud.payload.value?.timeseries || []);
const chartFields = computed(() => [{ key: 'total_discount', label: t('Total_Discount') }]);

const columns = computed(() => [
  { title: t('ID'), dataIndex: 'sale_id', key: 'sale_id', sorter: true },
  { title: t('date'), key: 'date_time', dataIndex: 'date_time', sorter: true, exportValue: r => dateTime(r.date_time) },
  { title: t('User'), dataIndex: 'user_name', key: 'user_name' },
  { title: t('Line_Discount'), key: 'line_discount', dataIndex: 'line_discount', align: 'right', sum: 'money', exportValue: r => money(r.line_discount) },
  { title: t('Header_Discount'), key: 'header_manual_discount', dataIndex: 'header_manual_discount', align: 'right', sum: 'money', exportValue: r => money(r.header_manual_discount) },
  { title: t('Discount_from_Points'), key: 'header_points_discount', dataIndex: 'header_points_discount', align: 'right', sum: 'money', exportValue: r => money(r.header_points_discount) },
  { title: t('Total_Discount'), key: 'total_discount', dataIndex: 'total_discount', align: 'right', sum: 'money', exportValue: r => money(r.total_discount) },
]);

onMounted(crud.fetchRows);
</script>
