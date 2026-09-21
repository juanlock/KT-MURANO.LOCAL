<template>
  <div class="page">
    <PageHeader :title="$t('Commission_Report')" :breadcrumb="[$t('Commissions'), $t('Commission_Report')]">
      <template #extra>
        <a-button type="primary" @click="applyFilters">
          <template #icon><ReloadOutlined /></template>
          {{ $t('Refresh') }}
        </a-button>
      </template>
    </PageHeader>

    <!-- Filters -->
    <a-card size="small" style="margin-bottom: 16px">
      <a-row :gutter="[12, 12]">
        <a-col :xs="12" :md="6">
          <div class="filter-label">{{ $t('Date_From') }}</div>
          <a-input v-model:value="filterDateFrom" type="date" />
        </a-col>
        <a-col :xs="12" :md="6">
          <div class="filter-label">{{ $t('Date_To') }}</div>
          <a-input v-model:value="filterDateTo" type="date" />
        </a-col>
        <a-col :xs="12" :md="6">
          <div class="filter-label">{{ $t('Sales_Agent') }}</div>
          <a-select
            v-model:value="filterAgentId" :placeholder="$t('All')"
            :options="agentsList.map(a => ({ label: a.name, value: a.id }))"
            show-search option-filter-prop="label" allow-clear style="width: 100%"
          />
        </a-col>
        <a-col :xs="12" :md="6">
          <div class="filter-label">{{ $t('Status') }}</div>
          <a-select v-model:value="filterStatus" style="width: 100%">
            <a-select-option value="">{{ $t('All') }}</a-select-option>
            <a-select-option value="pending">{{ $t('Pending') }}</a-select-option>
            <a-select-option value="approved">{{ $t('Approved') }}</a-select-option>
            <a-select-option value="paid">{{ $t('Paid') }}</a-select-option>
            <a-select-option value="cancelled">{{ $t('Cancelled') }}</a-select-option>
          </a-select>
        </a-col>
      </a-row>
    </a-card>

    <!-- Summary stat cards -->
    <a-row v-if="summary" :gutter="[16, 16]" style="margin-bottom: 16px">
      <a-col :xs="12" :md="6">
        <a-card size="small">
          <a-statistic :title="$t('Pending')" :value="formatMoney(summary.totals && summary.totals.pending_total)" :value-style="{ color: '#d48806' }" />
        </a-card>
      </a-col>
      <a-col :xs="12" :md="6">
        <a-card size="small">
          <a-statistic :title="$t('Approved')" :value="formatMoney(summary.totals && summary.totals.approved_total)" :value-style="{ color: '#1677ff' }" />
        </a-card>
      </a-col>
      <a-col :xs="12" :md="6">
        <a-card size="small">
          <a-statistic :title="$t('Paid')" :value="formatMoney(summary.totals && summary.totals.paid_total)" :value-style="{ color: '#3f8600' }" />
        </a-card>
      </a-col>
      <a-col :xs="12" :md="6">
        <a-card size="small">
          <a-statistic :title="$t('Total')" :value="formatMoney(summary.totals && summary.totals.grand_total)" />
        </a-card>
      </a-col>
    </a-row>

    <!-- Charts -->
    <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
      <a-col :xs="24" :lg="10">
        <a-card size="small" :title="$t('By_Status')">
          <a-spin :spinning="isChartsLoading">
            <apexchart
              v-if="statusSeries.some(s => s > 0)"
              :key="'cs-' + chartKey" type="donut" height="320"
              :options="statusOptions" :series="statusSeries"
            />
            <a-empty v-else :description="$t('No_Data')" style="padding: 60px 0" />
          </a-spin>
        </a-card>
      </a-col>
      <a-col :xs="24" :lg="14">
        <a-card size="small" :title="$t('By_Agent')">
          <a-spin :spinning="isChartsLoading">
            <apexchart
              v-if="agentSeries[0].data.length"
              :key="'ca-' + chartKey" type="bar" height="320"
              :options="agentOptions" :series="agentSeries"
            />
            <a-empty v-else :description="$t('No_Data')" style="padding: 60px 0" />
          </a-spin>
        </a-card>
      </a-col>
    </a-row>

    <!-- Table -->
    <a-card size="small" :body-style="{ padding: 0 }">
      <div v-if="crud.selectedIds.value.length && auth.can('commissions_edit')" style="padding: 12px 16px; border-bottom: 1px solid rgba(5, 5, 5, 0.06)">
        <a-space>
          <a-button type="primary" @click="approveSelected">
            <template #icon><CheckOutlined /></template>
            {{ $t('Approved') }} ({{ crud.selectedIds.value.length }})
          </a-button>
          <a-button danger @click="cancelSelected">
            <template #icon><CloseOutlined /></template>
            {{ $t('Cancel') }} ({{ crud.selectedIds.value.length }})
          </a-button>
        </a-space>
      </div>
      <a-table
        :columns="columns" :data-source="crud.rows.value"
        :loading="crud.loading.value" :pagination="crud.pagination.value"
        :row-selection="crud.rowSelection.value"
        row-key="id" size="middle" :scroll="{ x: 'max-content' }"
        :locale="{ emptyText: $t('NodataAvailable') }"
        @change="crud.onChange"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'sale_ref'">
            {{ record.sale ? record.sale.Ref : '—' }}
          </template>
          <template v-else-if="column.key === 'agent'">
            {{ record.sales_agent ? record.sales_agent.name : '—' }}
          </template>
          <template v-else-if="column.key === 'program'">
            {{ record.commission_program ? record.commission_program.name : '—' }}
          </template>
          <template v-else-if="column.key === 'base_amount'">
            {{ formatMoney(record.base_amount) }}
          </template>
          <template v-else-if="column.key === 'commission_amount'">
            {{ formatMoney(record.commission_amount) }}
          </template>
          <template v-else-if="column.key === 'status'">
            <a-tag :color="statusColor(record.status)">{{ record.status }}</a-tag>
          </template>
          <template v-else-if="column.key === 'calculated_at'">
            {{ formatDateTime(record.calculated_at) }}
          </template>
        </template>
      </a-table>
    </a-card>
  </div>
