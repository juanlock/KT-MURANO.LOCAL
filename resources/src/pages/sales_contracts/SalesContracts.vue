<template>
  <div class="page">
    <PageHeader title="Sales Contracts" :breadcrumb="[$t('Sales'), 'Sales Contracts']">
      <template #actions>
        <a-button v-if="can('sales_contracts_add')" type="primary" @click="$router.push('/sales-contracts/create')">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <!-- Summary cards -->
    <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
      <a-col v-for="tile in tiles" :key="tile.label" flex="1 1 180px">
        <a-card size="small">
          <a-statistic :title="tile.label" :value="tile.value" :value-style="tile.style" />
        </a-card>
      </a-col>
    </a-row>

    <!-- Filters -->
    <a-card size="small" style="margin-bottom: 12px">
      <div class="sc-filters">
        <a-select v-model:value="status" allow-clear placeholder="Status" style="flex: 0 1 160px" :options="statusOptions" @change="crud.reload()" />
        <a-select v-model:value="clientId" allow-clear show-search option-filter-prop="label" :placeholder="$t('Customer')" style="flex: 1 1 220px" :options="clientOptions" @change="crud.reload()" />
        <a-select v-model:value="billing" allow-clear placeholder="Billing" style="flex: 0 1 180px" :options="BILLING_OPTIONS" @change="crud.reload()" />
      </div>
    </a-card>

    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'actions'">
          <a-space :size="2">
            <a-tooltip :title="$t('View')">
              <a-button type="text" size="small" @click="$router.push(`/sales-contracts/${record.id}`)">
                <template #icon><EyeOutlined style="color: #1677ff" /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip v-if="can('sales_contracts_edit')" :title="$t('Edit')">
              <a-button type="text" size="small" @click="$router.push(`/sales-contracts/${record.id}/edit`)">
                <template #icon><EditOutlined style="color: #52c41a" /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip v-if="can('sales_contracts_delete')" :title="$t('Del')">
              <a-button type="text" size="small" danger @click="crud.remove(record, { label: record.contract_number })">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-tooltip>
          </a-space>
        </template>
        <template v-else-if="column.key === 'value'">{{ money(record.value) }}</template>
        <template v-else-if="column.key === 'status'">
          <a-tag :color="STATUS_COLORS[record.status]">{{ statusLabel(record.status) }}</a-tag>
        </template>
        <template v-else-if="column.key === 'billing'">
          <span v-if="record.billing_enabled"><SyncOutlined style="color: #6d28d9" /> {{ billingLabel(record) }}</span>
          <span v-else style="opacity: 0.45">One-off</span>
        </template>
        <template v-else-if="column.key === 'next_invoice_date'">
          <a-tag v-if="record.next_invoice_date && record.next_invoice_date <= today" color="orange">{{ date(record.next_invoice_date) }} · due</a-tag>
          <span v-else-if="record.next_invoice_date">{{ date(record.next_invoice_date) }}</span>
          <span v-else style="opacity: 0.45">—</span>
        </template>
        <template v-else-if="column.key === 'start_date'">{{ record.start_date ? date(record.start_date) : '—' }}</template>
        <template v-else-if="column.key === 'end_date'">{{ record.end_date ? date(record.end_date) : 'Open-ended' }}</template>
      </template>
    </DataTable>
  </div>
</template>

<script setup>
/**
 * Sales > Sales Contracts: customer contracts with recurring invoices
 * (API: sales_contracts, SalesContractController). Employee contracts are
 * HRM > Employee Contracts. Company-scoped; actions follow the
 * sales_contracts_* permissions.
 */
import { ref, computed } from 'vue';
import dayjs from 'dayjs';
import { PlusOutlined, EyeOutlined, EditOutlined, DeleteOutlined, SyncOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import { sortByLabel } from '../../lib/filterOptions';
import { STATUS_COLORS, statusLabel, billingLabel, STATUS_OPTIONS } from './salesContractVocab';

const auth = useAuthStore();
const can = p => auth.user?.type === 'SuperAdmin' || auth.can(p);
const { money, date } = useFormat();
const today = dayjs().format('YYYY-MM-DD');

const status = ref(undefined);
const clientId = ref(undefined);
const billing = ref(undefined);
const BILLING_OPTIONS = [
  { value: 'recurring', label: 'Recurring invoices' },
  { value: 'oneoff', label: 'One-off' },
];

const crud = useCrudTable('sales_contracts', {
  rowsKey: 'contracts',
  sortField: 'id',
  sortType: 'desc',
  params: () => ({
    ...(status.value ? { status: status.value } : {}),
    ...(clientId.value ? { client_id: clientId.value } : {}),
    ...(billing.value ? { billing: billing.value } : {}),
  }),
});
crud.fetchRows();

const stats = computed(() => crud.payload.value?.stats || {});
const tiles = computed(() => [
  { label: 'Active', value: stats.value.active || 0, style: { color: '#16a34a' } },
  { label: 'Recurring', value: stats.value.recurring || 0, style: { color: '#6d28d9' } },
  { label: 'Invoices due', value: stats.value.due || 0, style: { color: '#f97316' } },
  { label: 'Ending in 30 days', value: stats.value.expiring || 0, style: { color: '#ef4444' } },
]);
const statusOptions = STATUS_OPTIONS;
const clientOptions = computed(() => sortByLabel((crud.payload.value?.clients || []).map(c => ({ value: c.id, label: c.name }))));

const columns = computed(() => [
  { title: 'Action', key: 'actions', width: 110, align: 'center' },
  { title: '#', dataIndex: 'contract_number', key: 'contract_number', sorter: true },
  { title: 'Subject', dataIndex: 'subject', key: 'subject', sorter: true },
  { title: 'Customer', dataIndex: 'client_name', key: 'client_name' },
  { title: 'Value', key: 'value', dataIndex: 'value', align: 'right', sorter: true, exportValue: r => money(r.value) },
  { title: 'Billing', key: 'billing', exportValue: r => (r.billing_enabled ? billingLabel(r) : 'One-off') },
  { title: 'Next invoice', key: 'next_invoice_date', dataIndex: 'next_invoice_date', sorter: true },
  { title: 'Start', key: 'start_date', dataIndex: 'start_date', sorter: true },
  { title: 'End', key: 'end_date', dataIndex: 'end_date', sorter: true },
  { title: 'Status', key: 'status', dataIndex: 'status', exportValue: r => statusLabel(r.status) },
]);
</script>

<style scoped>
.sc-filters {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}
</style>
