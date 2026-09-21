<template>
  <ReportPage
    :title="$t('Cash_Register_Report')"
    :breadcrumb="[$t('Reports'), $t('Cash_Register_Report')]"
    :crud="crud"
    :columns="columns"
    row-key="id"
    export-endpoint="report/cash_registers"
    :export-params="filterParams"
    export-rows-key="registers"
  >
    <template #actions>
      <!-- Multi-Currency: view amounts converted (display-only) -->
      <ViewCurrencySelect />
    </template>
    <template #filters>
      <DateRangePicker v-model:value="range" :allow-clear="false" @change="crud.reload()" />
      <a-select
        v-model:value="warehouseId"
        style="width: 200px"
        allow-clear
        show-search
        option-filter-prop="label"
        :placeholder="$t('warehouse')"
        :options="warehouseOptions"
        @change="crud.reload()"
      />
    </template>

    <template #bodyCell="{ column, record }">
      <template v-if="column.key === 'opened_at'">{{ dateTime(record.opened_at) }}</template>
      <template v-else-if="column.key === 'closed_at'">
        {{ record.closed_at ? dateTime(record.closed_at) : '—' }}
      </template>
      <template v-else-if="MONEY_KEYS.includes(column.key)">{{ money(record[column.key]) }}</template>
      <template v-else-if="column.key === 'difference'">
        <!-- A non-zero difference is the point of this report. -->
        <strong :style="{ color: Number(record.difference) === 0 ? undefined : '#ff4d4f' }">
          {{ money(record.difference) }}
        </strong>
      </template>
      <template v-else-if="column.key === 'status'">
        <a-tag :color="String(record.status).toLowerCase().includes('open') ? 'processing' : 'default'">
          {{ record.status }}
        </a-tag>
      </template>
    </template>
  </ReportPage>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import dayjs from 'dayjs';
import { useI18n } from 'vue-i18n';
import ReportPage from '../../components/ReportPage.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import DateRangePicker from '../../components/DateRangePicker.vue';
import ViewCurrencySelect from '../../components/ViewCurrencySelect.vue';

const { t } = useI18n();
const { money, dateTime } = useFormat();

const MONEY_KEYS = ['opening_balance', 'cash_in', 'cash_out', 'total_sales', 'closing_balance'];

const range = ref([dayjs().subtract(29, 'day'), dayjs()]);
const warehouseId = ref(undefined);

const filterParams = () => ({
  from: range.value?.[0]?.format?.('YYYY-MM-DD') || '',
  to: range.value?.[1]?.format?.('YYYY-MM-DD') || '',
  ...(warehouseId.value ? { warehouse_id: warehouseId.value } : {}),
});

// Payload: { registers, totalRows, users, warehouses } — rowsKey required.
const crud = useCrudTable('report/cash_registers', {
  rowsKey: 'registers',
  sortField: 'opened_at',
  sortType: 'desc',
  params: filterParams,
});

const warehouseOptions = computed(() =>
  (crud.payload.value?.warehouses || []).map(w => ({ value: w.id, label: w.name }))
);

const columns = computed(() => [
  { title: t('Cashier'), dataIndex: 'cashier', key: 'cashier', sorter: true },
  { title: t('warehouse'), dataIndex: 'warehouse', key: 'warehouse' },
  { title: t('Opened'), key: 'opened_at', dataIndex: 'opened_at', sorter: true, exportValue: r => dateTime(r.opened_at) },
  { title: t('Opening'), key: 'opening_balance', dataIndex: 'opening_balance', align: 'right', exportValue: r => money(r.opening_balance) },
  { title: t('Cash In'), key: 'cash_in', dataIndex: 'cash_in', align: 'right', sum: 'money', exportValue: r => money(r.cash_in) },
  { title: t('Cash Out'), key: 'cash_out', dataIndex: 'cash_out', align: 'right', sum: 'money', exportValue: r => money(r.cash_out) },
  { title: t('TotalSales'), key: 'total_sales', dataIndex: 'total_sales', align: 'right', sum: 'money', exportValue: r => money(r.total_sales) },
  { title: t('Closed'), key: 'closed_at', dataIndex: 'closed_at', sorter: true, exportValue: r => (r.closed_at ? dateTime(r.closed_at) : '') },
  { title: t('Closing'), key: 'closing_balance', dataIndex: 'closing_balance', align: 'right', exportValue: r => money(r.closing_balance) },
  { title: t('Difference'), key: 'difference', dataIndex: 'difference', align: 'right', sum: 'money', exportValue: r => money(r.difference) },
  { title: t('Status'), key: 'status', dataIndex: 'status', exportValue: r => r.status },
]);

onMounted(crud.fetchRows);
</script>
