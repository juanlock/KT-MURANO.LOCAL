<template>
  <div class="page">
    <PageHeader :title="$t('Service_Jobs')" :breadcrumb="[$t('Service_Maintenance'), $t('Service_Jobs')]">
      <template #extra>
        <a-button type="primary" @click="$router.push('/service/jobs/create')">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <a-row :gutter="[12, 12]" align="bottom">
        <a-col :xs="24" :md="6">
          <div class="filter-label">{{ $t('Status') }}</div>
          <a-select v-model:value="filters.status" :options="statusFilterOptions" style="width: 100%" @change="applyFilters" />
        </a-col>
        <a-col :xs="24" :md="6">
          <div class="filter-label">{{ $t('Payment') }}</div>
          <a-select v-model:value="filters.payment_status" :options="paymentFilterOptions" style="width: 100%" @change="applyFilters" />
        </a-col>
        <a-col :xs="12" :md="5">
          <div class="filter-label">{{ $t('From') }}</div>
          <a-input v-model:value="filters.from" type="date" @change="applyFilters" />
        </a-col>
        <a-col :xs="12" :md="5">
          <div class="filter-label">{{ $t('To') }}</div>
          <a-input v-model:value="filters.to" type="date" @change="applyFilters" />
        </a-col>
        <a-col :xs="24" :md="2">
          <a-button v-if="hasActiveFilters" @click="clearFilters">
            <template #icon><ClearOutlined /></template>
          </a-button>
        </a-col>
      </a-row>
    </a-card>

    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'device'">
          <span v-if="record.device_brand || record.device_model">
            {{ record.device_brand }} {{ record.device_model }}
          </span>
          <span v-else style="color: #999">{{ record.service_item || '-' }}</span>
        </template>
        <template v-else-if="column.key === 'status'">
          <a-tag :color="statusColor(record.status)">{{ statusLabel(record.status) }}</a-tag>
        </template>
        <template v-else-if="column.key === 'payment_status'">
          <a-tag :color="paymentColor(record.payment_status)">{{ $t(record.payment_status || 'unpaid') }}</a-tag>
        </template>
        <template v-else-if="column.key === 'total_amount'">
          {{ formatNumber(record.total_amount) }}
        </template>
        <template v-else-if="column.key === 'balance_due'">
          <span :style="{ color: record.balance_due > 0 ? '#cf1322' : '#3f8600' }">
            {{ formatNumber(record.balance_due) }}
          </span>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space>
            <a-tooltip :title="$t('Details')">
              <a-button size="small" @click="$router.push(`/service/jobs/details/${record.id}`)">
                <template #icon><EyeOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="$t('Edit')">
              <a-button size="small" @click="$router.push(`/service/jobs/edit/${record.id}`)">
                <template #icon><EditOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="$t('Del')">
              <a-button size="small" danger @click="crud.remove(record, { label: record.Ref })">
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
 * Service jobs list — GET service_jobs (page/limit/SortField/SortType/search
 * plus optional status/payment_status/from/to) → {jobs, totalRows}. Sidebar
 * shortcuts land here with query params (?status=quoted, ?payment_status=
 * unpaid) which are applied as filters, exactly like legacy.
 */
import { ref, computed, watch, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import {
  PlusOutlined, EyeOutlined, EditOutlined, DeleteOutlined, ClearOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();

const filters = ref({ status: '', payment_status: '', from: '', to: '' });

const crud = useCrudTable('service_jobs', {
  rowsKey: 'jobs',
  sortField: 'Ref',
  sortType: 'desc',
  params: () => {
    const p = {};
    if (filters.value.status) p.status = filters.value.status;
    if (filters.value.payment_status) p.payment_status = filters.value.payment_status;
    if (filters.value.from) p.from = filters.value.from;
    if (filters.value.to) p.to = filters.value.to;
    return p;
  },
});

const hasActiveFilters = computed(() =>
  !!(filters.value.status || filters.value.payment_status || filters.value.from || filters.value.to));

const statusFilterOptions = [
  { value: '', label: 'All' },
  { value: 'pending', label: 'Pending' },
  { value: 'intake', label: 'Intake' },
  { value: 'diagnostic', label: 'Diagnostic' },
  { value: 'quoted', label: 'Quoted' },
  { value: 'approved', label: 'Approved' },
  { value: 'in_progress', label: 'In Progress' },
  { value: 'ready', label: 'Ready for Pickup' },
  { value: 'delivered', label: 'Delivered' },
  { value: 'declined', label: 'Declined' },
  { value: 'completed', label: 'Completed' },
  { value: 'cancelled', label: 'Cancelled' },
];
const paymentFilterOptions = [
  { value: '', label: 'All' },
  { value: 'unpaid', label: 'Unpaid' },
  { value: 'partial', label: 'Partial' },
  { value: 'paid', label: 'Paid' },
];

const columns = computed(() => [
  { title: t('Reference'), dataIndex: 'Ref', key: 'Ref', sorter: true },
  { title: t('Customer'), dataIndex: 'client_name', key: 'client_name', sorter: true },
  { title: t('Device'), key: 'device' },
  { title: t('Technician'), dataIndex: 'technician_name', key: 'technician_name', sorter: true },
  { title: t('Scheduled_Date'), dataIndex: 'scheduled_date', key: 'scheduled_date', sorter: true },
  { title: t('Status'), key: 'status' },
  { title: t('Payment'), key: 'payment_status' },
  { title: t('Total'), key: 'total_amount', align: 'right', sorter: true },
  { title: t('Balance_Due'), key: 'balance_due', align: 'right' },
  { title: t('Actions'), key: 'actions', width: 130 },
]);

function statusColor(s) {
  const map = {
    delivered: 'success', ready: 'success', completed: 'success',
    approved: 'processing', in_progress: 'processing',
    quoted: 'cyan', diagnostic: 'cyan', intake: 'cyan',
    pending: 'warning',
    declined: 'error', cancelled: 'error',
  };
  return map[s] || 'default';
}
function statusLabel(s) {
  if (!s) return '-';
  const map = {
    pending: t('Pending'),
    intake: 'Intake',
    diagnostic: 'Diagnostic',
    quoted: 'Quoted',
    approved: 'Approved',
    in_progress: t('In_Progress'),
    ready: 'Ready for Pickup',
    delivered: 'Delivered',
    declined: 'Declined',
    completed: t('complete'),
    cancelled: t('Cancelled'),
  };
  return map[s] || s;
}
function paymentColor(s) {
  if (s === 'paid') return 'success';
  if (s === 'partial') return 'warning';
  return 'error';
}
function formatNumber(n) {
  return (Number(n) || 0).toFixed(2);
}

function applyFilters() {
  crud.reload();
}
function applyQueryFilters() {
  const q = route.query || {};
  filters.value.status = q.status || '';
  filters.value.payment_status = q.payment_status || '';
  filters.value.from = q.from || '';
  filters.value.to = q.to || '';
  applyFilters();
}
function clearFilters() {
  filters.value = { status: '', payment_status: '', from: '', to: '' };
  if (Object.keys(route.query).length > 0) {
    router.replace({ path: route.path });
  } else {
    applyFilters();
  }
}

watch(() => route.query, () => {
  if (route.path === '/service/jobs') applyQueryFilters();
});

onMounted(applyQueryFilters);
</script>

<style scoped>
.filter-label {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.55);
  margin-bottom: 4px;
}
</style>
