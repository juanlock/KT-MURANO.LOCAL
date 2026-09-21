<template>
  <div class="page">
    <PageHeader :title="$t('Product_Reviews')" :breadcrumb="[$t('Store'), $t('Product_Reviews')]" />

    <a-card size="small" :body-style="{ padding: 0 }">
      <div class="toolbar">
        <a-input-search v-model:value="search" :placeholder="$t('Search')" allow-clear style="max-width: 220px" @search="fetch" @change="fetch" />
        <a-select v-model:value="statusFilter" style="width: 170px" @change="fetch">
          <a-select-option value="">{{ $t('All') }}</a-select-option>
          <a-select-option value="pending">{{ $t('Pending') }}{{ counts ? ` (${counts.pending})` : '' }}</a-select-option>
          <a-select-option value="approved">{{ $t('Approved') }}{{ counts ? ` (${counts.approved})` : '' }}</a-select-option>
          <a-select-option value="rejected">{{ $t('Rejected') }}{{ counts ? ` (${counts.rejected})` : '' }}</a-select-option>
        </a-select>
        <a-select v-model:value="ratingFilter" style="width: 140px" @change="fetch">
          <a-select-option value="">{{ $t('All') }}</a-select-option>
          <a-select-option :value="5">★★★★★</a-select-option>
          <a-select-option :value="4">★★★★</a-select-option>
          <a-select-option :value="3">★★★</a-select-option>
          <a-select-option :value="2">★★</a-select-option>
          <a-select-option :value="1">★</a-select-option>
        </a-select>
      </div>
      <a-table
        :columns="columns" :data-source="rows" :loading="isLoading"
        :pagination="{ pageSize: 10, showSizeChanger: true }" size="middle" row-key="id"
        :scroll="{ x: 'max-content' }"
        :locale="{ emptyText: $t('No_Reviews') }"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'product'">
            <strong>{{ record.product_name || ('#' + record.product_id) }}</strong>
          </template>
          <template v-else-if="column.key === 'rating'">
            <a-rate :value="record.rating" disabled style="font-size: 14px" />
          </template>
          <template v-else-if="column.key === 'comment'">
            <a-tooltip :title="record.comment">
              <span class="truncate">{{ record.comment || '—' }}</span>
            </a-tooltip>
          </template>
          <template v-else-if="column.key === 'status'">
            <a-tag :color="statusColor(record.status)">{{ record.status }}</a-tag>
          </template>
          <template v-else-if="column.key === 'created_at'">
            {{ formatDate(record.created_at) }}
          </template>
          <template v-else-if="column.key === 'actions'">
            <a-space>
              <a-button v-if="record.status !== 'approved'" size="small" @click="setStatus(record, 'approved')">
                {{ $t('Approved') }}
              </a-button>
              <a-button v-if="record.status !== 'rejected'" size="small" @click="setStatus(record, 'rejected')">
                {{ $t('Rejected') }}
              </a-button>
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
 * Product reviews — GET store/reviews?search&status&rating → {data, counts};
 * approve/reject POST store/reviews/{id}/status {status}; DELETE .../{id}.
 */
import { ref, computed, onMounted } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { DeleteOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const { t } = useI18n();

const isLoading = ref(true);
const search = ref('');
const statusFilter = ref('');
const ratingFilter = ref('');
const rows = ref([]);
const counts = ref(null);

const columns = computed(() => [
  { title: t('ProductName'), key: 'product' },
  { title: t('Customer'), dataIndex: 'reviewer_name', key: 'reviewer_name' },
  { title: t('Rating'), key: 'rating', width: 150 },
  { title: t('Comment'), key: 'comment' },
  { title: t('Status'), key: 'status', width: 100 },
  { title: t('date'), key: 'created_at' },
  { title: t('Actions'), key: 'actions', width: 210 },
]);

function statusColor(s) {
  return { pending: 'warning', approved: 'success', rejected: 'error' }[s] || 'default';
}
function formatDate(d) { return d ? new Date(d).toLocaleDateString() : ''; }

async function fetch() {
  try {
    const r = await http.get('store/reviews', {
      search: search.value, status: statusFilter.value, rating: ratingFilter.value,
    });
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
    await http.post(`store/reviews/${r.id}/status`, { status });
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
        await http.delete(`store/reviews/${r.id}`);
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
  max-width: 280px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  vertical-align: middle;
}
</style>
