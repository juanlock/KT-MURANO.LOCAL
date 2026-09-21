<template>
  <ReportPage
    :title="$t('Customer_Loyalty_Points_Report')"
    :breadcrumb="[$t('Reports'), $t('Customer_Loyalty_Points_Report')]"
    :crud="crud"
    :columns="columns"
    row-key="client_id"
    export-endpoint="report/customer_loyalty_points"
    :export-params="filterParams"
    export-rows-key="rows"
  >
    <!-- Program pulse over the WHOLE filtered range (backend totals /
         timeseries / top_members), not the visible page. -->
    <template #chart>
      <a-card size="small" class="pulse-card" style="margin-bottom: 16px">
        <div class="pulse">
          <!-- Redemption rate ring: how much of what members earn comes back. -->
          <div class="pulse-ring">
            <a-progress
              type="dashboard"
              :size="118"
              :percent="redemptionRate"
              stroke-color="#6d28d9"
            />
            <a-typography-text type="secondary" class="pulse-ring-label">
              {{ tf('Redemption_Rate', 'Redemption rate') }}
            </a-typography-text>
            <a-typography-text type="secondary" class="pulse-ring-sub">
              {{ fmt(crud.total.value) }} {{ tf('Active_Members', 'active members') }}
            </a-typography-text>
          </div>

          <div class="pulse-cells">
            <div v-for="cell in pulseCells" :key="cell.key" class="pulse-cell">
              <a-typography-text type="secondary" class="pulse-label">{{ cell.label }}</a-typography-text>
              <div class="pulse-value" :style="{ color: cell.color }">
                <a-spin v-if="crud.loading.value" size="small" />
                <template v-else>{{ cell.value }}</template>
              </div>
            </div>
          </div>
        </div>
      </a-card>

      <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
        <a-col :xs="24" :xl="9">
          <a-card size="small" class="chart-card" :title="tf('Top_Members', 'Top members')">
            <template v-if="topMembers.length">
              <div v-for="(m, i) in topMembers" :key="m.name" class="lead-row">
                <span class="lead-rank" :style="rankStyle(i)">{{ i + 1 }}</span>
                <div class="lead-text">
                  <a-typography-text strong class="lead-name">{{ m.name }}</a-typography-text>
                  <a-typography-text type="secondary" class="lead-sub">
                    {{ $t('Points_Balance') }}: {{ fmt(m.balance) }}
                  </a-typography-text>
                </div>
                <span class="lead-earned">+{{ fmt(m.earned) }}</span>
              </div>
            </template>
            <a-empty v-else :description="$t('NodataAvailable')" style="padding: 32px 0" />
          </a-card>
        </a-col>
        <a-col :xs="24" :xl="15">
          <ReportChart
            :data="crud.payload.value?.timeseries"
            :fields="[
              { key: 'earned', label: t('Points_Earned') },
              { key: 'redeemed', label: t('Points_Redeemed') },
            ]"
            :title="tf('Points_Flow', 'Points earned vs redeemed')"
            type="area"
            x-key="d"
            :height="300"
          />
        </a-col>
      </a-row>
    </template>

    <template #filters>
      <DateRangePicker v-model:value="range" :allow-clear="false" @change="crud.reload()" />
    </template>

    <template #bodyCell="{ column, record }">
      <template v-if="column.key === 'current_points'">
        <a-tag :color="Number(record.current_points) > 0 ? 'success' : 'default'">
          {{ number(record.current_points, 0) }}
        </a-tag>
      </template>
    </template>
  </ReportPage>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import dayjs from 'dayjs';
import { useI18n } from 'vue-i18n';
import ReportPage from '../../components/ReportPage.vue';
import ReportChart from '../../components/ReportChart.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { t as tf } from '../../i18n';
import DateRangePicker from '../../components/DateRangePicker.vue';

const { t } = useI18n();
const { number } = useFormat();

const range = ref([dayjs().subtract(29, 'day'), dayjs()]);

const filterParams = () => ({
  from: range.value?.[0]?.format?.('YYYY-MM-DD') || '',
  to: range.value?.[1]?.format?.('YYYY-MM-DD') || '',
});

