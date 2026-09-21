<template>
  <div class="page">
    <PageHeader :title="$t('Pending_Customers')" :breadcrumb="[$t('Store'), $t('Pending_Customers')]">
      <template #extra>
        <a-button v-if="customers.length" type="primary" :loading="saving" @click="approveAll">
          {{ $t('Approve_All') }}
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" :body-style="{ padding: 0 }">
      <div class="toolbar">
        <a-input-search
          v-model:value="search" :placeholder="$t('Search_by_name_or_email')"
          allow-clear style="max-width: 280px" @search="() => fetchCustomers(1)" @change="debounceFetch"
        />
      </div>
      <a-table
        :columns="columns" :data-source="customers" :loading="isLoading"
        :pagination="pagination" size="middle" row-key="id"
        :scroll="{ x: 'max-content' }"
        :locale="{ emptyText: $t('No_Pending_Customers') }"
        @change="onTableChange"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'customer'">
            <strong>{{ (record.client && record.client.name) || record.username }}</strong>
          </template>
          <template v-else-if="column.key === 'verified'">
            <a-tag :color="record.email_verified_at ? 'success' : 'warning'">
              {{ record.email_verified_at ? $t('Verified') : $t('Not_Verified') }}
            </a-tag>
          </template>
          <template v-else-if="column.key === 'phone'">
            {{ (record.client && record.client.phone) || '—' }}
          </template>
          <template v-else-if="column.key === 'invite_code'">
            <a-tag v-if="record.invite_code" style="font-family: ui-monospace, Menlo, monospace">{{ record.invite_code.code }}</a-tag>
            <span v-else style="color: #999">—</span>
          </template>
          <template v-else-if="column.key === 'created_at'">
            {{ formatDate(record.created_at) }}
          </template>
          <template v-else-if="column.key === 'actions'">
            <a-space>
              <a-button size="small" type="primary" :disabled="saving" @click="approve(record)">
                {{ $t('Approve') }}
              </a-button>
              <a-button size="small" danger :disabled="saving" @click="reject(record)">
                {{ $t('Rejected') }}
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
 * Pending customers — GET store/pending-customers?per_page=15&page&search
 * → paginator {data, current_page, last_page}. Approve POST
 * .../{id}/approve; reject POST .../{id}/reject (confirmed); approve-all
 * POST .../approve-all → {approved_count}.
 */
import { ref, computed, onMounted } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const { t } = useI18n();

const isLoading = ref(true);
const saving = ref(false);
const search = ref('');
const customers = ref([]);
const currentPage = ref(1);
const lastPage = ref(1);
let debounceTimer = null;

const columns = computed(() => [
  { title: t('Customer'), key: 'customer' },
  { title: t('Email'), dataIndex: 'email', key: 'email' },
  { title: t('Email_Verified'), key: 'verified', width: 130 },
  { title: t('Phone'), key: 'phone' },
  { title: t('Invite_Code_Used'), key: 'invite_code' },
  { title: t('Registered'), key: 'created_at' },
  { title: t('Actions'), key: 'actions', width: 190 },
]);
const pagination = computed(() => ({
  current: currentPage.value,
  pageSize: 15,
  total: lastPage.value * 15,
  showSizeChanger: false,
}));

function formatDate(d) {
  if (!d) return '';
  const dt = new Date(d);
  return `${dt.toLocaleDateString()} ${dt.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}`;
}
function debounceFetch() {
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(() => fetchCustomers(1), 400);
}
async function fetchCustomers(page) {
  try {
    isLoading.value = customers.value.length === 0;
    const params = { per_page: 15, page: page || 1 };
    if (search.value) params.search = search.value;
    const resp = await http.get('store/pending-customers', params);
    customers.value = resp.data || [];
    currentPage.value = resp.current_page || 1;
    lastPage.value = resp.last_page || 1;
  } catch (e) {
    message.error(t('Failed'));
  } finally {
    isLoading.value = false;
  }
}
function onTableChange(pag) {
  fetchCustomers(pag.current);
}
async function approve(c) {
  saving.value = true;
  try {
    await http.post(`store/pending-customers/${c.id}/approve`);
    message.success(`${c.email} ${t('approved')}`);
    await fetchCustomers(currentPage.value);
  } catch (e) {
    message.error(t('Failed'));
  } finally {
    saving.value = false;
  }
}
function reject(c) {
  Modal.confirm({
    title: t('Confirm_Reject_Customer'),
    content: c.email,
    okText: t('Yes'),
    okType: 'danger',
    cancelText: t('No'),
    async onOk() {
      saving.value = true;
      try {
        await http.post(`store/pending-customers/${c.id}/reject`);
        message.success(`${c.email} ${t('rejected')}`);
        await fetchCustomers(currentPage.value);
      } catch (e) {
        message.error(t('Failed'));
      } finally {
        saving.value = false;
      }
    },
  });
}
function approveAll() {
  Modal.confirm({
    title: t('Confirm_Approve_All_Pending'),
    okText: t('Yes'),
    cancelText: t('No'),
    async onOk() {
      saving.value = true;
      try {
        const resp = await http.post('store/pending-customers/approve-all');
        const count = (resp && resp.approved_count) || 0;
        message.success(`${count} ${t('customers_approved')}`);
        await fetchCustomers(1);
      } catch (e) {
        message.error(t('Failed'));
      } finally {
        saving.value = false;
      }
    },
  });
}

onMounted(() => fetchCustomers(1));
</script>

<style scoped>
.toolbar {
  padding: 16px;
  border-bottom: 1px solid rgba(5, 5, 5, 0.06);
}
</style>
