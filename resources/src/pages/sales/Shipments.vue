<template>
  <div class="page">
    <PageHeader :title="$t('Shipments')" :breadcrumb="[$t('Sales'), $t('Shipments')]" />

    <!-- Per-status summary (global counts, not just the current page) -->
    <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
      <a-col v-for="card in statusCards" :key="card.label" :xs="12" :sm="8" :xl="4">
        <a-card :bordered="false" class="kpi-card">
          <div class="kpi-inner">
            <div class="kpi-icon" :style="{ background: card.tint, color: card.color }">
              <component :is="card.icon" />
            </div>
            <div class="kpi-text">
              <div class="kpi-label">{{ card.label }}</div>
              <div class="kpi-value">{{ card.value }}</div>
            </div>
          </div>
        </a-card>
      </a-col>
    </a-row>

    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'date'">{{ date(record.date) }}</template>
        <template v-else-if="column.key === 'status'">
          <a-tag :color="statusColor(record.status)">{{ $t(statusLabelKey(record.status)) }}</a-tag>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space>
            <a-tooltip v-if="auth.can('shipment')" :title="$t('Edit')">
              <a-button type="text" size="small" @click="openEdit(record)">
                <template #icon><EditOutlined style="color: #52c41a" /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip v-if="auth.can('shipment')" :title="$t('Del')">
              <a-button type="text" size="small" danger @click="crud.remove(record, { label: record.shipment_ref })">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-tooltip>
          </a-space>
        </template>
      </template>
    </DataTable>

    <!-- Edit shipment -->
    <a-modal
      v-model:open="modalOpen"
      :title="$t('Edit')"
      :confirm-loading="submitting"
      :ok-text="$t('submit')"
      :cancel-text="$t('Delete_cancelButtonText')"
      @ok="submit"
    >
      <a-form ref="formRef" :model="form" :rules="rules" layout="vertical" style="margin-top: 8px">
        <a-form-item :label="$t('Status') + ' *'" name="status">
          <a-select
            v-model:value="form.status"
            :placeholder="$t('Choose_Status')"
            :options="[
              { label: $t('Ordered'), value: 'ordered' },
              { label: $t('Packed'), value: 'packed' },
              { label: $t('Shipped'), value: 'shipped' },
              { label: $t('Delivered'), value: 'delivered' },
              { label: $t('Cancelled'), value: 'cancelled' },
            ]"
          />
        </a-form-item>
        <a-form-item :label="$t('delivered_to')">
          <a-input v-model:value="form.delivered_to" :placeholder="$t('delivered_to')" />
        </a-form-item>
        <a-form-item :label="$t('Adress')">
          <a-textarea v-model:value="form.shipping_address" :rows="4" :placeholder="$t('Enter_Address')" />
        </a-form-item>
        <a-form-item :label="$t('Please_provide_any_details')">
          <a-textarea v-model:value="form.shipping_details" :rows="4" :placeholder="$t('Please_provide_any_details')" />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Shipments — GET shipments → {shipments, totalRows}. Edit modal only (no
 * create; shipments are born from sales): PUT shipments/{id} {sale_id,
 * shipping_address, delivered_to, shipping_details, status}; status vocab
 * ordered/packed/shipped/delivered/cancelled; DELETE shipments/{id}. All
 * actions gated by the single `shipment` permission (legacy).
 */
import { ref, computed } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  EditOutlined, DeleteOutlined, CarOutlined, ClockCircleOutlined,
  InboxOutlined, RocketOutlined, CheckCircleOutlined, CloseCircleOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import http from '../../lib/http';

const { t } = useI18n();
const { date } = useFormat();
const auth = useAuthStore();

const crud = useCrudTable('shipments', { rowsKey: 'shipments' });
crud.fetchRows();

/* ------------------------------------------------------- status summary */
const statusCounts = computed(() => crud.payload.value?.status_counts || {});

