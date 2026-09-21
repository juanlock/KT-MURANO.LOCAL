<template>
  <ReportPage
    :title="$t('Report_Transactions')"
    :breadcrumb="[$t('Reports'), $t('Report_Transactions')]"
    :crud="crud"
    :columns="columns"
    row-key="id"
    export-endpoint="report/report_transactions"
    :export-params="filterParams"
    export-rows-key="payments"
  >
    <template #actions>
      <!-- Multi-Currency: view amounts converted (display-only) -->
      <ViewCurrencySelect />
    </template>
    <template #filters>
      <DateRangePicker v-model:value="range" allow-clear @change="crud.reload()" />
      <a-select
        v-model:value="clientId" style="width: 170px" allow-clear show-search option-filter-prop="label"
        :placeholder="$t('Customer')" :options="opts('clients')" @change="crud.reload()" />
      <a-select
        v-model:value="providerId" style="width: 170px" allow-clear show-search option-filter-prop="label"
        :placeholder="$t('Supplier')" :options="opts('suppliers')" @change="crud.reload()" />
      <a-select
        v-model:value="paymentMethodId" style="width: 170px" allow-clear show-search option-filter-prop="label"
        :placeholder="$t('ModePaiement')" :options="opts('payment_methods')" @change="crud.reload()" />
    </template>

    <template #bodyCell="{ column, record }">
      <template v-if="column.key === 'date'">{{ date(record.date) }}</template>
      <template v-else-if="column.key === 'montant'">
        <strong>{{ money(record.montant) }}</strong>
      </template>
      <template v-else-if="column.key === 'payment_method'">
        <a-tag>{{ record.payment_method }}</a-tag>
      </template>
    </template>
  </ReportPage>
</template>

<script setup>
/**
 * Legacy also offers sale_id/purchase_id pickers; not carried over (huge
 * option lists for a rarely-used narrowing — the search box covers it).
 */
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import ReportPage from '../../components/ReportPage.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import DateRangePicker from '../../components/DateRangePicker.vue';
import ViewCurrencySelect from '../../components/ViewCurrencySelect.vue';

const { t } = useI18n();
const { money, date } = useFormat();

const range = ref(null);
const clientId = ref(undefined);
const providerId = ref(undefined);
const paymentMethodId = ref(undefined);

const filterParams = () => ({
  ...(range.value?.[0] ? { from: range.value[0].format('YYYY-MM-DD') } : {}),
  ...(range.value?.[1] ? { to: range.value[1].format('YYYY-MM-DD') } : {}),
  ...(clientId.value ? { client_id: clientId.value } : {}),
  ...(providerId.value ? { provider_id: providerId.value } : {}),
  ...(paymentMethodId.value ? { payment_method_id: paymentMethodId.value } : {}),
});

// Payload: { payments, totalRows, clients, suppliers, sales, purchases,
// payment_methods, payment_summary } — rowsKey required.
const crud = useCrudTable('report/report_transactions', {
  rowsKey: 'payments',
  sortField: 'date',
  sortType: 'desc',
  params: filterParams,
});

const opts = key =>
  (crud.payload.value?.[key] || []).map(x => ({ value: x.id, label: x.name }));

const columns = computed(() => [
  { title: t('date'), key: 'date', dataIndex: 'date', sorter: true, exportValue: r => date(r.date) },
  { title: t('Reference'), dataIndex: 'Ref', key: 'Ref', sorter: true },
  { title: t('Sale'), dataIndex: 'Ref_Sale', key: 'Ref_Sale' },
  { title: t('Customer'), dataIndex: 'client_name', key: 'client_name' },
  { title: t('ModePaiement'), key: 'payment_method', dataIndex: 'payment_method', exportValue: r => r.payment_method },
  { title: t('Account'), dataIndex: 'account_name', key: 'account_name' },
  { title: t('Amount'), key: 'montant', dataIndex: 'montant', sorter: true, align: 'right', sum: 'money', exportValue: r => money(r.montant) },
  { title: t('AddedBy'), dataIndex: 'user_name', key: 'user_name' },
]);

onMounted(crud.fetchRows);
</script>
