<template>
  <div class="page">
    <PageHeader :title="$t('Returns_Requests')" :breadcrumb="[$t('Store'), $t('Returns_Requests')]" />

    <a-card size="small" :body-style="{ padding: 0 }">
      <div class="toolbar">
        <a-input-search v-model:value="search" :placeholder="$t('Search')" allow-clear style="max-width: 220px" @search="fetch" @change="fetch" />
        <a-select v-model:value="statusFilter" style="width: 170px" @change="fetch">
          <a-select-option value="">{{ $t('All') }}</a-select-option>
          <a-select-option value="requested">{{ $t('Requested') }}</a-select-option>
          <a-select-option value="approved">{{ $t('Approved') }}</a-select-option>
          <a-select-option value="refunded">{{ $t('Refunded') }}</a-select-option>
          <a-select-option value="rejected">{{ $t('Rejected') }}</a-select-option>
        </a-select>
        <a-select v-model:value="typeFilter" style="width: 170px" @change="fetch">
          <a-select-option value="">{{ $t('All') }}</a-select-option>
          <a-select-option value="return">{{ $t('Return') }}</a-select-option>
          <a-select-option value="cancellation">{{ $t('Cancellation') }}</a-select-option>
        </a-select>
      </div>
      <a-table
        :columns="columns" :data-source="rows" :loading="isLoading"
        :pagination="{ pageSize: 10, showSizeChanger: true }" size="middle" row-key="id"
        :scroll="{ x: 'max-content' }"
        :locale="{ emptyText: $t('No_Return_Requests') }"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'order_ref'">
            <strong>{{ record.order_ref }}</strong>
          </template>
          <template v-else-if="column.key === 'type'">
            {{ record.type === 'cancellation' ? $t('Cancellation') : $t('Return') }}
          </template>
          <template v-else-if="column.key === 'status'">
            <a-tag :color="statusColor(record.status)">{{ record.status }}</a-tag>
          </template>
          <template v-else-if="column.key === 'reason'">
            <a-tooltip :title="record.reason">
              <span class="truncate">{{ record.reason || '—' }}</span>
            </a-tooltip>
          </template>
          <template v-else-if="column.key === 'refund_amount'">
            {{ Number(record.refund_amount).toFixed(2) }}
          </template>
          <template v-else-if="column.key === 'created_at'">
            {{ formatDate(record.created_at) }}
          </template>
          <template v-else-if="column.key === 'actions'">
            <a-space v-if="record.status === 'requested' || record.status === 'approved'">
              <a-button size="small" type="primary" :disabled="busy" @click="openApprove(record)">
                {{ $t('Approve_Refund') }}
              </a-button>
              <a-button size="small" danger :disabled="busy" @click="openReject(record)">
                {{ $t('Rejected') }}
              </a-button>
            </a-space>
            <span v-else style="color: #999">—</span>
          </template>
        </template>
      </a-table>
    </a-card>

    <!-- Approve modal -->
    <a-modal v-model:open="approveOpen" :title="$t('Approve_Refund')" :confirm-loading="busy" @ok="doApprove">
      <p style="color: #999; margin-top: 12px">{{ $t('Approve_Refund_Confirm') }}</p>
      <a-form layout="vertical">
        <a-form-item :label="$t('Refund_Amount')">
          <a-input-number v-model:value="current.refund_amount" :min="0" :step="0.01" style="width: 100%" />
        </a-form-item>
        <a-form-item :label="$t('Admin_Note')">
          <a-textarea v-model:value="adminNote" :rows="2" />
        </a-form-item>
      </a-form>
    </a-modal>

    <!-- Reject modal -->
    <a-modal v-model:open="rejectOpen" :title="$t('Rejected')" :confirm-loading="busy" :ok-button-props="{ danger: true }" @ok="doReject">
      <a-form layout="vertical" style="margin-top: 12px">
        <a-form-item :label="$t('Admin_Note')">
          <a-textarea v-model:value="adminNote" :rows="2" />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Returns/cancellation requests — GET store/returns?search&status&type →
 * {data}; approve POST store/returns/{id}/approve {admin_note,
 * refund_amount}; reject POST store/returns/{id}/reject {admin_note}.
 * Actions only for requested/approved rows (legacy).
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const { t } = useI18n();

const isLoading = ref(true);
const busy = ref(false);
const search = ref('');
const statusFilter = ref('');
const typeFilter = ref('');
const rows = ref([]);
const current = ref({});
const adminNote = ref('');
const approveOpen = ref(false);
const rejectOpen = ref(false);

const columns = computed(() => [
  { title: t('Order'), key: 'order_ref' },
  { title: t('Type'), key: 'type', width: 120 },
  { title: t('Status'), key: 'status', width: 110 },
  { title: t('Reason'), key: 'reason' },
  { title: t('Refund_Amount'), key: 'refund_amount', align: 'right' },
  { title: t('date'), key: 'created_at' },
  { title: t('Actions'), key: 'actions', width: 210 },
]);

function statusColor(s) {
  return { requested: 'warning', approved: 'processing', refunded: 'success', rejected: 'error' }[s] || 'default';
}
function formatDate(d) {
  if (!d) return '';
  const dt = new Date(d);
  return `${dt.toLocaleDateString()} ${dt.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}`;
}

async function fetch() {
  try {
    const r = await http.get('store/returns', {
      search: search.value, status: statusFilter.value, type: typeFilter.value,
    });
    rows.value = r.data || [];
  } catch (e) {
    message.error(t('Failed'));
  } finally {
    isLoading.value = false;
  }
}
function openApprove(r) {
  current.value = { ...r };
  adminNote.value = '';
  approveOpen.value = true;
}
function openReject(r) {
  current.value = { ...r };
  adminNote.value = '';
  rejectOpen.value = true;
}
async function doApprove() {
  busy.value = true;
  try {
    await http.post(`store/returns/${current.value.id}/approve`, {
      admin_note: adminNote.value,
      refund_amount: current.value.refund_amount,
    });
    message.success(t('Refund_Approved'));
    approveOpen.value = false;
    fetch();
  } catch (e) {
    message.error(e?.data?.error || t('Failed'));
  } finally {
    busy.value = false;
  }
}
async function doReject() {
  busy.value = true;
  try {
    await http.post(`store/returns/${current.value.id}/reject`, { admin_note: adminNote.value });
    message.success(t('Request_Rejected'));
    rejectOpen.value = false;
    fetch();
  } catch (e) {
    message.error(t('Failed'));
  } finally {
    busy.value = false;
  }
}

onMounted(fetch);
</script>

<style scoped>
.toolbar {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
  padding: 16px;
  border-bottom: 1px solid rgba(5, 5, 5, 0.06);
}
.truncate {
  display: inline-block;
  max-width: 220px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  vertical-align: middle;
}
</style>
