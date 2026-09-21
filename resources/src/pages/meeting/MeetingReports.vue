<template>
  <div class="page">
    <PageHeader :title="$t('Reports')" :breadcrumb="[$t('Meeting_Management'), $t('Reports')]">
      <template #extra>
        <a-segmented v-model:value="period" :options="periodOptions" @change="fetchReports" />
      </template>
    </PageHeader>

    <div v-if="isLoading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <template v-else>
      <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
        <a-col :xs="12" :md="6">
          <a-card size="small">
            <a-statistic :title="$t('Total_Meetings')" :value="summary.total || 0" :value-style="{ color: '#4361ee' }" />
          </a-card>
        </a-col>
        <a-col :xs="12" :md="6">
          <a-card size="small">
            <a-statistic :title="$t('Upcoming_Meetings')" :value="summary.upcoming || 0" :value-style="{ color: '#06b6d4' }" />
          </a-card>
        </a-col>
        <a-col :xs="12" :md="6">
          <a-card size="small">
            <a-statistic :title="$t('Completed_Meetings')" :value="summary.completed || 0" :value-style="{ color: '#22c55e' }" />
          </a-card>
        </a-col>
        <a-col :xs="12" :md="6">
          <a-card size="small">
            <a-statistic :title="$t('Cancelled_Meetings')" :value="summary.cancelled || 0" :value-style="{ color: '#ef4444' }" />
          </a-card>
        </a-col>
      </a-row>

      <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
        <a-col :xs="24" :lg="12">
          <a-card size="small" :title="$t('Meetings_By_Status')" style="height: 100%">
            <apexchart
              v-if="statusTotal > 0"
              :key="'st-' + chartKey" type="donut" height="300"
              :options="donutOptions" :series="donutSeries"
            />
            <a-empty v-else :description="$t('No_Data')" style="padding: 48px 0" />
          </a-card>
        </a-col>
        <a-col :xs="24" :lg="12">
          <a-card size="small" :title="$t('Meetings_By_Type')" style="height: 100%">
            <a-row :gutter="12" style="margin-bottom: 16px">
              <a-col :span="12">
                <a-card size="small" style="text-align: center; background: #eef1ff">
                  <div style="font-size: 24px; font-weight: 700">{{ byType.physical || 0 }}</div>
                  <div style="font-size: 12px; color: #64748b">{{ $t('Physical') }}</div>
                </a-card>
              </a-col>
              <a-col :span="12">
                <a-card size="small" style="text-align: center; background: #e7fbff">
                  <div style="font-size: 24px; font-weight: 700">{{ byType.online || 0 }}</div>
                  <div style="font-size: 12px; color: #64748b">{{ $t('Online') }}</div>
                </a-card>
              </a-col>
            </a-row>
            <div class="split-bar">
              <div :style="{ width: physicalPct + '%', background: '#4361ee' }"></div>
              <div :style="{ width: onlinePct + '%', background: '#06b6d4' }"></div>
            </div>
            <div style="display: flex; justify-content: space-between; font-size: 12px; color: #64748b; margin-top: 8px">
              <span>{{ $t('Physical') }} {{ physicalPct }}%</span>
              <span>{{ $t('Online') }} {{ onlinePct }}%</span>
            </div>
          </a-card>
        </a-col>
      </a-row>

      <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
        <a-col :xs="24" :lg="12">
          <a-card size="small" :title="$t('Attendance_Statistics')" style="height: 100%">
            <div style="display: flex; gap: 24px; align-items: center; flex-wrap: wrap">
              <a-progress
                type="dashboard" :percent="Number(attendanceRate) || 0"
                :stroke-color="'#22c55e'"
              />
              <div style="flex: 1; min-width: 200px">
                <a-empty v-if="attendanceTotal === 0" :description="$t('No_Data')" />
                <div v-else v-for="a in ATTENDANCE_ORDER" :key="a" style="margin-bottom: 12px">
                  <div style="display: flex; justify-content: space-between; margin-bottom: 4px">
                    <span style="text-transform: capitalize">{{ formatLabel(a) }}</span>
                    <span style="color: #999">{{ attendance[a] || 0 }}</span>
                  </div>
                  <a-progress
                    :percent="attPct(a)" :show-info="false" size="small"
                    :stroke-color="ATTEND_COLORS[a]"
                  />
                </div>
              </div>
            </div>
          </a-card>
        </a-col>
        <a-col :xs="24" :lg="12">
          <a-card size="small" :title="$t('Top_Organizers')" style="height: 100%">
            <a-list :data-source="topOrganizers" size="small" :locale="{ emptyText: $t('No_Data') }">
              <template #renderItem="{ item, index }">
                <a-list-item>
                  <a-list-item-meta>
                    <template #avatar>
                      <a-avatar style="background: linear-gradient(135deg, #4361ee, #7c3aed)">
                        {{ initials(userName(item.organizer)) }}
                      </a-avatar>
                    </template>
                    <template #title>
                      <a-tag v-if="index < 3" :color="['gold', 'default', 'orange'][index]">#{{ index + 1 }}</a-tag>
                      {{ userName(item.organizer) }}
                    </template>
                  </a-list-item-meta>
                  <strong style="color: #4361ee">{{ item.count }}</strong>
                </a-list-item>
              </template>
            </a-list>
          </a-card>
        </a-col>
      </a-row>

      <a-card size="small" :title="$t('Monthly_Trend')">
        <apexchart
          v-if="monthlyTrend.length"
          :key="'mt-' + chartKey" type="bar" height="280"
          :options="trendOptions" :series="trendSeries"
        />
        <a-empty v-else :description="$t('No_Data')" style="padding: 48px 0" />
      </a-card>
    </template>
  </div>