</template>

<script setup>
/**
 * Commission report — table GET commission_report (date_from/date_to/
 * sales_agent_id/status filters) → {commissions, totalRows}; summary GET
 * commission_report/summary → {totals{pending_total, approved_total,
 * paid_total, grand_total}, by_status}; agent chart GET
 * commission_report/by_agent → {by_agent[{name, code, total_commission}]}.
 * Bulk actions on selected rows: POST commissions/approve|cancel
 * {commission_ids} (cancel behind a confirm), edit-permission gated.
 */
import { ref, computed, onMounted, createVNode } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { ExclamationCircleOutlined, ReloadOutlined, CheckOutlined, CloseOutlined } from '@ant-design/icons-vue';
import { useI18n } from 'vue-i18n';
import PageHeader from '../../components/PageHeader.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useAuthStore } from '../../stores/auth';
import http from '../../lib/http';

const { t } = useI18n();
const auth = useAuthStore();

const filterDateFrom = ref('');
const filterDateTo = ref('');
const filterAgentId = ref(null);
const filterStatus = ref('');
const agentsList = ref([]);

const summary = ref(null);
const byAgent = ref([]);
const isChartsLoading = ref(true);
const chartKey = ref(0);

function filterParams() {
  const p = {};
  if (filterDateFrom.value) p.date_from = filterDateFrom.value;
  if (filterDateTo.value) p.date_to = filterDateTo.value;
  if (filterAgentId.value) p.sales_agent_id = filterAgentId.value;
  return p;
}

const crud = useCrudTable('commission_report', {
  sortField: 'calculated_at',
  sortType: 'desc',
  params: () => ({
    ...filterParams(),
    ...(filterStatus.value ? { status: filterStatus.value } : {}),
  }),
  select: p => {
    const d = p.data || p;
    return { rows: d.commissions || [], total: d.totalRows || 0 };
  },
});

const columns = computed(() => [
  { title: t('sale_ref'), key: 'sale_ref' },
  { title: t('Sales_Agent'), key: 'agent' },
  { title: t('Program'), key: 'program' },
  { title: t('Base_Amount'), key: 'base_amount', align: 'right' },
  { title: t('Commission'), key: 'commission_amount', align: 'right' },
  { title: t('Status'), key: 'status', width: 110 },
  { title: t('Calculated_At'), key: 'calculated_at', sorter: true },
]);

