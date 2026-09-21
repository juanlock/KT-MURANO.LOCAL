<template>
  <div class="page">
    <PageHeader :title="$t('ListTransfers')" :breadcrumb="[$t('StockTransfers'), $t('ListTransfers')]">
      <template #actions>
        <a-button v-if="auth.can('transfer_add')" type="primary" @click="$router.push('/transfers/create')">
          <template #icon><PlusOutlined /></template>
          {{ $t('CreateTransfer') }}
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <a-row :gutter="[16, 8]">
        <a-col :xs="12" :md="8">
          <div class="filter-label">{{ $t('Status') }}</div>
          <a-select
            v-model:value="filters.statut" style="width: 100%" allow-clear
            :placeholder="$t('Choose_Status')" :options="statusOptions" @change="crud.reload()"
          />
        </a-col>
        <a-col :xs="12" :md="8">
          <div class="filter-label">{{ $t('FromWarehouse') }}</div>
          <a-select
            v-model:value="filters.from_warehouse_id" style="width: 100%" allow-clear show-search
            option-filter-prop="label" :placeholder="$t('Choose_Warehouse')" :options="warehouseOptions"
            @change="crud.reload()"
          />
        </a-col>
        <a-col :xs="12" :md="8">
          <div class="filter-label">{{ $t('ToWarehouse') }}</div>
          <a-select
            v-model:value="filters.to_warehouse_id" style="width: 100%" allow-clear show-search
            option-filter-prop="label" :placeholder="$t('Choose_Warehouse')" :options="warehouseOptions"
            @change="crud.reload()"
          />
        </a-col>
      </a-row>
    </a-card>

    <DataTable :crud="crud" :columns="columns" :selectable="auth.can('transfer_delete')">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'date'">{{ dateTime(record.date) }}</template>
        <template v-else-if="column.key === 'Ref'">
          <a @click="$router.push(`/transfers/${record.id}`)">{{ record.Ref }}</a>
        </template>
        <template v-else-if="column.key === 'GrandTotal'">{{ money(record.GrandTotal) }}</template>
        <template v-else-if="column.key === 'statut'">
          <a-tag :color="transferStatusColor(record.statut)">{{ $t(transferStatusKey(record.statut)) }}</a-tag>
        </template>
        <template v-else-if="column.key === 'approval_status'">
          <a-tag :color="approvalColor(record.approval_status)">{{ $t(approvalKey(record.approval_status)) }}</a-tag>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space>
            <a-tooltip :title="$t('DownloadPdf')">
              <a-button type="text" size="small" @click="downloadPdf(record)">
                <template #icon><FilePdfOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="$t('View')">
              <a-button type="text" size="small" @click="$router.push(`/transfers/${record.id}`)">
                <template #icon><EyeOutlined style="color: #1677ff" /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip v-if="record.approval_status === 'pending' && auth.can('transfer_edit')" :title="$t('Approve')">
              <a-button type="text" size="small" @click="approve(record)">
                <template #icon><CheckOutlined style="color: #52c41a" /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip v-if="auth.can('transfer_edit')" :title="$t('Edit')">
              <a-button type="text" size="small" @click="$router.push(`/transfers/${record.id}/edit`)">
                <template #icon><EditOutlined style="color: #52c41a" /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip v-if="auth.can('transfer_delete')" :title="$t('Del')">
              <a-button type="text" size="small" danger @click="crud.remove(record, { label: record.Ref })">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-tooltip>
          </a-space>
        </template>
      </template>
    </DataTable>
  </div>
</template>

<script setup>
/**
 * GET transfers → {transfers, warehouses, totalRows}; filter params statut,
 * from_warehouse_id, to_warehouse_id (+ always-sent empty Ref). Approve =
 * POST transfers/{id}/approve, shown only while approval_status is pending
 * (gated transfer_edit like legacy). Bulk delete standard nested. Statut:
 * completed → `complete`, sent, pending. Approval: null/approved → Approved,
 * pending → Pending_Approval, rejected → Rejected.
 */
import { ref, computed, createVNode } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  PlusOutlined, EyeOutlined, EditOutlined, DeleteOutlined, FilePdfOutlined,
  CheckOutlined, ExclamationCircleOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import http from '../../lib/http';

const { t } = useI18n();
const { money, dateTime } = useFormat();
const auth = useAuthStore();

const filters = ref({ statut: undefined, from_warehouse_id: undefined, to_warehouse_id: undefined });

const crud = useCrudTable('transfers', {
  rowsKey: 'transfers',
  params: () => ({
    Ref: '',
    statut: filters.value.statut || '',
    from_warehouse_id: filters.value.from_warehouse_id || '',
    to_warehouse_id: filters.value.to_warehouse_id || '',
  }),
});
crud.fetchRows();

const warehouseOptions = computed(() =>
  (crud.payload.value?.warehouses || []).map(w => ({ value: w.id, label: w.name }))
);
const statusOptions = computed(() => [
  { value: 'completed', label: t('complete') },
  { value: 'sent', label: t('Sent') },
  { value: 'pending', label: t('Pending') },
]);

function transferStatusKey(s) {
  return s === 'completed' ? 'complete' : s === 'sent' ? 'Sent' : 'Pending';
}
function transferStatusColor(s) {
  return s === 'completed' ? 'success' : s === 'sent' ? 'warning' : 'error';
}
function approvalKey(s) {
  if (!s || s === 'approved') return 'Approved';
  return s === 'pending' ? 'Pending_Approval' : 'Rejected';
}
function approvalColor(s) {
  if (!s || s === 'approved') return 'success';
  return s === 'pending' ? 'warning' : 'error';
}

const columns = computed(() => [
  { title: t('date'), dataIndex: 'date', key: 'date', sorter: true, exportValue: r => dateTime(r.date) },
  { title: t('Reference'), dataIndex: 'Ref', key: 'Ref', sorter: true },
  { title: t('FromWarehouse'), dataIndex: 'from_warehouse', key: 'from_warehouse' },
  { title: t('ToWarehouse'), dataIndex: 'to_warehouse', key: 'to_warehouse' },
  { title: t('Items'), dataIndex: 'items', key: 'items', align: 'right' },
  { title: t('Total'), dataIndex: 'GrandTotal', key: 'GrandTotal', sorter: true, align: 'right', exportValue: r => money(r.GrandTotal) },
  { title: t('Status'), dataIndex: 'statut', key: 'statut', sorter: true, exportValue: r => r.statut },
  { title: t('Approval'), dataIndex: 'approval_status', key: 'approval_status', exportValue: r => r.approval_status || 'approved' },
  { title: t('Action'), key: 'actions', width: 190, align: 'center' },
]);

function downloadPdf(record) {
  http.download(`transfer_pdf/${record.id}`, `Transfer_${record.Ref}.pdf`)
    .catch(() => message.error(t('InvalidData')));
}

function approve(record) {
  Modal.confirm({
    title: t('Approve'),
    icon: createVNode(ExclamationCircleOutlined),
    content: record.Ref,
    okText: t('Approve'),
    async onOk() {
      try {
        await http.post(`transfers/${record.id}/approve`);
        message.success(t('Success'));
        crud.fetchRows();
      } catch (e) {
        message.error(e?.data?.message || t('InvalidData'));
      }
    },
  });
}
</script>

<style scoped>
.filter-label {
  margin-bottom: 4px;
  color: rgba(0, 0, 0, 0.55);
  font-size: 13px;
}
</style>