</template>

<script setup>
/**
 * Meeting reports — GET meeting/reports?period=1month|3months|6months|1year
 * → {summary{total, upcoming, completed, cancelled}, by_status, by_type,
 * attendance{present,late,absent,pending}, attendance_rate, monthly_trend
 * [{month, total, completed, cancelled}], top_organizers[{organizer, count}]}.
 * Legacy hand-rolled SVG donut/gauge/columns → apexcharts + a-progress.
 */
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const { t } = useI18n();

const STATUS_ORDER = ['scheduled', 'ongoing', 'completed', 'cancelled'];
const STATUS_COLORS = ['#4361ee', '#f59e0b', '#22c55e', '#ef4444'];
const ATTENDANCE_ORDER = ['present', 'late', 'absent', 'pending'];
const ATTEND_COLORS = { present: '#22c55e', late: '#f59e0b', absent: '#ef4444', pending: '#94a3b8' };

const isLoading = ref(true);
const period = ref('6months');
const summary = ref({});
const byStatus = ref({});
const byType = ref({});
const attendance = ref({});
const attendanceRate = ref(0);
const monthlyTrend = ref([]);
const topOrganizers = ref([]);
const chartKey = ref(0);

const periodOptions = computed(() => [
  { value: '1month', label: t('Last_Month') },
  { value: '3months', label: t('Last_3_Months') },
  { value: '6months', label: t('Last_6_Months') },
  { value: '1year', label: t('Last_Year') },
]);

const donutSeries = computed(() => STATUS_ORDER.map(s => Number(byStatus.value[s]) || 0));
const statusTotal = computed(() => donutSeries.value.reduce((a, b) => a + b, 0));
const donutOptions = computed(() => ({
  chart: { type: 'donut', fontFamily: 'inherit' },
  labels: STATUS_ORDER.map(s => formatLabel(s)),
  colors: STATUS_COLORS,
  legend: { position: 'bottom' },
  dataLabels: { enabled: false },
}));

const typeTotal = computed(() => (Number(byType.value.physical) || 0) + (Number(byType.value.online) || 0));
const physicalPct = computed(() =>
  typeTotal.value ? Math.round(((Number(byType.value.physical) || 0) / typeTotal.value) * 100) : 0);
const onlinePct = computed(() => (typeTotal.value ? 100 - physicalPct.value : 0));

const attendanceTotal = computed(() =>
  ATTENDANCE_ORDER.reduce((s, k) => s + (Number(attendance.value[k]) || 0), 0));

const trendSeries = computed(() => [
  { name: t('Total_Meetings'), data: monthlyTrend.value.map(r => Number(r.total) || 0) },
  { name: t('Completed'), data: monthlyTrend.value.map(r => Number(r.completed) || 0) },
  { name: t('Cancelled'), data: monthlyTrend.value.map(r => Number(r.cancelled) || 0) },
]);
const trendOptions = computed(() => ({
  chart: { type: 'bar', fontFamily: 'inherit', toolbar: { show: false } },
  plotOptions: { bar: { borderRadius: 4, columnWidth: '55%' } },
  colors: ['#4361ee', '#22c55e', '#ef4444'],
  dataLabels: { enabled: false },
  xaxis: { categories: monthlyTrend.value.map(r => monthLabel(r.month)) },
  legend: { position: 'bottom' },
}));

function formatLabel(v) { return v ? String(v).replace(/_/g, ' ') : '-'; }
function attPct(a) {
  return attendanceTotal.value
    ? Math.round(((Number(attendance.value[a]) || 0) / attendanceTotal.value) * 100)
    : 0;
}
function monthLabel(m) {
  if (!m) return m;
  const d = new Date(`${m}-01`);
  return Number.isNaN(d.getTime()) ? m : d.toLocaleString(undefined, { month: 'short', year: '2-digit' });
}
function userName(u) {
  if (!u) return '-';
  const n = `${u.firstname || ''} ${u.lastname || ''}`.trim();
  return n || u.username || '-';
}
function initials(name) {
  if (!name || name === '-') return '?';
  const parts = name.trim().split(/\s+/);
  return ((parts[0] ? parts[0][0] : '') + (parts[1] ? parts[1][0] : '')).toUpperCase();
}

async function fetchReports() {
  isLoading.value = true;
  try {
    const data = await http.get('meeting/reports', { period: period.value });
    summary.value = data.summary || {};
    byStatus.value = data.by_status || {};
    byType.value = data.by_type || {};
    attendance.value = data.attendance || {};
    attendanceRate.value = data.attendance_rate || 0;
    monthlyTrend.value = data.monthly_trend || [];
    topOrganizers.value = data.top_organizers || [];
    chartKey.value++;
  } catch (e) { /* report stays empty */ }
  isLoading.value = false;
}

onMounted(fetchReports);
</script>

<style scoped>
.split-bar {
  display: flex;
  height: 12px;
  border-radius: 8px;
  overflow: hidden;
  background: #eef1f6;
}
.split-bar > div {
  transition: width 0.5s ease;
}
</style>
