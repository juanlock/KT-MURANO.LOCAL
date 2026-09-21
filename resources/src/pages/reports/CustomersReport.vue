<template>
  <ReportPage
    :title="$t('CustomersReport')"
    :breadcrumb="[$t('Reports'), $t('CustomersReport')]"
    :crud="crud"
    :columns="columns"
    row-key="id"
    export-endpoint="report/client"
    export-rows-key="report"
  >
    <template #actions>
      <!-- Multi-Currency: view amounts converted (display-only) -->
      <ViewCurrencySelect />
    </template>
    <!-- Summary + charts over the WHOLE customer base (backend summary /
         top_customers / top_debtors), not the visible page. -->
    <template #chart>
      <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
        <a-col v-for="k in kpiTiles" :key="k.key" :xs="12" :sm="12" :md="8">
          <a-card size="small" class="kpi-card">
            <div class="kpi-inner">
              <div class="kpi-icon" :style="{ background: k.tint, color: k.color }">
                <component :is="k.icon" />
              </div>
              <div class="kpi-text">
                <a-typography-text type="secondary" class="kpi-label">{{ k.label }}</a-typography-text>
                <div class="kpi-value">
                  <a-spin v-if="crud.loading.value" size="small" />
                  <template v-else>{{ k.value }}</template>
                </div>
              </div>
            </div>
          </a-card>
        </a-col>
      </a-row>

      <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
        <a-col :xs="24" :xl="9">
          <a-card size="small" class="chart-card" :title="tf('Revenue_Concentration', 'Revenue concentration')">
            <apexchart
              v-if="donutSeries.length"
              type="donut"
              height="300"
              :options="donutOptions"
              :series="donutSeries"
            />
            <a-empty v-else :description="$t('NodataAvailable')" style="padding: 48px 0" />
          </a-card>
        </a-col>
        <a-col :xs="24" :xl="15">
          <a-card
            size="small"
            class="chart-card"
            :title="`${tf('Top_Debtors', 'Top debtors')} (${fmt(summary.debtors)})`"
          >
            <template v-if="topDebtors.length">
              <div v-for="d in topDebtors" :key="d.name" class="debt-row">
                <div class="debt-text">
                  <a-typography-text strong class="debt-name">{{ d.name }}</a-typography-text>
                  <a-typography-text type="secondary" class="debt-sub">
                    {{ $t('Amount') }}: {{ money(d.amount) }}
                  </a-typography-text>
                </div>
                <div class="debt-meter">
                  <span class="debt-value">{{ money(d.due) }}</span>
                  <span class="debt-track">
                    <span class="debt-fill" :style="{ width: shareOf(d) + '%' }"></span>
                  </span>
                </div>
              </div>
            </template>
            <a-empty v-else :description="tf('No_Outstanding_Due', 'No outstanding balances.')" style="padding: 32px 0" />
          </a-card>
        </a-col>
      </a-row>
    </template>

    <template #bodyCell="{ column, record }">
      <template v-if="MONEY_KEYS.includes(column.key)">{{ money(record[column.key]) }}</template>
      <template v-else-if="column.key === 'service_due' || column.key === 'total_due'">
        <strong :style="{ color: Number(record[column.key]) > 0 ? '#ff4d4f' : undefined }">
          {{ money(record[column.key]) }}
        </strong>
      </template>
      <template v-else-if="column.key === 'due'">
        <strong :style="{ color: Number(record.due) > 0 ? '#ff4d4f' : undefined }">
          {{ money(record.due) }}
        </strong>
      </template>
      <template v-else-if="column.key === 'actions'">
        <a-tooltip :title="$t('Reports')">
          <a-button type="text" size="small" @click="$router.push(`/reports/customers/${record.id}`)">
            <template #icon><EyeOutlined /></template>
          </a-button>
        </a-tooltip>
      </template>
    </template>
  </ReportPage>
</template>

<script setup>
import { computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  EyeOutlined, TeamOutlined, DollarOutlined, CheckCircleOutlined, ExclamationCircleOutlined,
} from '@ant-design/icons-vue';
import ReportPage from '../../components/ReportPage.vue';
import ViewCurrencySelect from '../../components/ViewCurrencySelect.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { useUiStore } from '../../stores/ui';
import { t as tf } from '../../i18n';

const { t } = useI18n();
const { money } = useFormat();
const ui = useUiStore();

const MONEY_KEYS = ['total_amount', 'total_paid'];

// Payload: { report, totalRows, summary, top_customers, top_debtors }
const crud = useCrudTable('report/client', {
  rowsKey: 'report',
  sortField: 'id',
  sortType: 'desc',
});

const fmt = v => (Number(v) || 0).toLocaleString();

/* --------------------------------------------------------------- summary */
const summary = computed(() => crud.payload.value?.summary || {});

