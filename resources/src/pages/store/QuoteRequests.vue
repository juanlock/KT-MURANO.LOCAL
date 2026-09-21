<template>
  <div class="page">
    <PageHeader :title="$t('Quote_Requests')" :breadcrumb="[$t('Store'), $t('Quote_Requests')]" />

    <a-card size="small" :body-style="{ padding: 0 }">
      <div class="toolbar">
        <a-input-search v-model:value="search" :placeholder="$t('Search')" allow-clear style="max-width: 220px" @search="fetch" @change="fetch" />
        <a-select v-model:value="statusFilter" style="width: 190px" @change="fetch">
          <a-select-option value="">{{ $t('All') }}</a-select-option>
          <a-select-option value="new">{{ $t('New') }}{{ counts ? ` (${counts.new})` : '' }}</a-select-option>
          <a-select-option value="handled">{{ $t('Handled') }}{{ counts ? ` (${counts.handled})` : '' }}</a-select-option>
          <a-select-option value="closed">{{ $t('Closed') }}{{ counts ? ` (${counts.closed})` : '' }}</a-select-option>
        </a-select>
      </div>
      <a-table
        :columns="columns" :data-source="rows" :loading="isLoading"
        :pagination="{ pageSize: 10, showSizeChanger: true }" size="middle" row-key="id"
        :scroll="{ x: 'max-content' }"
        :locale="{ emptyText: $t('No_Quote_Requests') }"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'product'">
            <strong>{{ record.product_name || '—' }}</strong>
          </template>
          <template v-else-if="column.key === 'email'">
            <a :href="'mailto:' + record.email">{{ record.email }}</a>
          </template>
          <template v-else-if="column.key === 'quantity'">
            {{ record.quantity != null ? record.quantity : '—' }}
          </template>
          <template v-else-if="column.key === 'message'">
            <a-tooltip :title="record.message">
              <span class="truncate">{{ record.message || '—' }}</span>
            </a-tooltip>
          </template>
          <template v-else-if="column.key === 'status'">
            <a-tag :color="statusColor(record.status)">{{ statusLabel(record.status) }}</a-tag>
          </template>
          <template v-else-if="column.key === 'created_at'">
            {{ formatDate(record.created_at) }}
          </template>
          <template v-else-if="column.key === 'actions'">
            <a-space>
              <a-dropdown>
                <a-button size="small">
                  {{ $t('Status') }}
                  <DownOutlined />
                </a-button>
                <template #overlay>
                  <a-menu @click="({ key }) => setStatus(record, key)">
                    <a-menu-item key="new">{{ $t('New') }}</a-menu-item>
                    <a-menu-item key="handled">{{ $t('Handled') }}</a-menu-item>
                    <a-menu-item key="closed">{{ $t('Closed') }}</a-menu-item>
                  </a-menu>
                </template>
              </a-dropdown>
              <a-button size="small" danger @click="confirmDelete(record)">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-space>
          </template>
        </template>
      </a-table>
    </a-card>
  </div>
</template>

<script setup>
/**
 * Quote requests — GET store/quote-requests?search&status → {data, counts};
 * status POST store/quote-requests/{id}/status {status}; DELETE .../{id}.
 */
import { ref, computed, onMounted } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { DeleteOutlined, DownOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const { t } = useI18n();

const isLoading = ref(true);
const search = ref('');
const statusFilter = ref('');
const rows = ref([]);
const counts = ref(null);

const columns = computed(() => [
  { title: t('ProductName'), key: 'product' },
  { title: t('Customer'), dataIndex: 'name', key: 'name' },
  { title: t('Email'), key: 'email' },
  { title: t('Phone'), dataIndex: 'phone', key: 'phone' },
  { title: t('Quantity'), key: 'quantity', align: 'center' },
  { title: t('Message'), key: 'message' },
  { title: t('Status'), key: 'status', width: 100 },
  { title: t('date'), key: 'created_at' },
  { title: t('Actions'), key: 'actions', width: 150 },
]);

function statusColor(s) {
  return { new: 'processing', handled: 'warning', closed: 'default' }[s] || 'default';
}
function statusLabel(s) {
  return { new: t('New'), handled: t('Handled'), closed: t('Closed') }[s] || s;
}
function formatDate(d) { return d ? new Date(d).toLocaleString() : ''; }

async function fetch() {
  try {
    const r = await http.get('store/quote-requests', { search: search.value, status: statusFilter.value });
    rows.value = r.data || [];
    counts.value = r.counts || null;
  } catch (e) {
    message.error(t('Failed'));
  } finally {
    isLoading.value = false;
  }
}
async function setStatus(r, status) {
  try {
    await http.post(`store/quote-requests/${r.id}/status`, { status });
    message.success(t('Status_updated'));
    fetch();
  } catch (e) {
    message.error(t('Failed'));
  }
}
function confirmDelete(r) {
  Modal.confirm({
    title: t('AreYouSure'),
    okText: t('Yes'),
    okType: 'danger',
    cancelText: t('No'),
    async onOk() {
      try {
        await http.delete(`store/quote-requests/${r.id}`);
        message.success(t('Deleted_in_successfully'));
        fetch();
      } catch (e) {
        message.error(t('Failed'));
      }
    },
  });
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
  max-width: 240px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  vertical-align: middle;
}
</style>
