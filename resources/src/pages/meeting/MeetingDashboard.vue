<template>
  <div class="page">
    <PageHeader :title="$t('Dashboard')" :breadcrumb="[$t('Meeting_Management'), $t('Dashboard')]">
      <template #extra>
        <a-button type="primary" @click="$router.push('/meetings')">
          <template #icon><PlusOutlined /></template>
          {{ $t('New_Meeting') }}
        </a-button>
      </template>
    </PageHeader>

    <div v-if="isLoading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <template v-else>
      <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
        <a-col v-for="card in statCards" :key="card.label" :xs="12" :md="8" :xl="4">
          <a-card size="small">
            <a-statistic :title="$t(card.label)" :value="card.value" :value-style="{ color: card.color }" />
          </a-card>
        </a-col>
      </a-row>

      <a-row :gutter="[16, 16]">
        <!-- Status donut -->
        <a-col :xs="24" :lg="8">
          <a-card size="small" :title="$t('Meetings_By_Status')" style="height: 100%">
            <apexchart
              v-if="statusTotal > 0"
              type="donut" height="300"
              :options="donutOptions" :series="donutSeries"
            />
            <a-empty v-else :description="$t('No_Data')" style="padding: 48px 0" />
          </a-card>
        </a-col>

        <!-- Type split -->
        <a-col :xs="24" :lg="8">
          <a-card size="small" :title="$t('Meetings_By_Type')" style="height: 100%">
            <a-row :gutter="12" style="margin-bottom: 16px">
              <a-col :span="12">
                <a-card size="small" style="text-align: center; background: #eef1ff">
                  <EnvironmentOutlined style="font-size: 20px; color: #4361ee" />
                  <div style="font-size: 24px; font-weight: 700">{{ byType.physical || 0 }}</div>
                  <div style="font-size: 12px; color: #64748b">{{ $t('Physical') }}</div>
                </a-card>
              </a-col>
              <a-col :span="12">
                <a-card size="small" style="text-align: center; background: #e7fbff">
                  <VideoCameraOutlined style="font-size: 20px; color: #06b6d4" />
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
            <a-divider style="margin: 14px 0" />
            <a-space size="large">
              <span><CheckCircleOutlined style="color: #22c55e" /> {{ $t('Completed') }} <strong>{{ stats.completed_meetings || 0 }}</strong></span>
              <span><CloseCircleOutlined style="color: #ef4444" /> {{ $t('Cancelled') }} <strong>{{ stats.cancelled_meetings || 0 }}</strong></span>
            </a-space>
          </a-card>
        </a-col>

        <!-- Upcoming meetings -->
        <a-col :xs="24" :lg="8">
          <a-card size="small" :title="$t('Upcoming_Meetings')" style="height: 100%">
            <template #extra>
              <a @click="$router.push('/meeting/calendar')">{{ $t('Calendar') }}</a>
            </template>
            <a-list :data-source="upcomingMeetings" size="small" :locale="{ emptyText: $t('No_Meetings_Scheduled') }">
              <template #renderItem="{ item }">
                <a-list-item style="cursor: pointer" @click="$router.push(`/meeting/details/${item.id}`)">
                  <a-list-item-meta>
                    <template #avatar>
                      <div class="daybox">
                        <span class="daybox-day">{{ dayOf(item.meeting_date) }}</span>
                        <span class="daybox-mon">{{ monOf(item.meeting_date) }}</span>
                      </div>
                    </template>
                    <template #title>{{ item.title }}</template>
                    <template #description>
                      {{ shortTime(item.start_time) }} · <span style="text-transform: capitalize">{{ formatLabel(item.type) }}</span> · {{ item.participants_count }}
                    </template>
                  </a-list-item-meta>
                  <a-tag :color="statusTagColor(item.status)" style="text-transform: capitalize">{{ formatLabel(item.status) }}</a-tag>
                </a-list-item>
              </template>
            </a-list>
          </a-card>
        </a-col>
      </a-row>
    </template>
  </div>
</template>

<script setup>
/**
 * Meeting dashboard — GET meeting/dashboard → {stats, upcoming_meetings,
 * by_status, by_type}. Status donut via apexcharts (legacy hand-rolled SVG);
 * physical/online split bar kept.
 */
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  PlusOutlined, EnvironmentOutlined, VideoCameraOutlined,
  CheckCircleOutlined, CloseCircleOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const { t } = useI18n();

const isLoading = ref(true);
const stats = ref({});
const upcomingMeetings = ref([]);
const byStatus = ref({});
const byType = ref({});
const STATUS_ORDER = ['scheduled', 'ongoing', 'completed', 'cancelled'];
const STATUS_COLORS = ['#4361ee', '#f59e0b', '#22c55e', '#ef4444'];

const statCards = computed(() => [
  { label: 'Total_Meetings', value: stats.value.total_meetings || 0, color: '#4361ee' },
  { label: 'Upcoming_Meetings', value: stats.value.upcoming_meetings || 0, color: '#06b6d4' },
  { label: 'Today_Meetings', value: stats.value.today_meetings || 0, color: '#8b5cf6' },
  { label: 'Ongoing_Meetings', value: stats.value.ongoing_meetings || 0, color: '#f59e0b' },
  { label: 'Completed_Meetings', value: stats.value.completed_meetings || 0, color: '#22c55e' },
  { label: 'Cancelled_Meetings', value: stats.value.cancelled_meetings || 0, color: '#334155' },
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

function formatLabel(v) { return v ? String(v).replace(/_/g, ' ') : '-'; }
function shortTime(x) { return x ? String(x).substring(0, 5) : ''; }
function dayOf(d) { return d ? String(d).substring(8, 10) : '--'; }
function monOf(d) {
  if (!d) return '';
  const dt = new Date(String(d).substring(0, 10));
  return Number.isNaN(dt.getTime()) ? '' : dt.toLocaleString(undefined, { month: 'short' });
}
function statusTagColor(s) {
  return { scheduled: 'processing', ongoing: 'warning', completed: 'success', cancelled: 'error' }[s] || 'default';
}

onMounted(async () => {
  try {
    const data = await http.get('meeting/dashboard');
    stats.value = data.stats || {};
    upcomingMeetings.value = data.upcoming_meetings || [];
    byStatus.value = data.by_status || {};
    byType.value = data.by_type || {};
  } catch (e) { /* dashboard stays empty */ }
  isLoading.value = false;
});
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
.daybox {
  width: 44px;
  border-radius: 10px;
  background: #eef1ff;
  color: #4361ee;
  text-align: center;
  padding: 4px 0;
}
.daybox-day {
  display: block;
  font-size: 16px;
  font-weight: 700;
  line-height: 1.1;
}
.daybox-mon {
  display: block;
  font-size: 10px;
  text-transform: uppercase;
}
</style>