const kpiTiles = computed(() => {
  const n = k => Number(summary.value[k]) || 0;
  return [
    { key: 'customers', label: t('Customers'), value: `${fmt(n('active'))} / ${fmt(n('customers'))}`, icon: TeamOutlined, color: '#1677ff', tint: 'rgba(22, 119, 255, 0.12)' },
    { key: 'amount', label: t('Amount'), value: money(n('amount')), icon: DollarOutlined, color: '#6d28d9', tint: 'rgba(109, 40, 217, 0.12)' },
    { key: 'paid', label: t('Paid'), value: money(n('paid')), icon: CheckCircleOutlined, color: '#22c55e', tint: 'rgba(34, 197, 94, 0.12)' },
    { key: 'due', label: t('Total_Sale_Due'), value: money(n('due')), icon: ExclamationCircleOutlined, color: '#f43f5e', tint: 'rgba(244, 63, 94, 0.12)' },
    { key: 'service_due', label: t('Total_Service_Due'), value: money(n('service_due')), icon: ExclamationCircleOutlined, color: '#f97316', tint: 'rgba(249, 115, 22, 0.12)' },
    { key: 'total_due', label: t('Total_Due'), value: money(n('total_due')), icon: DollarOutlined, color: '#dc2626', tint: 'rgba(220, 38, 38, 0.12)' },
  ];
});

/* ------------------------------------------------- concentration donut */
const DONUT_COLORS = ['#6366f1', '#22d3ee', '#f59e0b', '#ec4899', '#10b981', '#8b5cf6', '#0ea5e9', '#94a3b8'];

const donutRows = computed(() => crud.payload.value?.top_customers || []);
const donutSeries = computed(() => donutRows.value.map(r => Number(r.value) || 0));
const donutOptions = computed(() => ({
  chart: { type: 'donut', background: 'transparent', fontFamily: 'inherit' },
  labels: donutRows.value.map(r => r.name),
  colors: DONUT_COLORS,
  legend: { position: 'bottom', labels: { colors: ui.dark ? '#d9d9d9' : '#595959' } },
  dataLabels: { enabled: false },
  stroke: { colors: [ui.dark ? '#1f1f1f' : '#ffffff'], width: 3 },
  plotOptions: { pie: { donut: { size: '70%' } } },
  tooltip: { theme: ui.dark ? 'dark' : 'light', y: { formatter: v => money(v) } },
}));

/* ------------------------------------------------------------- debtors */
const topDebtors = computed(() => crud.payload.value?.top_debtors || []);
const maxDebt = computed(() =>
  Math.max(1, ...topDebtors.value.map(d => Number(d.due) || 0))
);
function shareOf(d) {
  return Math.round(((Number(d.due) || 0) / maxDebt.value) * 100);
}

const columns = computed(() => [
  { title: t('CustomerName'), dataIndex: 'name', key: 'name', sorter: true },
  { title: t('Phone'), dataIndex: 'phone', key: 'phone' },
  { title: t('TotalSales'), dataIndex: 'total_sales', key: 'total_sales', sorter: true, align: 'right', sum: true },
  { title: t('Amount'), key: 'total_amount', dataIndex: 'total_amount', sorter: true, align: 'right', sum: 'money', exportValue: r => money(r.total_amount) },
  { title: t('Paid'), key: 'total_paid', dataIndex: 'total_paid', sorter: true, align: 'right', sum: 'money', exportValue: r => money(r.total_paid) },
  { title: t('Total_Sale_Due'), key: 'due', dataIndex: 'due', sorter: true, align: 'right', sum: 'money', exportValue: r => money(r.due) },
  { title: t('Service_Due'), key: 'service_due', dataIndex: 'service_due', sorter: true, align: 'right', sum: 'money', exportValue: r => money(r.service_due) },
  { title: t('Total_Due'), key: 'total_due', dataIndex: 'total_due', sorter: true, align: 'right', sum: 'money', exportValue: r => money(r.total_due) },
  { title: t('Action'), key: 'actions', align: 'center', width: 80, exportable: false },
]);

onMounted(crud.fetchRows);
</script>

<style scoped>
.kpi-card,
.chart-card { border-radius: 10px; }
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
.kpi-text { min-width: 0; flex: 1 1 auto; }
.kpi-label {
  display: block;
  font-size: 12px;
  margin-bottom: 2px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.kpi-value {
  font-size: 20px;
  font-weight: 700;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* Debtor rows: name + lifetime amount, due + share meter. */
.debt-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 8px 4px;
}
.debt-row + .debt-row { border-top: 1px solid rgba(128, 128, 128, 0.14); }
.debt-text {
  min-width: 0;
  display: flex;
  flex-direction: column;
}
.debt-name,
.debt-sub {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.debt-sub { font-size: 12px; }
.debt-meter {
  display: flex;
  align-items: center;
  gap: 8px;
  flex: none;
}
.debt-value {
  font-weight: 600;
  color: #f43f5e;
}
.debt-track {
  display: inline-block;
  width: 90px;
  height: 6px;
  border-radius: 999px;
  background: rgba(128, 128, 128, 0.2);
  overflow: hidden;
}
.debt-fill {
  display: block;
  height: 100%;
  border-radius: 999px;
  background: #f43f5e;
}
@media (max-width: 575px) {
  .kpi-value { font-size: 16px; }
}
</style>
