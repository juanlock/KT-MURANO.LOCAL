<template>
  <ReportPage
    :title="$t('Users_Report')"
    :breadcrumb="[$t('Reports'), $t('Users_Report')]"
    :crud="crud"
    :columns="columns"
    row-key="id"
    export-endpoint="report/users"
    export-rows-key="report"
  >
    <!-- Team activity over ALL matching users (backend summary / activity /
         top_users), not the visible page. -->
    <template #chart>
      <a-card size="small" class="band-card" style="margin-bottom: 16px">
        <div class="band">
          <div v-for="(cell, i) in bandCells" :key="cell.key" class="band-cell" :class="{ first: i === 0 }">
            <a-typography-text type="secondary" class="band-label">{{ cell.label }}</a-typography-text>
            <div class="band-value">
              <a-spin v-if="crud.loading.value" size="small" />
              <template v-else>{{ cell.value }}</template>
            </div>
          </div>
        </div>
      </a-card>

      <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
        <a-col :xs="24" :xl="9">
          <a-card size="small" class="chart-card" :title="tf('Activity_Mix', 'Activity mix')">
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
          <a-card size="small" class="chart-card" :title="tf('Top_Contributors', 'Top contributors')">
            <template v-if="topUsers.length">
              <div v-for="(u, i) in topUsers" :key="u.name" class="contrib-row">
                <span class="contrib-rank" :style="rankStyle(i)">{{ i + 1 }}</span>
                <a-typography-text strong class="contrib-name">{{ u.name }}</a-typography-text>
                <div class="contrib-meter">
                  <span class="contrib-count">{{ fmt(u.count) }}</span>
                  <span class="contrib-track">
                    <span class="contrib-fill" :style="{ width: shareOf(u) + '%' }"></span>
                  </span>
                </div>
              </div>
            </template>
            <a-empty v-else :description="$t('NodataAvailable')" style="padding: 32px 0" />
          </a-card>
        </a-col>
      </a-row>
    </template>

    <template #bodyCell="{ column, record }">
      <template v-if="column.key === 'actions'">
        <a-tooltip :title="$t('Reports')">
          <a-button type="text" size="small" @click="$router.push(`/reports/users/${record.id}`)">
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
import { EyeOutlined } from '@ant-design/icons-vue';
import ReportPage from '../../components/ReportPage.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useUiStore } from '../../stores/ui';
import { t as tf } from '../../i18n';

const { t } = useI18n();
const ui = useUiStore();

// Payload: { report, totalRows, summary, activity, top_users }.
// All columns are activity counts, not money.
const crud = useCrudTable('report/users', {
  rowsKey: 'report',
  sortField: 'id',
  sortType: 'desc',
});

const fmt = v => (Number(v) || 0).toLocaleString();

/* ------------------------------------------------------------ stat band */
const summary = computed(() => crud.payload.value?.summary || {});

const bandCells = computed(() => {
  const n = k => Number(summary.value[k]) || 0;
  return [
    { key: 'users', label: tf('Team_Members', 'Team members'), value: `${fmt(n('active_users'))} / ${fmt(n('users'))}` },
    { key: 'documents', label: tf('Documents_Created', 'Documents created'), value: fmt(n('documents')) },
    {
      key: 'top',
      label: tf('Top_Contributor', 'Top contributor'),
      value: summary.value.top_name ? `${summary.value.top_name} (${fmt(n('top_count'))})` : '—',
    },
  ];
});

/* --------------------------------------------------------- activity mix */
const ACTIVITY_META = computed(() => ([
  { key: 'sales', label: t('Sales') },
  { key: 'purchases', label: t('Purchases') },
  { key: 'quotations', label: tf('Quotations', 'Quotations') },
  { key: 'sale_returns', label: t('SalesReturn') },
  { key: 'purchase_returns', label: t('PurchasesReturn') },
  { key: 'transfers', label: tf('Transfers', 'Transfers') },
  { key: 'adjustments', label: tf('Adjustments', 'Adjustments') },
]));
const DONUT_COLORS = ['#6366f1', '#22d3ee', '#f59e0b', '#ec4899', '#10b981', '#8b5cf6', '#0ea5e9'];