const statusCards = computed(() => {
  const n = s => Number(statusCounts.value[s]) || 0;
  const total = Object.values(statusCounts.value).reduce((sum, v) => sum + (Number(v) || 0), 0);
  return [
    { label: t('Total'), value: total, color: '#6d28d9', tint: 'rgba(109, 40, 217, 0.12)', icon: CarOutlined },
    { label: t('Ordered'), value: n('ordered'), color: '#faad14', tint: 'rgba(250, 173, 20, 0.14)', icon: ClockCircleOutlined },
    { label: t('Packed'), value: n('packed'), color: '#1677ff', tint: 'rgba(22, 119, 255, 0.12)', icon: InboxOutlined },
    { label: t('Shipped'), value: n('shipped'), color: '#722ed1', tint: 'rgba(114, 46, 209, 0.12)', icon: RocketOutlined },
    { label: t('Delivered'), value: n('delivered'), color: '#52c41a', tint: 'rgba(82, 196, 26, 0.12)', icon: CheckCircleOutlined },
    { label: t('Cancelled'), value: n('cancelled'), color: '#ff4d4f', tint: 'rgba(255, 77, 79, 0.12)', icon: CloseCircleOutlined },
  ];
});

const columns = computed(() => [
  { title: t('date'), dataIndex: 'date', key: 'date', sorter: true, exportValue: r => date(r.date) },
  { title: t('shipment_ref'), dataIndex: 'shipment_ref', key: 'shipment_ref', sorter: true },
  { title: t('Reference'), dataIndex: 'sale_ref', key: 'sale_ref', sorter: true },
  { title: t('Customer'), dataIndex: 'customer_name', key: 'customer_name', sorter: true },
  { title: t('warehouse'), dataIndex: 'warehouse_name', key: 'warehouse_name', sorter: true },
  { title: t('Status'), key: 'status', dataIndex: 'status', sorter: true, exportValue: r => r.status },
  { title: t('Action'), key: 'actions', width: 110, align: 'center' },
]);

// Legacy badge variants: ordered=warning, packed=info, shipped=secondary,
// delivered=success, else danger.
function statusColor(s) {
  return { ordered: 'warning', packed: 'processing', shipped: 'default', delivered: 'success' }[s] || 'error';
}
function statusLabelKey(s) {
  return { ordered: 'Ordered', packed: 'Packed', shipped: 'Shipped', delivered: 'Delivered' }[s] || 'Cancelled';
}

/* ------------------------------------------------------------------- edit */
const modalOpen = ref(false);
const submitting = ref(false);
const formRef = ref();
const form = ref({});

const rules = computed(() => ({
  status: [{ required: true, message: t('Field_is_required') }],
}));

function openEdit(record) {
  form.value = {
    id: record.id,
    sale_id: record.sale_id,
    status: record.status,
    delivered_to: record.delivered_to || '',
    shipping_address: record.shipping_address || '',
    shipping_details: record.shipping_details || '',
  };
  modalOpen.value = true;
}

async function submit() {
  try {
    await formRef.value.validate();
  } catch (e) {
    return;
  }
  submitting.value = true;
  try {
    await http.put(`shipments/${form.value.id}`, {
      sale_id: form.value.sale_id,
      shipping_address: form.value.shipping_address,
      delivered_to: form.value.delivered_to,
      shipping_details: form.value.shipping_details,
      status: form.value.status,
    });
    message.success(t('Updated_in_successfully'));
    modalOpen.value = false;
    crud.fetchRows();
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    submitting.value = false;
  }
}
</script>

<style scoped>
/* KPI tiles — same design as the supplier/customer ledgers. */
.kpi-card {
  border: 1px solid #f0f0f0;
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.04);
  height: 100%;
}
.kpi-inner {
  display: flex;
  align-items: center;
  gap: 12px;
}
.kpi-icon {
  width: 44px;
  height: 44px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  flex: 0 0 auto;
}
.kpi-text {
  min-width: 0;
  flex: 1 1 auto;
}
.kpi-label {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.45);
  line-height: 1.3;
  margin-bottom: 3px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.kpi-value {
  font-size: 18px;
  font-weight: 700;
  line-height: 1.2;
}
</style>
