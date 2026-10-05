<template>
  <div class="page">
    <PageHeader :title="pageTitle" :breadcrumb="[$t('Service_Maintenance'), pageTitle]">
      <template #extra>
        <a-button type="primary" @click="$router.push({ path: '/service/jobs/create', query: filters.kind ? { kind: filters.kind } : {} })">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <a-row :gutter="[12, 12]" align="bottom">
        <!-- Service jobs (work at the customer) vs device / product repairs. -->
        <a-col :xs="24" :md="5">
          <div class="filter-label">Kind</div>
          <a-select v-model:value="filters.kind" :options="kindOptions" style="width: 100%" @change="applyFilters" />
        </a-col>
        <a-col :xs="24" :md="4">
          <div class="filter-label">{{ $t('Status') }}</div>
          <a-select v-model:value="filters.status" :options="statusFilterOptions" style="width: 100%" @change="applyFilters" />
        </a-col>
        <a-col :xs="24" :md="4">
          <div class="filter-label">{{ $t('Payment') }}</div>
          <a-select v-model:value="filters.payment_status" :options="paymentFilterOptions" style="width: 100%" @change="applyFilters" />
        </a-col>
        <!-- Day view: that day's jobs -- all-day tasks first, then by time,
             including multi-day jobs that cover it. -->
        <a-col :xs="24" :md="7">
          <div class="filter-label">Day</div>
          <a-space-compact style="width: 100%">
            <a-button @click="shiftDay(-1)">‹</a-button>
            <a-date-picker v-model:value="filters.day" value-format="YYYY-MM-DD" placeholder="Any day" style="flex: 1" @change="onDayChange" />
            <a-button @click="shiftDay(1)">›</a-button>
            <a-button :type="filters.day === todayStr ? 'primary' : 'default'" @click="setDay(todayStr)">Today</a-button>
          </a-space-compact>
        </a-col>
        <!-- Date range (presets + editable Start date / to), when no single
             day is picked. v-show, not v-if: remounting would re-apply the
             user's default preset over the range. -->
        <a-col v-show="!filters.day" :span="24">
          <div class="filter-label">{{ $t('date') }}</div>
          <PresetRangeFilter v-model:value="range" @change="applyFilters" />
        </a-col>
        <a-col flex="none">
          <ResetFiltersButton :dirty="hasActiveFilters" @reset="clearFilters" />
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
        <template v-else-if="column.key === 'scheduled_date'">
          <template v-if="record.scheduled_date">
            <span>{{ scheduleText(record).day }}</span>
            <a-tag v-if="record.all_day" color="blue" style="margin-left: 6px">All day</a-tag>
            <span v-else class="sched-time">{{ scheduleText(record).time }}</span>
          </template>
          <span v-else style="color: #999">—</span>
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
  PlusOutlined, EyeOutlined, EditOutlined, DeleteOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import PresetRangeFilter from '../../components/PresetRangeFilter.vue';
import { rangeModel } from '../../lib/datePresets';
import DataTable from '../../components/DataTable.vue';
import ResetFiltersButton from '../../components/ResetFiltersButton.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import dayjs from 'dayjs';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();

const filters = ref({ status: '', payment_status: '', from: '', to: '', day: null, kind: '' });
const range = rangeModel(() => [filters.value.from, filters.value.to], (a, b) => { filters.value.from = a; filters.value.to = b; });
const kindOptions = [
  { value: '', label: 'All jobs' },
  { value: 'service', label: t('Service_Jobs_Group') === 'Service_Jobs_Group' ? 'Service Jobs' : t('Service_Jobs_Group') },
  { value: 'device', label: t('Device_Repairs') === 'Device_Repairs' ? 'Device Repairs' : t('Device_Repairs') },
];
// Title follows the menu entry: Service Jobs / Device Repairs / Today's Jobs.
const pageTitle = computed(() => {
  const label = k => (t(k) === k ? null : t(k));
  if (filters.value.kind === 'device') return label('Device_Repairs') || 'Device Repairs';
  if (filters.value.kind === 'service') return label('Service_Jobs_Group') || 'Service Jobs';
  if (filters.value.day === todayStr) return label('Todays_Jobs') || "Today's Jobs";
  return t('Service_Jobs');
});
const { date } = useFormat();
const todayStr = dayjs().format('YYYY-MM-DD');

const crud = useCrudTable('service_jobs', {
  rowsKey: 'jobs',
  sortField: 'Ref',
  sortType: 'desc',
  params: () => {
    const p = {};
    if (filters.value.kind) p.kind = filters.value.kind;
    if (filters.value.status) p.status = filters.value.status;
    if (filters.value.payment_status) p.payment_status = filters.value.payment_status;
    if (filters.value.day) {
      // Day view: the server orders all-day first, then by time.
      p.day = filters.value.day;
    } else {
      if (filters.value.from) p.from = filters.value.from;
      if (filters.value.to) p.to = filters.value.to;
    }
    return p;
  },
});

const hasActiveFilters = computed(() =>
  !!(filters.value.status || filters.value.payment_status || filters.value.from || filters.value.to || filters.value.day || filters.value.kind));

function setDay(d) {
  filters.value.day = d;
  crud.reload();
}
function onDayChange() { crud.reload(); }
function shiftDay(n) {
  setDay(dayjs(filters.value.day || todayStr).add(n, 'day').format('YYYY-MM-DD'));
}
/** "Oct 8" or "Oct 8 – Oct 10" + "10:30 – 12:00" (no time for all-day jobs). */
function scheduleText(r) {
  const s = String(r.scheduled_date || '').replace('T', ' ');
  const e = String(r.scheduled_end_date || '').replace('T', ' ');
  const d1 = s.slice(0, 10);
  const d2 = e ? e.slice(0, 10) : '';
  const day = d2 && d2 !== d1 ? `${date(d1)} – ${date(d2)}` : date(d1);
  const t1 = s.slice(11, 16);
  const t2 = e.slice(11, 16);
  return { day, time: t1 ? `${t1}${t2 && t2 !== t1 ? ` – ${t2}` : ''}` : '' };
}

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
  // ?day=YYYY-MM-DD (or ?day=today) opens that day's jobs.
  filters.value.day = q.day === 'today' ? todayStr : (/^\d{4}-\d{2}-\d{2}$/.test(String(q.day || '')) ? String(q.day) : null);
  // ?kind=service | device (Service menu -> Service Jobs / Device Repairs).
  filters.value.kind = ['service', 'device'].includes(q.kind) ? q.kind : '';
  applyFilters();
}
function clearFilters() {
  filters.value = { status: '', payment_status: '', from: '', to: '', day: null, kind: '' };
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
.sched-time {
  margin-left: 6px;
  font-weight: 600;
}
</style>