const donutRows = computed(() => {
  const activity = crud.payload.value?.activity || {};
  return ACTIVITY_META.value
    .map((m, i) => ({ ...m, color: DONUT_COLORS[i], count: Number(activity[m.key]) || 0 }))
    .filter(r => r.count > 0);
});
const donutSeries = computed(() => donutRows.value.map(r => r.count));
const donutOptions = computed(() => ({
  chart: { type: 'donut', background: 'transparent', fontFamily: 'inherit' },
  labels: donutRows.value.map(r => r.label),
  colors: donutRows.value.map(r => r.color),
  legend: { position: 'bottom', labels: { colors: ui.dark ? '#d9d9d9' : '#595959' } },
  dataLabels: { enabled: false },
  stroke: { colors: [ui.dark ? '#1f1f1f' : '#ffffff'], width: 3 },
  plotOptions: { pie: { donut: { size: '70%' } } },
  tooltip: { theme: ui.dark ? 'dark' : 'light' },
}));

/* ---------------------------------------------------------- leaderboard */
const topUsers = computed(() => crud.payload.value?.top_users || []);
const maxUserDocs = computed(() =>
  Math.max(1, ...topUsers.value.map(u => Number(u.count) || 0))
);
function shareOf(u) {
  return Math.round(((Number(u.count) || 0) / maxUserDocs.value) * 100);
}

const RANK_COLORS = ['#f59e0b', '#94a3b8', '#d97706'];
function rankStyle(i) {
  const c = RANK_COLORS[i];
  return c
    ? { background: c, color: '#fff' }
    : { background: 'rgba(128, 128, 128, 0.15)' };
}

/* ---------------------------------------------------------------- table */
const columns = computed(() => [
  { title: t('username'), dataIndex: 'username', key: 'username', sorter: true },
  { title: t('TotalSales'), dataIndex: 'total_sales', key: 'total_sales', sorter: true, align: 'right', sum: true },
  { title: t('TotalPurchases'), dataIndex: 'total_purchases', key: 'total_purchases', sorter: true, align: 'right', sum: true },
  { title: t('Total_quotations'), dataIndex: 'total_quotations', key: 'total_quotations', align: 'right', sum: true },
  { title: t('Total_return_sales'), dataIndex: 'total_return_sales', key: 'total_return_sales', align: 'right', sum: true },
  { title: t('Total_return_purchases'), dataIndex: 'total_return_purchases', key: 'total_return_purchases', align: 'right', sum: true },
  { title: t('Total_transfers'), dataIndex: 'total_transfers', key: 'total_transfers', align: 'right', sum: true },
  { title: t('Total_adjustments'), dataIndex: 'total_adjustments', key: 'total_adjustments', align: 'right', sum: true },
  { title: t('Action'), key: 'actions', align: 'center', width: 80, exportable: false },
]);

onMounted(crud.fetchRows);
</script>

<style scoped>
.band-card,
.chart-card { border-radius: 10px; }

/* Divided stat band — one card, cells split by hairlines (wraps on mobile). */
.band {
  display: flex;
  flex-wrap: wrap;
  gap: 12px 0;
}
.band-cell {
  flex: 1 1 180px;
  min-width: 180px;
  padding: 2px 20px;
  border-inline-start: 1px solid rgba(128, 128, 128, 0.2);
}
.band-cell.first {
  border-inline-start: none;
  padding-inline-start: 4px;
}
.band-label {
  display: block;
  font-size: 12px;
  margin-bottom: 2px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.band-value {
  font-size: 22px;
  font-weight: 700;
  line-height: 1.25;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* Contributor rows: rank badge, name, document count + share meter. */
.contrib-row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 9px 4px;
}
.contrib-row + .contrib-row { border-top: 1px solid rgba(128, 128, 128, 0.14); }
.contrib-rank {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  border-radius: 999px;
  font-weight: 700;
  font-size: 13px;
  flex: none;
}
.contrib-name {
  min-width: 0;
  flex: 1;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.contrib-meter {
  display: flex;
  align-items: center;
  gap: 8px;
  flex: none;
}
.contrib-count {
  font-weight: 600;
  min-width: 40px;
  text-align: end;
}
.contrib-track {
  display: inline-block;
  width: 110px;
  height: 6px;
  border-radius: 999px;
  background: rgba(128, 128, 128, 0.2);
  overflow: hidden;
}
.contrib-fill {
  display: block;
  height: 100%;
  border-radius: 999px;
  background: #6d28d9;
}
</style>
