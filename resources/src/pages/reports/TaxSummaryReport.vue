<template>
  <ReportPage
    :title="$t('Tax_Summary_Report')"
    :breadcrumb="[$t('Reports'), $t('Tax_Summary_Report')]"
    :crud="crud"
    :columns="columns"
    row-key="sale_id"
    export-endpoint="report/tax_summary"
    :export-params="filterParams"
    export-rows-key="report"
  >
    <template #actions>
      <!-- Multi-Currency: view amounts converted (display-only) -->
      <ViewCurrencySelect />
    </template>
    <template #filters>
      <DateRangePicker v-model:value="range" :allow-clear="false" @change="crud.reload()" />
      <a-typography-text v-if="totals.base" type="secondary">
        {{ $t('Taxable_Base') }}: <strong>{{ money(totals.base) }}</strong>
        &nbsp;·&nbsp;
        {{ $t('Tax_Collected') }}: <strong>{{ money(totals.tax) }}</strong>
      </a-typography-text>
    </template>

    <template #chart>
      <ReportChart
        :data="timeseries"
        :fields="chartFields"
        :title="$t('Tax_Collected')"
        type="line"
        :format="money"
      />
    </template>

    <template #bodyCell="{ column, record }">
      <template v-if="column.key === 'date_time'">{{ dateTime(record.date_time) }}</template>
      <template v-else-if="column.key === 'taxable_base'">{{ money(record.taxable_base) }}</template>
      <template v-else-if="column.key === 'tax_collected'">{{ money(record.tax_collected) }}</template>
      <template v-else-if="column.key === 'effective_rate'">{{ rate(record.effective_rate) }}</template>
    </template>
  </ReportPage>
</template>

<script setup>
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
const { money, dateTime, number } = useFormat();

const range = ref([dayjs().subtract(29, 'day'), dayjs()]);

const filterParams = () => ({
  from: range.value?.[0]?.format?.('YYYY-MM-DD') || '',
  to: range.value?.[1]?.format?.('YYYY-MM-DD') || '',
});

const crud = useCrudTable('report/tax_summary', {
  rowsKey: 'report',
  sortField: 'date_time',
  sortType: 'desc',
  params: filterParams,
});

const totals = computed(() => crud.payload.value?.totals || { base: 0, tax: 0 });

// Payload also carries a daily trend: [{ d, taxable_base, tax_collected }]
const timeseries = computed(() => crud.payload.value?.timeseries || []);
const chartFields = computed(() => [
  { key: 'taxable_base', label: t('Taxable_Base') },
  { key: 'tax_collected', label: t('Tax_Collected') },
]);

const rate = v => `${number(v, 2)}%`;

const columns = computed(() => [
  { title: t('ID'), dataIndex: 'sale_id', key: 'sale_id', sorter: true },
  { title: t('date'), key: 'date_time', dataIndex: 'date_time', sorter: true, exportValue: r => dateTime(r.date_time) },
  { title: t('User'), dataIndex: 'user_name', key: 'user_name' },
  { title: t('Taxable_Base'), key: 'taxable_base', dataIndex: 'taxable_base', sorter: true, align: 'right', sum: 'money', exportValue: r => money(r.taxable_base) },
  { title: t('Tax_Collected'), key: 'tax_collected', dataIndex: 'tax_collected', sorter: true, align: 'right', sum: 'money', exportValue: r => money(r.tax_collected) },
  { title: t('Effective_Rate'), key: 'effective_rate', dataIndex: 'effective_rate', align: 'right', exportValue: r => rate(r.effective_rate) },
]);

onMounted(crud.fetchRows);
</script>