// Payload: { rows, totalRows, totals, timeseries, top_members } — rowsKey required.
const crud = useCrudTable('report/customer_loyalty_points', {
  rowsKey: 'rows',
  sortField: 'earned_points',
  sortType: 'desc',
  params: filterParams,
});

const totals = computed(
  () => crud.payload.value?.totals || { earned_total: 0, redeemed_total: 0, balance_total: 0 }
);

const fmt = v => number(Number(v) || 0, 0);

/* ----------------------------------------------------------- pulse card */
const redemptionRate = computed(() => {
  const earned = Number(totals.value.earned_total) || 0;
  const redeemed = Number(totals.value.redeemed_total) || 0;
  if (!earned) return 0;
  return Math.min(100, Math.round((redeemed / earned) * 100));
});

const pulseCells = computed(() => {
  const earned = Number(totals.value.earned_total) || 0;
  const redeemed = Number(totals.value.redeemed_total) || 0;
  const net = earned - redeemed;
  return [
    { key: 'earned', label: t('Points_Earned'), value: fmt(earned), color: '#10b981' },
    { key: 'redeemed', label: t('Points_Redeemed'), value: fmt(redeemed), color: '#f59e0b' },
    { key: 'net', label: tf('Net_This_Period', 'Net this period'), value: `${net >= 0 ? '+' : ''}${fmt(net)}`, color: net >= 0 ? '#10b981' : '#f43f5e' },
    { key: 'balance', label: tf('Outstanding_Balance', 'Outstanding balance'), value: fmt(totals.value.balance_total), color: '#6d28d9' },
  ];
});

/* ---------------------------------------------------------- leaderboard */
const topMembers = computed(() => crud.payload.value?.top_members || []);

const RANK_COLORS = ['#f59e0b', '#94a3b8', '#d97706'];
function rankStyle(i) {
  const c = RANK_COLORS[i];
  return c
    ? { background: c, color: '#fff' }
    : { background: 'rgba(128, 128, 128, 0.15)' };
}

/* ---------------------------------------------------------------- table */
const columns = computed(() => [
  { title: t('Customer'), dataIndex: 'client_name', key: 'client_name', sorter: true },
  { title: t('Points_Earned'), dataIndex: 'earned_points', key: 'earned_points', sorter: true, align: 'right', sum: true },
  { title: t('Points_Redeemed'), dataIndex: 'redeemed_points', key: 'redeemed_points', sorter: true, align: 'right', sum: true },
  {
    title: t('Points_Balance'),
    key: 'current_points',
    dataIndex: 'current_points',
    sorter: true,
    align: 'right',
    sum: true,
    exportValue: r => r.current_points,
  },
]);

onMounted(crud.fetchRows);
</script>

<style scoped>
.pulse-card,
.chart-card { border-radius: 10px; }

/* Pulse card: redemption ring on the left, divided stat cells on the right. */
.pulse {
  display: flex;
  align-items: center;
  gap: 24px;
  flex-wrap: wrap;
}
.pulse-ring {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
  flex: none;
}
.pulse-ring-label { font-size: 12px; }
.pulse-ring-sub { font-size: 12px; }

.pulse-cells {
  flex: 1 1 320px;
  display: flex;
  flex-wrap: wrap;
  gap: 12px 0;
}
.pulse-cell {
  flex: 1 1 140px;
  min-width: 140px;
  padding: 2px 20px;
  border-inline-start: 1px solid rgba(128, 128, 128, 0.2);
}
.pulse-label {
  display: block;
  font-size: 12px;
  margin-bottom: 2px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.pulse-value {
  font-size: 22px;
  font-weight: 700;
  line-height: 1.25;
  white-space: nowrap;
}

/* Leaderboard rows: rank badge, member, points earned. */
.lead-row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 9px 4px;
}
.lead-row + .lead-row { border-top: 1px solid rgba(128, 128, 128, 0.14); }
.lead-rank {
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
.lead-text {
  min-width: 0;
  flex: 1;
  display: flex;
  flex-direction: column;
}
.lead-name,
.lead-sub {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.lead-sub { font-size: 12px; }
.lead-earned {
  font-weight: 700;
  color: #10b981;
  flex: none;
}
</style>