const statusSeries = ref([]);
const statusOptions = computed(() => ({
  chart: { type: 'donut', fontFamily: 'inherit' },
  labels: [t('Pending'), t('Approved'), t('Paid'), t('Cancelled')],
  colors: ['#f59e0b', '#3b82f6', '#10b981', '#6b7280'],
  legend: { position: 'bottom', fontSize: '14px' },
  dataLabels: { enabled: true, formatter: val => `${Number(val || 0).toFixed(1)}%` },
  tooltip: { y: { formatter: val => formatMoney(val) } },
  plotOptions: {
    pie: {
      donut: {
        size: '65%',
        labels: {
          show: true,
          name: { show: true, fontSize: '14px', fontWeight: 600 },
          value: { show: true, fontSize: '18px', fontWeight: 700, formatter: val => formatMoney(val) },
          total: {
            show: true,
            label: t('Total'),
            formatter: () => formatMoney(statusSeries.value.reduce((a, b) => a + (Number(b) || 0), 0)),
          },
        },
      },
    },
  },
}));

const agentSeries = ref([{ name: t('Commission'), data: [] }]);
const agentOptions = computed(() => ({
  chart: { type: 'bar', fontFamily: 'inherit', toolbar: { show: false } },
  plotOptions: { bar: { borderRadius: 4, horizontal: true, barHeight: '70%' } },
  dataLabels: { enabled: true, formatter: val => formatMoney(val) },
  xaxis: { categories: byAgent.value.map(a => a.name || a.code || '—') },
  colors: ['#6366f1'],
  grid: { xaxis: { lines: { show: false } } },
  tooltip: { y: { formatter: val => formatMoney(val) } },
}));

function formatMoney(v) {
  return v != null ? Number(v).toLocaleString(undefined, { minimumFractionDigits: 2 }) : '0.00';
}
function formatDateTime(v) { return v ? new Date(v).toLocaleString() : '—'; }
function statusColor(s) {
  return { pending: 'warning', approved: 'processing', paid: 'success', cancelled: 'default' }[s] || 'default';
}

async function loadSummary() {
  const data = await http.get('commission_report/summary', filterParams());
  summary.value = data.data || data;
  const tt = summary.value && summary.value.totals;
  statusSeries.value = tt
    ? [
        Number(tt.pending_total) || 0,
        Number(tt.approved_total) || 0,
        Number(tt.paid_total) || 0,
        Number(summary.value.by_status?.cancelled?.total || 0),
      ]
    : [];
}
async function loadByAgent() {
  const p = {};
  if (filterDateFrom.value) p.date_from = filterDateFrom.value;
  if (filterDateTo.value) p.date_to = filterDateTo.value;
  const data = await http.get('commission_report/by_agent', p);
  const d = data.data || data;
  byAgent.value = d.by_agent || [];
  agentSeries.value = [{
    name: t('Commission'),
    data: byAgent.value.map(a => Number(a.total_commission) || 0),
  }];
}
async function loadAllCharts() {
  isChartsLoading.value = true;
  try {
    await Promise.all([loadSummary(), loadByAgent()]);
    chartKey.value++;
  } catch (e) { /* charts stay empty */ }
  isChartsLoading.value = false;
}
function applyFilters() {
  loadAllCharts();
  crud.reload();
}

async function approveSelected() {
  if (!crud.selectedIds.value.length) return;
  try {
    await http.post('commissions/approve', { commission_ids: crud.selectedIds.value });
    message.success(t('Success'));
    crud.selectedIds.value = [];
    await crud.fetchRows();
    loadAllCharts();
  } catch (e) {
    message.error(e?.data?.message || t('Error'));
  }
}
function cancelSelected() {
  if (!crud.selectedIds.value.length) return;
  Modal.confirm({
    title: t('Confirm_delete'),
    icon: createVNode(ExclamationCircleOutlined),
    okType: 'danger',
    async onOk() {
      try {
        await http.post('commissions/cancel', { commission_ids: crud.selectedIds.value });
        message.success(t('Success'));
        crud.selectedIds.value = [];
        await crud.fetchRows();
        loadAllCharts();
      } catch (e) {
        message.error(e?.data?.message || t('Error'));
      }
    },
  });
}

onMounted(async () => {
  crud.fetchRows();
  loadAllCharts();
  try {
    const data = await http.get('sales_agents_list_for_select');
    const d = data.data || data;
    agentsList.value = Array.isArray(d) ? d : (d.agents || []);
  } catch (e) { /* filter stays empty */ }
});
</script>

<style scoped>
.filter-label {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.55);
  margin-bottom: 4px;
}
</style>
