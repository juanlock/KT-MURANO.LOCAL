<template>
  <ReportPage
    :title="$t('Batch_Register_Report')"
    :breadcrumb="[$t('Reports'), $t('Batch_Register_Report')]"
    :crud="crud"
    :columns="columns"
    row-key="id"
    export-endpoint="report/batches/register"
    :export-params="filterParams"
    export-rows-key="batches"
  >
    <template #actions>
      <!-- Multi-Currency: view amounts converted (display-only) -->
      <ViewCurrencySelect />
    </template>
    <template #filters>
      <a-select
        v-model:value="warehouseId"
        style="width: 190px"
        allow-clear
        show-search
        option-filter-prop="label"
        :placeholder="$t('Warehouse')"
        :options="warehouseOptions"
        @change="crud.reload()"
      />
      <a-select
        v-model:value="supplierId"
        style="width: 190px"
        allow-clear
        show-search
        option-filter-prop="label"
        :placeholder="$t('Supplier')"
        :options="supplierOptions"
        @change="crud.reload()"
      />
      <a-select
        v-model:value="status"
        style="width: 160px"
        allow-clear
        :placeholder="$t('Status')"
        :options="statusOptions"
        @change="crud.reload()"
      />
      <a-select
        v-model:value="expiryWindow" style="width: 190px" allow-clear
        :placeholder="$t('Expiry_Date')" :options="expiryWindowOptions" @change="crud.reload()"
      />
      <DateRangePicker
        v-model:value="purchaseRange" allow-clear style="width: 240px"
        :placeholder="[$t('Purchase') + ' ' + $t('From'), $t('To')]" @change="crud.reload()"
      />
    </template>

    <template #bodyCell="{ column, record }">
      <template v-if="column.key === 'mfg_date'">{{ record.mfg_date ? date(record.mfg_date) : '—' }}</template>
      <template v-else-if="column.key === 'expiry_date'">{{ record.expiry_date ? date(record.expiry_date) : '—' }}</template>
      <template v-else-if="column.key === 'unit_cost'">{{ money(record.unit_cost) }}</template>
      <template v-else-if="column.key === 'value'">{{ money(record.value) }}</template>
      <template v-else-if="column.key === 'status'">
        <a-tag :color="statusColor(record.status)">{{ record.status }}</a-tag>
      </template>
      <template v-else-if="column.key === 'actions'">
        <a-tooltip :title="$t('Reports')">
          <a-button type="text" size="small" @click="$router.push(`/reports/batch-history/${record.id}`)">
            <template #icon><EyeOutlined /></template>
          </a-button>
        </a-tooltip>
      </template>
    </template>
  </ReportPage>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { EyeOutlined } from '@ant-design/icons-vue';
import ReportPage from '../../components/ReportPage.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import DateRangePicker from '../../components/DateRangePicker.vue';
import ViewCurrencySelect from '../../components/ViewCurrencySelect.vue';

const { t } = useI18n();
const { money, date } = useFormat();

const warehouseId = ref(undefined);
const supplierId = ref(undefined);
const status = ref(undefined);
const expiryWindow = ref(undefined);
const purchaseRange = ref(null);

// Legacy only sends these when set, rather than as empty strings.
const filterParams = () => ({
  ...(warehouseId.value ? { warehouse_id: warehouseId.value } : {}),
  ...(supplierId.value ? { supplier_id: supplierId.value } : {}),
  ...(status.value ? { status: status.value } : {}),
  ...(expiryWindow.value ? { expiry_window: expiryWindow.value } : {}),
  ...(purchaseRange.value?.[0] ? { purchase_date_from: purchaseRange.value[0].format('YYYY-MM-DD') } : {}),
  ...(purchaseRange.value?.[1] ? { purchase_date_to: purchaseRange.value[1].format('YYYY-MM-DD') } : {}),
});

// Payload: { batches, totalRows, warehouses, suppliers } — three arrays, so
// rowsKey is mandatory.
const crud = useCrudTable('report/batches/register', {
  rowsKey: 'batches',
  sortField: 'expiry_date',
  sortType: 'asc',
  params: filterParams,
});

const warehouseOptions = computed(() =>
  (crud.payload.value?.warehouses || []).map(w => ({ value: w.id, label: w.name }))
);
const supplierOptions = computed(() =>
  (crud.payload.value?.suppliers || []).map(s => ({ value: s.id, label: s.name }))
);
const statusOptions = computed(() => [
  { value: 'active', label: t('Active') },
  { value: 'expired', label: t('Expired') },
]);
const expiryWindowOptions = computed(() => [
  { value: 'all', label: t('All') },
  { value: 'expired', label: t('Expired') },
  { value: 'near', label: t('Near_Expiry') },
  { value: 'valid', label: t('Valid') },
  { value: 'expired_or_near', label: `${t('Expired')} + ${t('Near_Expiry')}` },
]);

const columns = computed(() => [
  { title: t('Product'), dataIndex: 'product', key: 'product', sorter: true },
  { title: t('Batch_No'), dataIndex: 'batch_no', key: 'batch_no' },
  { title: t('Warehouse'), dataIndex: 'warehouse_name', key: 'warehouse_name' },
  { title: t('Mfg_Date'), key: 'mfg_date', dataIndex: 'mfg_date', sorter: true, exportValue: r => (r.mfg_date ? date(r.mfg_date) : '') },
  { title: t('Expiry_Date'), key: 'expiry_date', dataIndex: 'expiry_date', sorter: true, exportValue: r => (r.expiry_date ? date(r.expiry_date) : '') },
  { title: t('Quantity'), dataIndex: 'qty', key: 'qty', align: 'right', sum: true },
  { title: t('Unit_Cost'), key: 'unit_cost', dataIndex: 'unit_cost', align: 'right', exportValue: r => money(r.unit_cost) },
  { title: t('Value'), key: 'value', dataIndex: 'value', align: 'right', sum: 'money', exportValue: r => money(r.value) },
  { title: t('Status'), key: 'status', dataIndex: 'status', exportValue: r => r.status },
  { title: t('Purchase_Ref'), dataIndex: 'origin_purchase_ref', key: 'origin_purchase_ref' },
  { title: t('Supplier'), dataIndex: 'origin_supplier_name', key: 'origin_supplier_name' },
  { title: t('Action'), key: 'actions', align: 'center', width: 80, exportable: false },
]);

function statusColor(s) {
  const v = String(s || '').toLowerCase();
  if (v.includes('expired')) return 'error';
  if (v.includes('soon') || v.includes('near')) return 'warning';
  return 'success';
}

onMounted(crud.fetchRows);
</script>
